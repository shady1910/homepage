<?php

declare(strict_types=1);

namespace CasaSol;

final class AdminAuth
{
    public static function start(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('casa_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if (!@session_start()) {
            throw new \RuntimeException('Cannot start admin session.');
        }
        if (isset($_SESSION['admin']) && (time() - ($_SESSION['last_seen'] ?? 0) > 1800 || time() - ($_SESSION['created'] ?? 0) > 28800)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrf(mixed $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function authenticated(string $hash): bool
    {
        if ($hash === '' || !isset($_SESSION['admin']) || !hash_equals(hash('sha256', $hash), $_SESSION['admin'])) {
            return false;
        }
        $_SESSION['last_seen'] = time();
        return true;
    }

    /** Across sessions, limit password attempts to five per 15 minutes per IP. */
    public static function allowAttempt(): bool
    {
        $dir = sys_get_temp_dir() . '/casa-admin-rate-limit';
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create admin rate directory.');
        }
        $file = $dir . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $handle = @fopen($file, 'c+');
        if ($handle === false) { throw new \RuntimeException('Cannot open admin rate file.'); }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Cannot lock admin rate file.'); }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? ['start' => time(), 'count' => 0] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !isset($state['start'], $state['count'])) { throw new \RuntimeException('Invalid admin rate data.'); }
            if (time() - $state['start'] >= 900) { $state = ['start' => time(), 'count' => 0]; }
            if ($state['count'] >= 5) { return false; }
            $state['count']++;
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('Cannot save admin rate data.');
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function login(string $hash): void
    {
        if (!session_regenerate_id(true)) { throw new \RuntimeException('Cannot renew admin session.'); }
        $_SESSION = ['admin' => hash('sha256', $hash), 'created' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
    }
}
