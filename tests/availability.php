<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/availability.php';

use CasaSol\Availability;

function check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . "\n";
}
function rejected(callable $action, string $label): void {
    try { $action(); } catch (Throwable) { check(true, $label); return; }
    check(false, $label);
}
$dir = sys_get_temp_dir() . '/casa-availability-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$file = $dir . '/availability.json';
$store = new Availability($file);
try {
    rejected(fn () => $store->read(), 'missing file fails closed');
    $store->initialize();
    check($store->read() === [] && $store->isAvailable('2098-01-01', '2098-01-02'), 'A: empty calendar');
    $existing = Availability::period('2026-09-20', '2026-09-27', true);
    foreach ([
        ['B', '2026-09-10', '2026-09-15', true],
        ['C', '2026-09-18', '2026-09-23', false],
        ['D', '2026-09-27', '2026-10-03', true],
        ['E', '2026-09-15', '2026-09-20', true],
        ['F', '2026-09-19', '2026-09-20', true],
        ['G', '2026-09-26', '2026-09-27', false],
    ] as [$label, $from, $to, $free]) {
        check(!Availability::overlaps(Availability::period($from, $to, true), $existing) === $free, $label . ': interval rule');
    }
    $id = $store->add('2098-10-01', '2098-10-08');
    rejected(fn () => $store->add('2098-10-07', '2098-10-10'), 'I: reject overlap');
    $store->add('2098-10-08', '2098-10-15');
    check(count($store->read()) === 2, 'J: adjacent bookings');
    check(!$store->isAvailable('2098-10-07', '2098-10-08'), 'server rejects occupied night');
    $store->initialize();
    check(count($store->read()) === 2, 'initialization preserves existing data');
    $store->delete($id);
    check(count($store->read()) === 1, 'delete and read');
    rejected(fn () => $store->delete('../../config.php'), 'manipulated ID');
    rejected(fn () => $store->delete($id), 'unknown ID');
    foreach ([['2026-02-30', '2026-03-03'], ['2026-9-10', '2026-09-20'], ['2098-01-01', '2098-01-01'], ['2098-01-02', '2098-01-01'], ['2000-01-01', '2000-01-03']] as [$from, $to]) {
        rejected(fn () => $store->add($from, $to), 'invalid/past period ' . $from . ' / ' . $to);
    }
    foreach (['', '{broken', '{}', '[{"id":"bad"}]'] as $bad) {
        file_put_contents($file, $bad);
        rejected(fn () => $store->read(), 'invalid storage fails closed: ' . $bad);
        rejected(fn () => $store->add('2098-01-01', '2098-01-02'), 'invalid storage not overwritten');
        check(file_get_contents($file) === $bad, 'corrupt data preserved');
    }
    file_put_contents($file, '[]');
    chmod($file, 0000);
    clearstatcache();
    rejected(fn () => $store->read(), 'no read permission');
    chmod($file, 0400);
    clearstatcache();
    rejected(fn () => $store->add('2098-01-01', '2098-01-02'), 'no write permission');
    chmod($file, 0600);
    $lock = fopen($file . '.lock', 'c');
    flock($lock, LOCK_EX);
    rejected(fn () => $store->read(), 'lock timeout');
    flock($lock, LOCK_UN);
    fclose($lock);
    // Two independent processes attempt the same interval concurrently.
    $code = 'require ' . var_export(__DIR__ . '/../includes/availability.php', true) . '; try { (new \\CasaSol\\Availability(' . var_export($file, true) . '))->add("2098-11-01", "2098-11-08"); exit(0); } catch (\\CasaSol\\OccupiedPeriod) { exit(2); }';
    $processes = [];
    for ($i = 0; $i < 2; $i++) { $processes[] = proc_open([PHP_BINARY, '-r', $code], [], $pipes); }
    $codes = array_map('proc_close', $processes);
    sort($codes);
    check($codes === [0, 2] && count($store->read()) === 1, 'concurrent overlap: one writer wins, no data loss');
} finally {
    foreach (glob($dir . '/*') as $path) { unlink($path); }
    rmdir($dir);
}
