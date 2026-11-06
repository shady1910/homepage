<?php

declare(strict_types=1);

// CLI only: never a publicly accessible password generator.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!stream_isatty(STDIN)) {
    fwrite(STDERR, "Please run interactively in Docker/SSH with a terminal.\n");
    exit(1);
}
$mode = shell_exec('stty -g');
if (!is_string($mode) || trim($mode) === '') {
    fwrite(STDERR, "Cannot hide terminal input. Use a trusted PHP CLI password_hash() workflow instead.\n");
    exit(1);
}
$restore = static function () use ($mode): void { shell_exec('stty ' . escapeshellarg(trim($mode))); };
register_shutdown_function($restore);
shell_exec('stty -echo');
try {
    fwrite(STDERR, 'Neues Adminpasswort (12–72 Bytes, Eingabe verborgen): ');
    $password = rtrim((string)fgets(STDIN), "\r\n");
    fwrite(STDERR, "\nPasswort wiederholen: ");
    $confirmation = rtrim((string)fgets(STDIN), "\r\n");
    fwrite(STDERR, "\n");
    if (strlen($password) < 12 || strlen($password) > 72 || !hash_equals($password, $confirmation)) {
        fwrite(STDERR, "Passwörter stimmen nicht überein oder die Länge ist ungültig.\n");
        exit(1);
    }
    echo password_hash($password, PASSWORD_DEFAULT) . "\n";
} finally {
    $restore();
}
