<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/availability.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

try {
    $rows = (new \CasaSol\Availability())->read();
    header('X-Availability-Today: ' . \CasaSol\Availability::today());
    echo json_encode(['success' => true, 'bookings' => array_map(
        static fn (array $row): array => ['from' => $row['from'], 'to' => $row['to']],
        $rows
    )], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    error_log('Availability API: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Die Verfügbarkeit kann derzeit nicht geladen werden. Bitte versuchen Sie es später erneut.'], JSON_UNESCAPED_UNICODE);
}
