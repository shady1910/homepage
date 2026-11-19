<?php

declare(strict_types=1);

namespace CasaSol\Weather;

const LATITUDE = '37.1032';
const LONGITUDE = '-8.4681';
const MET_AGENT = 'CasaSolFerias/1.0 (https://casasolferias.com; info@casasolferias.com)';

function readJson(string $path): array
{
    $json = @file_get_contents($path);
    $decoded = $json === false ? null : json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function writeCache(string $path, array $entry): void
{
    $temporary = tempnam(dirname($path), 'weather-');
    if ($temporary === false) {
        throw new \RuntimeException('Weather cache temporary file is unavailable.');
    }
    try {
        if (file_put_contents($temporary, json_encode($entry, JSON_THROW_ON_ERROR)) === false
            || !rename($temporary, $path)) {
            throw new \RuntimeException('Weather cache could not be written.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function fetchMet(string $path, array $parameters, ?string $modified): array
{
    $responseHeaders = [];
    $url = 'https://api.met.no/weatherapi/' . $path . '?' . http_build_query(
        ['lat' => LATITUDE, 'lon' => LONGITUDE] + $parameters, '', '&', PHP_QUERY_RFC3986
    );
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($modified !== null) {
        $headers[] = 'If-Modified-Since: ' . $modified;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => MET_AGENT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) {
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    return [$status, is_string($body) ? json_decode($body, true) : null, $responseHeaders];
}

function cachedMet(
    string $storage,
    string $name,
    string $path,
    array $parameters,
    int $interval,
    int $maxAge,
    string $expectedField
): array {
    if (getenv('CASA_WEATHER_MET_ENABLED') !== '1' || !function_exists('curl_init')) {
        return [];
    }
    $file = $storage . '/weather-' . $name . '.json';
    $key = http_build_query($parameters);
    $read = static function () use ($file, $key): array {
        $entry = readJson($file);
        return ($entry['key'] ?? null) === $key ? $entry : [];
    };
    $usable = static fn (array $entry): array => time() - ($entry['checked'] ?? 0) <= $maxAge
        ? ($entry['data'] ?? []) : [];
    $entry = $read();
    if (time() < ($entry['next'] ?? 0)) {
        return $usable($entry);
    }
    $lock = @fopen($file . '.lock', 'c');
    if ($lock === false) {
        return $usable($entry);
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return $usable($read());
        }
        $entry = $read();
        if (time() < ($entry['next'] ?? 0)) {
            return $usable($entry);
        }
        // Verify the cache is writable before requesting data.
        writeCache($file, ['key' => $key, 'next' => time() + 300] + $entry);
        [$status, $body, $headers] = fetchMet($path, $parameters, $entry['modified'] ?? null);
        $now = time();
        $expires = strtotime($headers['expires'] ?? '') ?: 0;
        if ($status === 304 && isset($entry['data'])) {
            $entry['checked'] = $now;
            $entry['next'] = max($now + $interval, $expires);
        } elseif (in_array($status, [200, 203], true) && is_array($body)
            && isset($body['properties'][$expectedField])) {
            if ($status === 203) {
                error_log('Weather API: MET endpoint is deprecated: ' . $name);
            }
            $entry = [
                'data' => $body,
                'checked' => $now,
                'modified' => $headers['last-modified'] ?? null,
                'next' => max($now + $interval, $expires),
            ];
        } else {
            $retry = $headers['retry-after'] ?? '';
            $retryAt = ctype_digit($retry) ? $now + (int) $retry : (strtotime($retry) ?: 0);
            $entry['next'] = max($now + 900, $retryAt, $expires);
            error_log('Weather API: MET ' . $name . ' returned HTTP ' . $status);
        }
        $entry['key'] = $key;
        writeCache($file, $entry);
        return $usable($entry);
    } catch (\Throwable $error) {
        error_log('Weather API: ' . $error->getMessage());
        return $usable($entry);
    } finally {
        fclose($lock);
    }
}

function numericValue(mixed $value): ?float
{
    return (is_float($value) || is_int($value)) && is_finite((float) $value) ? (float) $value : null;
}

function symbol(array $data): ?string
{
    return $data['next_1_hours']['summary']['symbol_code']
        ?? $data['next_6_hours']['summary']['symbol_code']
        ?? $data['next_12_hours']['summary']['symbol_code']
        ?? null;
}

function weatherSummary(array $data, \DateTimeImmutable $now): array
{
    $days = [];
    for ($offset = 1; $offset <= 3; $offset++) {
        $days[$now->modify('+' . $offset . ' day')->format('Y-m-d')] = [];
    }
    $nearest = null;
    $nearestDistance = 5401;
    foreach ($data['properties']['timeseries'] ?? [] as $sample) {
        $instant = strtotime($sample['time'] ?? '');
        if ($instant === false) {
            continue;
        }
        $values = $sample['data']['instant']['details'] ?? [];
        $temperature = numericValue($values['air_temperature'] ?? null);
        $distance = abs($instant - $now->getTimestamp());
        if ($distance < $nearestDistance && $temperature !== null) {
            $wind = numericValue($values['wind_speed'] ?? null);
            $nearestDistance = $distance;
            $nearest = [
                'time' => gmdate(DATE_ATOM, $instant),
                'temperature' => $temperature,
                'wind' => $wind === null ? null : $wind * 3.6,
                'humidity' => numericValue($values['relative_humidity'] ?? null),
                'symbol' => symbol($sample['data']),
            ];
        }
        $local = (new \DateTimeImmutable('@' . $instant))->setTimezone($now->getTimezone());
        $date = $local->format('Y-m-d');
        if (isset($days[$date]) && $temperature !== null) {
            $days[$date][] = [
                'temperature' => $temperature,
                'noonDistance' => abs((int) $local->format('G') * 60 + (int) $local->format('i') - 720),
                'symbol' => symbol($sample['data']),
            ];
        }
    }
    $forecast = [];
    foreach ($days as $date => $samples) {
        if (!$samples) {
            continue;
        }
        $daySymbol = null;
        $noonDistance = PHP_INT_MAX;
        foreach ($samples as $sample) {
            if ($sample['symbol'] !== null && $sample['noonDistance'] < $noonDistance) {
                $daySymbol = $sample['symbol'];
                $noonDistance = $sample['noonDistance'];
            }
        }
        $temperatures = array_column($samples, 'temperature');
        $forecast[] = ['date' => $date, 'min' => min($temperatures), 'max' => max($temperatures), 'symbol' => $daySymbol];
    }
    return ['current' => $nearest, 'forecast' => $forecast];
}

function seaTemperature(string $storage, int $now): ?array
{
    $sample = readJson($storage . '/sea-temperature.json');
    $value = numericValue($sample['temperature'] ?? null);
    $time = is_string($sample['time'] ?? null) ? strtotime($sample['time']) : false;
    if ($value === null || $value < 0 || $value > 40 || $time === false
        || $time > $now || $now - $time > 4 * 86400) {
        return null;
    }
    return ['time' => gmdate(DATE_ATOM, $time), 'temperature' => $value];
}
