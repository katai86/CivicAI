<?php
/**
 * M7 – Change intelligence via indicator snapshots.
 */
require_once __DIR__ . '/../../db.php';

final class CityChangeIntelligence
{
    /** @return array<string,mixed> */
    public function snapshot(int $authorityId): array
    {
        $indicators = [];
        try {
            $st = db()->prepare("
                SELECT indicator_type, AVG(value_num) AS avg_val, COUNT(*) AS cnt
                FROM city_observations
                WHERE authority_id = ? AND observed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND value_num IS NOT NULL
                GROUP BY indicator_type
            ");
            $st->execute([$authorityId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $indicators[$row['indicator_type']] = [
                    'avg' => round((float)$row['avg_val'], 3),
                    'count' => (int)$row['cnt'],
                ];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'indicators' => []];
        }
        $health = $this->estimateHealthScore($indicators);
        $this->persistSnapshot($authorityId, $indicators, $health);
        $trends = $this->compareWithPrevious($authorityId, $indicators);
        return ['ok' => true, 'indicators' => $indicators, 'health_score' => $health, 'trends' => $trends];
    }

    /** @param array<string,array<string,mixed>> $indicators */
    private function estimateHealthScore(array $indicators): float
    {
        $score = 70.0;
        foreach ($indicators as $key => $data) {
            if (str_contains($key, 'stress') || str_contains($key, 'anomaly') || str_contains($key, 'risk')) {
                $score -= min(20.0, (float)($data['avg'] ?? 0) * 0.2);
            }
            if (str_contains($key, 'health') || str_contains($key, 'ndvi')) {
                $score += min(15.0, (float)($data['avg'] ?? 0) * 0.1);
            }
        }
        return max(0.0, min(100.0, round($score, 1)));
    }

    /** @param array<string,array<string,mixed>> $indicators */
    private function persistSnapshot(int $authorityId, array $indicators, float $health): void
    {
        try {
            if (!function_exists('db_table_has_column') || !db_table_has_column(db(), 'city_indicator_snapshots', 'snapshot_date')) {
                return;
            }
            db()->prepare('
                INSERT INTO city_indicator_snapshots (authority_id, snapshot_date, indicators_json, health_score)
                VALUES (?, CURDATE(), ?, ?)
                ON DUPLICATE KEY UPDATE indicators_json = VALUES(indicators_json), health_score = VALUES(health_score)
            ')->execute([$authorityId, json_encode($indicators, JSON_UNESCAPED_UNICODE), $health]);
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('CityChangeIntelligence persist: ' . $e->getMessage());
        }
    }

    /** @param array<string,array<string,mixed>> $current @return array<string,mixed> */
    private function compareWithPrevious(int $authorityId, array $current): array
    {
        $trends = ['improving' => [], 'deteriorating' => []];
        try {
            $st = db()->prepare('SELECT indicators_json FROM city_indicator_snapshots WHERE authority_id = ? AND snapshot_date < CURDATE() ORDER BY snapshot_date DESC LIMIT 1');
            $st->execute([$authorityId]);
            $prev = $st->fetchColumn();
            if (!$prev) return $trends;
            $prevData = json_decode((string)$prev, true);
            if (!is_array($prevData)) return $trends;
            foreach ($current as $key => $data) {
                $prevAvg = (float)($prevData[$key]['avg'] ?? 0);
                $currAvg = (float)($data['avg'] ?? 0);
                $delta = $currAvg - $prevAvg;
                if (abs($delta) < 0.01) continue;
                $isBad = str_contains($key, 'stress') || str_contains($key, 'risk') || str_contains($key, 'anomaly');
                if ($isBad) {
                    if ($delta > 0) $trends['deteriorating'][] = $key;
                    else $trends['improving'][] = $key;
                } else {
                    if ($delta > 0) $trends['improving'][] = $key;
                    else $trends['deteriorating'][] = $key;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $trends;
    }
}
