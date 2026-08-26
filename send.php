<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, bool $success, string $message): never
{
    http_response_code($status);
    echo json_encode(
        ['success' => $success, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Methode nicht erlaubt.');
}

/*
 * Optional, aber sinnvoll:
 * Verhindert, dass fremde Websites deinen Endpoint direkt aus dem Browser nutzen.
 * Trage in config.php deine echte Domain ein.
 */
$configFile = __DIR__ . '/../private/config.php';

if (!is_file($configFile)) {
    respond(500, false, 'Serverkonfiguration fehlt.');
}

$config = require $configFile;

$allowedHost = (string)($config['allowed_host'] ?? '');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

if ($allowedHost !== '') {
    $allowed = false;

    foreach ([$origin, $referer] as $source) {
        if ($source === '') {
            continue;
        }

        $host = parse_url($source, PHP_URL_HOST);
        if (is_string($host) && hash_equals($allowedHost, strtolower($host))) {
            $allowed = true;
            break;
        }
    }

    /*
     * Manche Browser/Privacy-Tools senden weder Origin noch Referer.
     * Darum blockieren wir nur, wenn einer der Header vorhanden ist und nicht passt.
     */
    if (($origin !== '' || $referer !== '') && !$allowed) {
        respond(403, false, 'Ungültige Herkunft der Anfrage.');
    }
}

/* Honeypot */
$honeypot = trim((string)($_POST['website'] ?? ''));
if ($honeypot !== '') {
    // Für Bots absichtlich wie ein erfolgreicher Versand antworten.
    respond(200, true, 'Vielen Dank! Deine Nachricht wurde erfolgreich versendet.');
}

/* Einfache serverseitige Rate-Limitierung */
$rateLimitSeconds = (int)($config['rate_limit_seconds'] ?? 30);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateDir = sys_get_temp_dir() . '/contact-form-rate-limit';

if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0700, true);
}

$rateFile = $rateDir . '/' . hash('sha256', $ip) . '.txt';
$now = time();

if (is_file($rateFile)) {
    $lastRequest = (int)trim((string)@file_get_contents($rateFile));

    if ($lastRequest > 0 && ($now - $lastRequest) < $rateLimitSeconds) {
        respond(429, false, 'Bitte warte einen Moment, bevor du das Formular erneut sendest.');
    }
}

