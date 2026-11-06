<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/admin-auth.php';

use CasaSol\AdminAuth;
use CasaSol\Availability;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; font-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function postText(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
function redirect(): never { header('Location: ./', true, 303); exit; }

$error = '';
$notice = '';
$authenticated = false;
$configured = false;
$sessionReady = false;
$bookings = null;
$from = postText('from');
$to = postText('to');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        http_response_code(405);
        exit('Methode nicht erlaubt.');
    }
    AdminAuth::start();
    $sessionReady = true;
    $configFile = __DIR__ . '/../../private/config.php';
    if (!is_file($configFile) || !is_readable($configFile)) {
        throw new \RuntimeException('Admin configuration missing or unreadable.');
    }
    $config = require $configFile;
    $hash = $config['admin_password_hash'] ?? '';
    $configured = is_string($hash) && $hash !== '' && password_get_info($hash)['algo'] !== null;
    $authenticated = $configured && AdminAuth::authenticated($hash);
    $notice = $_SESSION['notice'] ?? '';
    unset($_SESSION['notice']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AdminAuth::csrf($_POST['csrf'] ?? null)) {
            http_response_code(403);
            $error = 'Die Sitzung oder das Sicherheits-Token ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        } elseif (postText('action') === 'login' && !$authenticated) {
            if (!$configured) {
                http_response_code(503);
                $error = 'Der Adminzugang ist noch nicht eingerichtet.';
            } elseif (!AdminAuth::allowAttempt()) {
                http_response_code(429);
                $error = 'Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten.';
            } elseif (!is_string($_POST['password'] ?? null) || strlen($_POST['password']) > 1024 || !password_verify($_POST['password'], $hash)) {
                http_response_code(401);
                $error = 'Das Passwort ist nicht korrekt.';
            } else {
                AdminAuth::login($hash);
                redirect();
            }
        } elseif (!$authenticated) {
            http_response_code(401);
            $error = 'Bitte melden Sie sich erneut an.';
        } else {
            $store = new Availability();
            switch (postText('action')) {
                case 'logout':
                    AdminAuth::logout();
                    redirect();
                case 'add':
                    $store->add($from, $to);
                    $_SESSION['notice'] = 'Die Belegung wurde gespeichert.';
                    redirect();
                case 'delete':
                    $store->delete(postText('id'));
                    $_SESSION['notice'] = 'Die Belegung wurde gelöscht.';
                    redirect();
                default:
                    http_response_code(400);
                    $error = 'Ungültige Aktion.';
            }
        }
    }
} catch (\CasaSol\InvalidPeriod | \CasaSol\OccupiedPeriod $e) {
    http_response_code($e instanceof \CasaSol\OccupiedPeriod ? 409 : 422);
    $error = $e->getMessage();
} catch (\Throwable $e) {
    error_log('Availability admin: ' . $e->getMessage());
    http_response_code(503);
    $error = 'Die Verwaltung ist derzeit nicht verfügbar. Bitte versuchen Sie es später erneut.';
}

if ($authenticated) {
    try { $bookings = (new Availability())->read(); }
    catch (\Throwable $e) {
        error_log('Availability admin read: ' . $e->getMessage());
        http_response_code(503);
        $error = 'Die Belegungen können derzeit nicht geladen werden. Änderungen sind vorübergehend gesperrt.';
    }
}
$csrf = $sessionReady ? escape($_SESSION['csrf'] ?? '') : '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Belegungen verwalten – Casa Sol Férias</title>
    <link rel="icon" href="../img/favicon.ico" />
    <link rel="stylesheet" href="../css/custom.css" />
    <link rel="stylesheet" href="../css/admin.css" />
</head>
<body class="admin-page">
<main class="admin-container">
    <a href="../index.html"><img src="../img/logo_sticky.png" width="135" height="45" alt="Casa Sol Férias – Startseite" /></a>
    <h1>Belegungen verwalten</h1>
    <?php if ($error !== ''): ?><p class="admin-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="admin-notice" role="status"><?= escape($notice) ?></p><?php endif; ?>
    <?php if (!$authenticated): ?>
        <section class="admin-card">
            <h2>Anmelden</h2>
            <?php if (!$configured): ?>
                <p>Der Adminzugang ist noch nicht eingerichtet. Bitte hinterlegen Sie den Passwort-Hash in der privaten Konfiguration.</p>
            <?php elseif ($sessionReady): ?>
                <form method="post">
                    <input type="hidden" name="action" value="login" />
                    <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required maxlength="1024" />
                    <button type="submit" class="btn">Anmelden</button>
                </form>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <form method="post" class="admin-logout">
            <input type="hidden" name="action" value="logout" />
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <button type="submit" class="btn outline">Abmelden</button>
        </form>
        <p>Tragen Sie hier ausschließlich bestätigte Belegungen ein. Eine Kontaktanfrage reserviert keinen Zeitraum.</p>
        <?php if ($bookings !== null): ?>
        <section class="admin-card">
            <h2>Neue Belegung</h2>
            <p id="periodHelp">Die Anreise zählt als belegte Nacht, die Abreise nicht. Direkt anschließende Aufenthalte sind möglich.</p>
            <form method="post" aria-describedby="periodHelp">
                <input type="hidden" name="action" value="add" />
                <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                <div class="admin-dates">
                    <div><label for="from">Anreise</label><input type="date" id="from" name="from" required min="<?= Availability::today() ?>" value="<?= escape($from) ?>" /></div>
                    <div><label for="to">Abreise</label><input type="date" id="to" name="to" required min="<?= Availability::today() ?>" value="<?= escape($to) ?>" /></div>
                </div>
                <button type="submit" class="btn">Belegung speichern</button>
            </form>
        </section>
        <section class="admin-card">
            <h2>Gespeicherte Belegungen</h2>
            <?php if ($bookings === []): ?><p>Es sind noch keine Belegungen eingetragen.</p><?php endif; ?>
            <ul class="booking-list">
                <?php foreach ($bookings as $booking): $label = Availability::date($booking['from'])->format('d.m.Y') . ' – ' . Availability::date($booking['to'])->format('d.m.Y'); ?>
                <li>
                    <div><strong><?= escape($label) ?></strong><br /><small>Anreise – Abreise<?= $booking['to'] <= Availability::today() ? ' · vergangen' : '' ?></small></div>
                    <form method="post">
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                        <input type="hidden" name="id" value="<?= escape($booking['id']) ?>" />
                        <button type="submit" class="btn outline" aria-label="<?= escape('Belegung ' . $label . ' löschen') ?>">Löschen</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    <?php endif; ?>
    <p><a href="../contact.html">Zum öffentlichen Kalender</a></p>
</main>
</body>
</html>
