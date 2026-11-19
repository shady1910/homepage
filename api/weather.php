<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/weather.php';

use function CasaSol\Weather\{cachedMet, weatherSummary};

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo '{"error":"Methode nicht erlaubt."}';
    exit;
}

$storage = getenv('CASA_WEATHER_STORAGE_DIR') ?: __DIR__ . '/../../storage';
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
$today = $now->format('Y-m-d');
$forecastData = cachedMet(
    $storage, 'forecast', 'locationforecast/2.0/compact', [], 1800, 6 * 3600, 'timeseries'
);
$sunData = cachedMet(
    $storage, 'sun', 'sunrise/3.0/sun', ['date' => $today, 'offset' => $now->format('P')],
    86400, 36 * 3600, 'sunrise'
);
$sun = $sunData['properties'] ?? [];
header('Cache-Control: public, max-age=180');
echo json_encode(weatherSummary($forecastData, $now) + [
    'sun' => $sun ? [
        'date' => $today,
        'rise' => $sun['sunrise']['time'] ?? null,
        'set' => $sun['sunset']['time'] ?? null,
    ] : null,
], JSON_THROW_ON_ERROR);