/* Eingaben aus contact.html */
$firstName = trim((string)($_POST['name'] ?? ''));
$lastName = trim((string)($_POST['lastname'] ?? ''));
$arrival = trim((string)($_POST['arrival'] ?? ''));
$departure = trim((string)($_POST['departure'] ?? ''));
$numberAdults = trim((string)($_POST['number_adult'] ?? ''));
$numberKids = trim((string)($_POST['number_kids'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$privacyAccepted = array_key_exists('privacy', $_POST);

if ($firstName === '' || mb_strlen($firstName) > 80) {
    respond(422, false, 'Bitte gib einen gültigen Vornamen ein.');
}

if ($lastName === '' || mb_strlen($lastName) > 80) {
    respond(422, false, 'Bitte gib einen gültigen Nachnamen ein.');
}

$parseDate = static function (string $value): ?DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }

    return $date;
};

$arrivalDate = $parseDate($arrival);
$departureDate = $parseDate($departure);

if ($arrivalDate === null || $departureDate === null) {
    respond(422, false, 'Bitte gib einen gültigen Reisezeitraum an.');
}

if ($departureDate <= $arrivalDate) {
    respond(422, false, 'Die Abreise muss nach der Anreise liegen.');
}

$adultCount = filter_var($numberAdults, FILTER_VALIDATE_INT);
$kidsCount = filter_var($numberKids, FILTER_VALIDATE_INT);

if ($adultCount === false || $adultCount < 1 || $adultCount > 99) {
    respond(422, false, 'Bitte gib eine gültige Anzahl Erwachsener ein.');
}

if ($kidsCount === false || $kidsCount < 0 || $kidsCount > 99) {
    respond(422, false, 'Bitte gib eine gültige Anzahl Kinder ein.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    respond(422, false, 'Bitte gib eine gültige E-Mail-Adresse ein.');
}

if ($phone === '' || mb_strlen($phone) > 50) {
    respond(422, false, 'Bitte gib eine gültige Telefonnummer ein.');
}

if ($message === '' || mb_strlen($message) > 5000) {
    respond(422, false, 'Bitte gib eine Nachricht mit maximal 5.000 Zeichen ein.');
}

if (!$privacyAccepted) {
    respond(422, false, 'Bitte bestätige die Datenschutzerklärung.');
}

/*
 * Header-Injection zusätzlich erschweren.
 */
foreach ([$firstName, $lastName, $email, $phone] as $value) {
    if (preg_match('/[\r\n]/', $value)) {
        respond(422, false, 'Ungültige Eingabe.');
    }
}

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    respond(500, false, 'PHPMailer ist nicht installiert.');
}

require $autoload;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = (string)$config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string)$config['smtp_username'];
    $mail->Password = (string)$config['smtp_password'];

    $encryption = strtolower((string)($config['smtp_encryption'] ?? 'tls'));

    if ($encryption === 'ssl' || $encryption === 'smtps') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->Port = (int)$config['smtp_port'];
    $mail->CharSet = 'UTF-8';

    /*
     * WICHTIG:
     * From = dein eigenes, authentifiziertes Postfach.
     * Reply-To = Besucher.
     */
    $mail->setFrom(
        (string)$config['from_email'],
        (string)$config['from_name']
    );

    $mail->addAddress(
        (string)$config['recipient_email'],
        (string)($config['recipient_name'] ?? '')
    );

    $fullName = $firstName . ' ' . $lastName;
    $mail->addReplyTo($email, $fullName);

    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLastName = htmlspecialchars($lastName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safePhone = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeArrival = htmlspecialchars($arrivalDate->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDeparture = htmlspecialchars($departureDate->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = nl2br(
        htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );

    $mail->Subject = 'Buchungsanfrage: ' . $arrivalDate->format('d.m.Y') . ' bis ' . $departureDate->format('d.m.Y');
    $mail->isHTML(true);

    $mail->Body = '
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#222">
            <h2>Neue Anfrage über die Website</h2>
            <p><strong>Vorname:</strong> ' . $safeFirstName . '</p>
            <p><strong>Nachname:</strong> ' . $safeLastName . '</p>
            <p><strong>E-Mail:</strong> ' . $safeEmail . '</p>
            <p><strong>Telefon:</strong> ' . ($safePhone !== '' ? $safePhone : '–') . '</p>
            <p><strong>Anreise:</strong> ' . $safeArrival . '</p>
            <p><strong>Abreise:</strong> ' . $safeDeparture . '</p>
            <p><strong>Erwachsene:</strong> ' . $adultCount . '</p>
            <p><strong>Kinder:</strong> ' . $kidsCount . '</p>
            <hr>
            <p><strong>Nachricht:</strong></p>
            <p>' . $safeMessage . '</p>
        </div>
    ';

    $mail->AltBody =
        "Neue Anfrage über die Website\n\n" .
        "Vorname: {$firstName}\n" .
        "Nachname: {$lastName}\n" .
        "E-Mail: {$email}\n" .
        "Telefon: " . ($phone !== '' ? $phone : '–') . "\n" .
        "Anreise: " . $arrivalDate->format('d.m.Y') . "\n" .
        "Abreise: " . $departureDate->format('d.m.Y') . "\n" .
        "Erwachsene: {$adultCount}\n" .
        "Kinder: {$kidsCount}\n\n" .
        "Nachricht:\n{$message}";

    $mail->send();

    /*
     * Rate-Limit erst nach erfolgreichem Versand setzen.
     */
    @file_put_contents($rateFile, (string)$now, LOCK_EX);

    /*
     * Optionale Bestätigungsmail an den Besucher.
     */
    if (!empty($config['send_confirmation'])) {
        $confirmation = new PHPMailer(true);
        $confirmation->isSMTP();
        $confirmation->Host = (string)$config['smtp_host'];
        $confirmation->SMTPAuth = true;
        $confirmation->Username = (string)$config['smtp_username'];
        $confirmation->Password = (string)$config['smtp_password'];
        $confirmation->SMTPSecure = $mail->SMTPSecure;
        $confirmation->Port = (int)$config['smtp_port'];
        $confirmation->CharSet = 'UTF-8';

        $confirmation->setFrom(
            (string)$config['from_email'],
            (string)$config['from_name']
        );

        $confirmation->addAddress($email, $fullName);
        $confirmation->Subject = (string)($config['confirmation_subject'] ?? 'Vielen Dank für deine Anfrage');
        $confirmation->isHTML(true);

        $confirmation->Body = '
            <div style="font-family:Arial,sans-serif;line-height:1.6;color:#222">
                <p>Hallo ' . $safeFirstName . ',</p>
                <p>vielen Dank für deine Nachricht. Wir haben deine Anfrage erhalten und melden uns schnellstmöglich bei dir.</p>
                <p>Viele Grüße<br>' . htmlspecialchars((string)$config['from_name'], ENT_QUOTES, 'UTF-8') . '</p>
            </div>
        ';

        $confirmation->AltBody =
            "Hallo {$firstName},\n\n" .
            "vielen Dank für deine Nachricht. Wir haben deine Anfrage erhalten und melden uns schnellstmöglich bei dir.\n\n" .
            "Viele Grüße\n" . (string)$config['from_name'];

        $confirmation->send();
    }

    respond(200, true, 'Vielen Dank! Deine Nachricht wurde erfolgreich versendet.');
} catch (Exception $e) {
    /*
     * Keine technischen SMTP-Details an Besucher ausgeben.
     * Optional kannst du $e->getMessage() in eine serverseitige Logdatei schreiben.
     */
    error_log('Kontaktformular-Mailfehler: ' . $e->getMessage());

    respond(
        500,
        false,
        'Die Nachricht konnte derzeit nicht versendet werden. Bitte versuche es später erneut.'
    );
}
