<?php

declare(strict_types=1);

namespace CasaSol;

final class InvalidPeriod extends \InvalidArgumentException {}
final class OccupiedPeriod extends \RuntimeException {}

/** All dates describe nights in Carvoeiro: arrival inclusive, departure exclusive. */
final class Availability
{
    public function __construct(private readonly string $file = __DIR__ . '/../../storage/availability.json') {}

    public static function today(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
    }

    public static function date(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || $value < '0001-01-01') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Lisbon'));
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function period(string $from, string $to, bool $allowPast = false): array
    {
        if (self::date($from) === null || self::date($to) === null) {
            throw new InvalidPeriod('Bitte gib einen gültigen Reisezeitraum an.');
        }
        if ($to <= $from) {
            throw new InvalidPeriod('Die Abreise muss nach der Anreise liegen.');
        }
        if (!$allowPast && $from < self::today()) {
            throw new InvalidPeriod('Die Anreise darf nicht in der Vergangenheit liegen.');
        }
        return ['from' => $from, 'to' => $to];
    }

    public static function overlaps(array $a, array $b): bool
    {
        return $a['from'] < $b['to'] && $a['to'] > $b['from'];
    }

    public static function normalize(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('Availability must be a JSON list.');
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 3 || !isset($row['id'], $row['from'], $row['to'])
                || !is_string($row['id']) || !preg_match('/^booking_[a-f0-9]{32}$/D', $row['id'])
                || isset($ids[$row['id']]) || !is_string($row['from']) || !is_string($row['to'])) {
                throw new \RuntimeException('Invalid availability record.');
            }
            self::period($row['from'], $row['to'], true);
            $ids[$row['id']] = true;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
        for ($i = 1; $i < count($rows); $i++) {
            if (self::overlaps($rows[$i - 1], $rows[$i])) {
                throw new \RuntimeException('Overlapping availability records.');
            }
        }
        return $rows;
    }

    /** A separate, stable lock file survives atomic replacement of the JSON inode. */
    private function locked(int $mode, callable $operation): mixed
    {
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open availability lock.');
        }
        try {
            $deadline = microtime(true) + 2;
            while (!flock($lock, $mode | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Availability lock timed out.');
                }
                usleep(20000);
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readUnlocked(): array
    {
        $json = @file_get_contents($this->file);
        if ($json === false || trim($json) === '') {
            throw new \RuntimeException('Availability file missing, unreadable or empty.');
        }
        // Decode objects as objects so {} cannot silently become an empty list.
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Availability must be a JSON list.');
        }
        return self::normalize(array_map(static fn ($row) => is_object($row) ? (array)$row : $row, $decoded));
    }

    public function read(): array
    {
        return $this->locked(LOCK_SH, fn (): array => $this->readUnlocked());
    }

    public function isAvailable(string $from, string $to): bool
    {
        $period = self::period($from, $to);
        foreach ($this->read() as $row) {
            if (self::overlaps($period, $row)) {
                return false;
            }
        }
        return true;
    }

    private function writeUnlocked(array $rows): void
    {
        $json = json_encode(self::normalize($rows), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (!is_writable(dirname($this->file)) || (file_exists($this->file) && !is_writable($this->file))) {
            throw new \RuntimeException('Availability storage is not writable.');
        }
        $temp = $this->file . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temp, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create availability temporary file.');
        }
        try {
            if (!@chmod($temp, 0600) || @fwrite($handle, $json) !== strlen($json) || !@fflush($handle) || !@fsync($handle)) {
                throw new \RuntimeException('Cannot persist availability data.');
            }
            fclose($handle);
            $handle = null;
            if (!@rename($temp, $this->file)) {
                throw new \RuntimeException('Cannot replace availability file.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** Explicit CLI setup only: public requests never initialize missing data. */
    public function initialize(): void
    {
        $this->locked(LOCK_EX, function (): void {
            if (file_exists($this->file)) {
                $this->readUnlocked();
                return;
            }
            $this->writeUnlocked([]);
        });
    }

    public function add(string $from, string $to): string
    {
        $period = self::period($from, $to);
        return $this->locked(LOCK_EX, function () use ($period): string {
            $rows = $this->readUnlocked();
            foreach ($rows as $row) {
                if (self::overlaps($period, $row)) {
                    throw new OccupiedPeriod('Dieser Zeitraum überschneidet sich mit einer bestehenden Belegung.');
                }
            }
            $id = 'booking_' . bin2hex(random_bytes(16));
            $rows[] = ['id' => $id] + $period;
            $this->writeUnlocked($rows);
            return $id;
        });
    }

    public function delete(string $id): void
    {
        if (!preg_match('/^booking_[a-f0-9]{32}$/D', $id)) {
            throw new InvalidPeriod('Ungültige Belegung. Bitte lade die Seite neu.');
        }
        $this->locked(LOCK_EX, function () use ($id): void {
            $rows = $this->readUnlocked();
            $remaining = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] !== $id));
            if (count($remaining) === count($rows)) {
                throw new InvalidPeriod('Diese Belegung wurde bereits gelöscht. Bitte lade die Seite neu.');
            }
            $this->writeUnlocked($remaining);
        });
    }
}
