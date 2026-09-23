<?php
/**
 * Central registry of city intelligence data sources (status, sync, quality).
 */
require_once __DIR__ . '/CityIntelSchema.php';
require_once __DIR__ . '/../../db.php';

final class CityDataSourceRegistry
{
    /** @return list<array<string,mixed>> */
    public function listAll(bool $activeOnly = false): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        $sql = 'SELECT * FROM city_data_sources';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        try {
            return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function get(string $sourceKey): ?array
    {
        if (!CityIntelSchema::ensure()) {
            return null;
        }
        try {
            $st = db()->prepare('SELECT * FROM city_data_sources WHERE source_key = ? LIMIT 1');
            $st->execute([$sourceKey]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function markAttempt(string $sourceKey): void
    {
        if (!CityIntelSchema::ensure()) {
            return;
        }
        try {
            db()->prepare('UPDATE city_data_sources SET last_attempt_at = NOW(), status = ? WHERE source_key = ?')
                ->execute(['running', $sourceKey]);
        } catch (Throwable $e) {
        }
    }

    public function markSuccess(string $sourceKey, int $records, ?float $quality = null): void
    {
        if (!CityIntelSchema::ensure()) {
            return;
        }
        try {
            $row = $this->get($sourceKey);
            $mins = max(30, (int)($row['refresh_minutes'] ?? 360));
            $st = db()->prepare('
                UPDATE city_data_sources
                SET last_success_at = NOW(), last_attempt_at = NOW(), next_sync_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    status = ?, records_processed = ?, last_error = NULL, data_quality = COALESCE(?, data_quality)
                WHERE source_key = ?
            ');
            $st->execute([$mins, 'ok', max(0, $records), $quality, $sourceKey]);
        } catch (Throwable $e) {
        }
    }

    public function markFailure(string $sourceKey, string $error): void
    {
        if (!CityIntelSchema::ensure()) {
            return;
        }
        try {
            $row = $this->get($sourceKey);
            $mins = max(30, (int)($row['refresh_minutes'] ?? 360));
            db()->prepare('
                UPDATE city_data_sources
                SET last_attempt_at = NOW(), next_sync_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    status = ?, last_error = ?
                WHERE source_key = ?
            ')->execute([$mins, 'error', mb_substr($error, 0, 512), $sourceKey]);
        } catch (Throwable $e) {
        }
    }

    /** @return array{total:int,ok:int,error:int,stale:int,running:int} */
    public function freshnessSummary(): array
    {
        $out = ['total' => 0, 'ok' => 0, 'error' => 0, 'stale' => 0, 'running' => 0];
        foreach ($this->listAll(true) as $s) {
            $out['total']++;
            $status = (string)($s['status'] ?? 'idle');
            if ($status === 'ok') {
                $out['ok']++;
            } elseif ($status === 'error') {
                $out['error']++;
            } elseif ($status === 'running') {
                $out['running']++;
            }
            $last = $s['last_success_at'] ?? null;
            $mins = max(30, (int)($s['refresh_minutes'] ?? 360));
            if ($last === null || strtotime((string)$last) < time() - ($mins * 60 * 2)) {
                $out['stale']++;
            }
        }
        return $out;
    }
}
