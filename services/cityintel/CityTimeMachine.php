<?php
/**
 * M12 – City time machine (indicator snapshot history).
 */
require_once __DIR__ . '/../../db.php';

final class CityTimeMachine
{
    /** @return array<string,mixed> */
    public function atDate(int $authorityId, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d');
        try {
            if (!db_table_has_column(db(), 'city_indicator_snapshots', 'snapshot_date')) {
                return ['ok' => false, 'error' => 'schema_missing'];
            }
            $st = db()->prepare('SELECT * FROM city_indicator_snapshots WHERE authority_id = ? AND snapshot_date <= ? ORDER BY snapshot_date DESC LIMIT 1');
            $st->execute([$authorityId, $date]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => true, 'date' => $date, 'snapshot' => null];
            }
            $row['indicators'] = json_decode((string)($row['indicators_json'] ?? '{}'), true);
            unset($row['indicators_json']);
            return ['ok' => true, 'date' => $row['snapshot_date'], 'snapshot' => $row];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return list<array<string,mixed>> */
    public function timeline(int $authorityId, int $days = 90): array
    {
        try {
            if (!db_table_has_column(db(), 'city_indicator_snapshots', 'snapshot_date')) {
                return [];
            }
            $st = db()->prepare('
                SELECT snapshot_date, health_score FROM city_indicator_snapshots
                WHERE authority_id = ? AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ORDER BY snapshot_date ASC
            ');
            $st->execute([$authorityId, $days]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
