<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../vendor/autoload.php';
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) { throw new RuntimeException('Composer autoload missing'); }
echo "PASS: existing Composer PHPMailer autoload\n";

function verify(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/casa-flow-' . bin2hex(random_bytes(8));
$source = dirname(__DIR__);
foreach (['html/includes', 'html/admin', 'html/api', 'html/vendor', 'private', 'storage', 'sessions'] as $dir) { mkdir($root . '/' . $dir, 0700, true); }
foreach (['send.php', 'admin/index.php', 'api/availability.php', 'includes/availability.php', 'includes/admin-auth.php'] as $file) { copy($source . '/' . $file, $root . '/html/' . $file); }
$password = bin2hex(random_bytes(24));
file_put_contents($root . '/private/config.php', '<?php return ' . var_export([
    'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT), 'allowed_host' => ['localhost'],
    'smtp_host' => 'invalid.test', 'smtp_username' => 'fixture', 'smtp_password' => 'fixture', 'smtp_port' => 587,
    'from_email' => 'owner@example.test', 'from_name' => 'Casa Sol Férias', 'recipient_email' => 'owner@example.test',
    'send_confirmation' => true, 'rate_limit_seconds' => 30,
], true) . ';');
// Isolated fake: no sockets or SMTP methods can send any mail.
file_put_contents($root . '/html/vendor/autoload.php', <<<'PHP'
<?php
namespace PHPMailer\PHPMailer;
file_put_contents(__DIR__ . '/loaded', 'loaded');
class Exception extends \Exception {}
#[\AllowDynamicProperties]
class PHPMailer {
    const ENCRYPTION_SMTPS = 'ssl';
    const ENCRYPTION_STARTTLS = 'tls';
    public array $calls = [];
    public function __construct(...$args) {}
    public function __call($name, $args) { $this->calls[$name] = $args; }
    public function send() { file_put_contents(__DIR__ . '/sent', json_encode(get_object_vars($this)) . "\n", FILE_APPEND); return true; }
}
PHP);
file_put_contents($root . '/runner.php', <<<'PHP'
<?php
$request = json_decode(file_get_contents($argv[1]), true);
$_SERVER = $request['server'];
$_POST = $request['post'];
$_COOKIE = $request['cookie'];
ini_set('session.save_path', __DIR__ . '/sessions');
ini_set('display_errors', '0');
if (isset($request['seed']) && isset($_COOKIE['casa_admin'])) {
    session_name('casa_admin'); session_id($_COOKIE['casa_admin']); session_start();
    $_SESSION = array_replace($_SESSION, $request['seed']); session_write_close();
}
register_shutdown_function(function () {
    file_put_contents(__DIR__ . '/meta.json', json_encode(['status' => http_response_code() ?: 200, 'session' => session_id(), 'csrf' => $_SESSION['csrf'] ?? null, 'cookie_params' => session_get_cookie_params()]));
});
require __DIR__ . '/html/' . $request['file'];
PHP);
$ip = 'fixture-' . bin2hex(random_bytes(12));
$request = function (string $file, string $method = 'GET', array $post = [], string $session = '', array $seed = [], string $origin = 'http://localhost:8080', bool $https = false) use ($root, $ip): array {
    @unlink($root . '/html/vendor/loaded');
    file_put_contents($root . '/request.json', json_encode([
        'file' => $file, 'server' => ['REQUEST_METHOD' => $method, 'SCRIPT_NAME' => '/' . $file, 'REMOTE_ADDR' => $ip, 'SERVER_PORT' => $https ? '443' : '80', 'HTTPS' => $https ? 'on' : 'off', 'HTTP_ORIGIN' => $origin],
        'post' => $post, 'cookie' => $session !== '' ? ['casa_admin' => $session] : [], 'seed' => $seed,
    ]));
    $process = proc_open([PHP_BINARY, $root . '/runner.php', $root . '/request.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) { throw new RuntimeException('Fixture failed: ' . $errors); }
    $meta = json_decode(file_get_contents($root . '/meta.json'), true);
    return $meta + ['body' => $body, 'mailer_loaded' => file_exists($root . '/html/vendor/loaded')];
};
try {
    $store = new \CasaSol\Availability($root . '/storage/availability.json');
    $store->initialize();
    $id = $store->add('2098-09-20', '2098-09-27');
    $fields = ['name' => 'Test', 'lastname' => 'Guest', 'arrival' => '2098-09-21', 'departure' => '2098-09-24', 'number_adult' => '2', 'number_kids' => '0', 'email' => 'guest@example.test', 'phone' => '+49 123', 'message' => 'Fixture inquiry', 'privacy' => '1', 'website' => ''];
    $r = $request('send.php', 'POST', $fields);
    verify($r['status'] === 409 && json_decode($r['body'], true)['success'] === false && !$r['mailer_loaded'], 'H: manipulated occupied range: 409, no PHPMailer/autoload');
    foreach ([['arrival' => '2000-01-01', 'departure' => '2000-01-03'], ['arrival' => '2098-02-30'], ['arrival' => ['2098-09-20']], ['departure' => '2098-09-20'], ['email' => "guest@example.test\r\nBcc:x"], ['name' => "Test\r\nBcc:x"]] as $change) {
        $r = $request('send.php', 'POST', array_replace($fields, $change));
        verify($r['status'] === 422 && !$r['mailer_loaded'], 'date/input/header validation, no mail');
    }
    $r = $request('send.php'); verify($r['status'] === 405 && !$r['mailer_loaded'], 'POST required');
    $r = $request('send.php', 'POST', $fields, '', [], 'https://foreign.example'); verify($r['status'] === 403 && !$r['mailer_loaded'], 'Origin protection preserved');
    $r = $request('send.php', 'POST', array_replace($fields, ['website' => 'bot'])); verify($r['status'] === 200 && !$r['mailer_loaded'], 'honeypot preserved');
    $r = $request('api/availability.php');
    verify($r['status'] === 200 && json_decode($r['body'], true) === ['success' => true, 'bookings' => [['from' => '2098-09-20', 'to' => '2098-09-27']]], 'public API: periods only, no ID/config');
    $r = $request('api/availability.php', 'POST'); verify($r['status'] === 405, 'API GET only');
    file_put_contents($root . '/storage/availability.json', '{broken');
    $r = $request('api/availability.php'); verify($r['status'] === 503 && !str_contains($r['body'], $root), 'API corruption: 503, no paths');
    $r = $request('send.php', 'POST', $fields); verify($r['status'] === 503 && !$r['mailer_loaded'], 'storage failure: no mail');
    file_put_contents($root . '/storage/availability.json', '[]');
    $r = $request('admin/index.php');
    verify(!str_contains($r['body'], 'Belegung speichern') && str_contains($r['body'], 'Anmelden'), 'K: unauthenticated admin shows login only');
    $sid = $r['session']; $csrf = $r['csrf'];
    $r = $request('admin/index.php', 'POST', ['action' => 'add', 'from' => '2098-01-01', 'to' => '2098-01-02', 'csrf' => $csrf], $sid);
    verify($r['status'] === 401 && $store->read() === [], 'unauthenticated write denied');
    $r = $request('admin/index.php', 'POST', ['action' => 'login', 'password' => 'wrong', 'csrf' => $csrf], $sid);
    verify($r['status'] === 401, 'wrong password');
    $r = $request('admin/index.php', 'POST', ['action' => 'login', 'password' => $password, 'csrf' => $csrf], $sid);
    verify($r['status'] === 303 && $r['session'] !== $sid && $r['csrf'] !== $csrf, 'login rotates session and CSRF');
    $sid = $r['session']; $csrf = $r['csrf'];
    foreach (['add', 'delete', 'logout'] as $action) {
        foreach ([[], ['csrf' => 'bad']] as $token) {
            $r = $request('admin/index.php', 'POST', ['action' => $action] + $token, $sid);
            verify($r['status'] === 403, 'L: missing/invalid CSRF rejected for ' . $action);
        }
    }
    $add = ['action' => 'add', 'csrf' => $csrf, 'from' => '2098-10-01', 'to' => '2098-10-08'];
    $r = $request('admin/index.php', 'POST', $add, $sid); verify($r['status'] === 303 && count($store->read()) === 1, 'admin add');
    $r = $request('admin/index.php', 'POST', $add, $sid); verify($r['status'] === 409 && count($store->read()) === 1, 'I: admin overlap rejected');
    $r = $request('admin/index.php', 'POST', array_replace($add, ['from' => '2098-10-08', 'to' => '2098-10-15']), $sid);
    verify($r['status'] === 303 && count($store->read()) === 2, 'J: admin adjacent dates accepted');
    $r = $request('admin/index.php', 'POST', ['action' => 'delete', 'csrf' => $csrf, 'id' => '../../private/config.php'], $sid);
    verify($r['status'] === 422 && count($store->read()) === 2, 'admin manipulated ID rejected');
    $r = $request('admin/index.php', 'GET', ['action' => 'delete', 'id' => $store->read()[0]['id']], $sid);
    verify(count($store->read()) === 2, 'GET cannot delete');
    $r = $request('admin/index.php', 'POST', ['action' => 'delete', 'csrf' => $csrf, 'id' => $store->read()[0]['id']], $sid);
    verify($r['status'] === 303 && count($store->read()) === 1, 'admin delete by ID');
    $r = $request('admin/index.php', 'POST', $add, $sid, ['last_seen' => time() - 1801]);
    verify($r['status'] === 403 && count($store->read()) === 1 && !str_contains($r['body'], 'Belegung speichern'), 'expired session fails closed');
    $r = $request('admin/index.php'); $sid = $r['session']; $csrf = $r['csrf'];
    $r = $request('admin/index.php', 'POST', ['action' => 'login', 'password' => $password, 'csrf' => $csrf], $sid);
    $sid = $r['session']; $csrf = $r['csrf'];
    $r = $request('admin/index.php', 'POST', ['action' => 'logout', 'csrf' => $csrf], $sid);
    verify($r['status'] === 303, 'logout');
    $r = $request('admin/index.php', 'GET', [], $sid);
    verify(!str_contains($r['body'], 'Belegung speichern'), 'logged out session cannot access admin');
    $sid = $r['session']; $csrf = $r['csrf'];
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $r = $request('admin/index.php', 'POST', ['action' => 'login', 'csrf' => $csrf, 'password' => 'wrong'], $sid);
    }
    verify($r['status'] === 429, 'admin brute-force limit survives new sessions');
    rename($root . '/private/config.php', $root . '/private/config.saved');
    $r = $request('admin/index.php');
    verify($r['status'] === 503 && !str_contains($r['body'], $root), 'missing admin config: generic error without paths');
    rename($root . '/private/config.saved', $root . '/private/config.php');
    $r = $request('admin/index.php', 'GET', [], '', [], 'https://localhost', true);
    verify($r['cookie_params']['secure'] && $r['cookie_params']['httponly'] && $r['cookie_params']['samesite'] === 'Strict', 'Secure/HttpOnly/SameSite cookie when HTTPS is reported');
    $before = $store->read();
    $r = $request('send.php', 'POST', array_replace($fields, ['arrival' => '2098-12-01', 'departure' => '2098-12-08']));
    $mails = array_map(fn ($line) => json_decode($line, true), file($root . '/html/vendor/sent', FILE_IGNORE_NEW_LINES));
    verify($r['status'] === 200 && json_decode($r['body'], true)['success'] && count($mails) === 2, 'free inquiry and confirmation via isolated mailer fake');
    verify(isset($mails[0]['Body'], $mails[0]['AltBody']) && $mails[0]['calls']['addReplyTo'] === ['guest@example.test', 'Test Guest'] && str_contains($mails[0]['AltBody'], 'Kinder: 0'), 'HTML/plaintext, fields and Reply-To preserved');
    verify($store->read() === $before, 'inquiry creates no booking');
    $r = $request('send.php', 'POST', $fields); verify($r['status'] === 429 && !$r['mailer_loaded'], 'existing contact rate limit preserved');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
    @unlink(sys_get_temp_dir() . '/casa-admin-rate-limit/' . hash('sha256', $ip));
    @unlink(sys_get_temp_dir() . '/contact-form-rate-limit/' . hash('sha256', $ip) . '.txt');
}
