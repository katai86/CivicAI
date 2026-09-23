<?php
/**
 * Indicator / baseline / trend / anomaly engines over city_observations.
 */
require_once __DIR__ . '/CityIntelSchema.php';
require_once __DIR__ . '/../../db.php';

final class CityIndicatorEngine
{
    /**
     * Aggregate recent observations into city_indicator_values (daily city spatial).
     * @return array{written:int,keys:list<string>}
     */
    public function recompute(?int $authorityId, int $lookbackDays = 30): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['written' => 0, 'keys' => []];
        }
        $lookbackDays = max(1, min(365, $lookbackDays));
        $where = 'observed_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$lookbackDays . ' DAY) AND indicator_type IS NOT NULL AND value_num IS NOT NULL';
        $params = [];
        if ($authorityId !== null && $authorityId > 0) {
            $where .= ' AND authority_id = ?';
            $params[] = $authorityId;
        }
        try {
            $st = db()->prepare("
                SELECT authority_id, indicator_type, unit,
                       AVG(value_num) AS v_avg, MIN(value_num) AS v_min, MAX(value_num) AS v_max,
                       COUNT(*) AS n, AVG(quality) AS q, AVG(confidence) AS c,
                       MIN(observed_at) AS p_start, MAX(observed_at) AS p_end,
                       GROUP_CONCAT(DISTINCT source_key ORDER BY source_key SEPARATOR ',') AS sources
                FROM city_observations
                WHERE $where
                GROUP BY authority_id, indicator_type, unit
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['written' => 0, 'keys' => []];
        }

        $written = 0;
        $keys = [];
        $ins = db()->prepare('
            INSERT INTO city_indicator_values
              (authority_id, indicator_key, period_start, period_end, spatial_key, value_num, unit,
               sample_count, quality, confidence, calc_method, evidence_json)
            VALUES
              (:authority_id, :indicator_key, :period_start, :period_end, \'city\', :value_num, :unit,
               :sample_count, :quality, :confidence, :calc_method, :evidence_json)
            ON DUPLICATE KEY UPDATE
              value_num = VALUES(value_num), sample_count = VALUES(sample_count),
              quality = VALUES(quality), confidence = VALUES(confidence),
              evidence_json = VALUES(evidence_json), unit = VALUES(unit)
        ');
        foreach ($rows as $r) {
            $key = (string)$r['indicator_type'];
            $keys[] = $key;
            $evidence = [
                'calc' => 'avg(value_num) over lookback',
                'lookback_days' => $lookbackDays,
                'min' => isset($r['v_min']) ? (float)$r['v_min'] : null,
                'max' => isset($r['v_max']) ? (float)$r['v_max'] : null,
                'sources' => array_values(array_filter(explode(',', (string)($r['sources'] ?? '')))),
                'measured' => true,
            ];
            try {
                $ins->execute([
                    ':authority_id' => $r['authority_id'] !== null ? (int)$r['authority_id'] : null,
                    ':indicator_key' => mb_substr($key, 0, 96),
                    ':period_start' => $r['p_start'],
                    ':period_end' => $r['p_end'],
                    ':value_num' => round((float)$r['v_avg'], 6),
                    ':unit' => $r['unit'],
                    ':sample_count' => (int)$r['n'],
                    ':quality' => $r['q'] !== null ? round((float)$r['q'], 3) : null,
                    ':confidence' => $r['c'] !== null ? round((float)$r['c'], 4) : null,
                    ':calc_method' => 'rolling_mean_' . $lookbackDays . 'd',
                    ':evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
                ]);
                $written++;
            } catch (Throwable $e) {
            }
        }
        return ['written' => $written, 'keys' => array_values(array_unique($keys))];
    }

    /**
     * Persist a point-in-time indicator snapshot (e.g. daily weather).
     */
    public function writeSnapshot(
        ?int $authorityId,
        string $indicatorKey,
        float $value,
        ?string $unit,
        string $calcMethod,
        array $evidence,
        ?float $quality = null,
        ?float $confidence = null,
        int $sampleCount = 1
    ): bool {
        if (!CityIntelSchema::ensure()) {
            return false;
        }
        $end = date('Y-m-d H:i:s');
        $start = date('Y-m-d 00:00:00');
        try {
            $st = db()->prepare('
                INSERT INTO city_indicator_values
                  (authority_id, indicator_key, period_start, period_end, spatial_key, value_num, unit,
                   sample_count, quality, confidence, calc_method, evidence_json)
                VALUES
                  (?, ?, ?, ?, \'city\', ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  value_num = VALUES(value_num), sample_count = VALUES(sample_count),
                  quality = VALUES(quality), confidence = VALUES(confidence),
                  evidence_json = VALUES(evidence_json), calc_method = VALUES(calc_method)
            ');
            $st->execute([
                $authorityId,
                mb_substr($indicatorKey, 0, 96),
                $start,
                $end,
                round($value, 6),
                $unit,
                $sampleCount,
                $quality,
                $confidence,
                mb_substr($calcMethod, 0, 120),
                json_encode($evidence, JSON_UNESCAPED_UNICODE),
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    public function latest(?int $authorityId, int $limit = 50): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        try {
            if ($authorityId !== null && $authorityId > 0) {
                $st = db()->prepare('
                    SELECT v.* FROM city_indicator_values v
                    INNER JOIN (
                      SELECT indicator_key, MAX(period_end) AS me
                      FROM city_indicator_values WHERE authority_id = ?
                      GROUP BY indicator_key
                    ) t ON t.indicator_key = v.indicator_key AND t.me = v.period_end AND v.authority_id = ?
                    ORDER BY v.indicator_key ASC
                    LIMIT ' . $limit);
                $st->execute([$authorityId, $authorityId]);
            } else {
                $st = db()->query('
                    SELECT v.* FROM city_indicator_values v
                    INNER JOIN (
                      SELECT indicator_key, authority_id, MAX(period_end) AS me
                      FROM city_indicator_values GROUP BY indicator_key, authority_id
                    ) t ON t.indicator_key = v.indicator_key AND t.authority_id <=> v.authority_id AND t.me = v.period_end
                    ORDER BY v.indicator_key ASC
                    LIMIT ' . $limit);
            }
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(?int $authorityId, string $indicatorKey, int $limit = 60): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        $limit = max(1, min(365, $limit));
        try {
            $st = db()->prepare('
                SELECT * FROM city_indicator_values
                WHERE indicator_key = ? AND (? IS NULL OR authority_id = ?)
                ORDER BY period_end DESC
                LIMIT ' . $limit);
            $st->execute([$indicatorKey, $authorityId, $authorityId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

final class CityBaselineEngine
{
    public function recompute(?int $authorityId, int $windowDays = 90): int
    {
        if (!CityIntelSchema::ensure()) {
            return 0;
        }
        $windowDays = max(7, min(365, $windowDays));
        $where = 'period_end >= DATE_SUB(NOW(), INTERVAL ' . (int)$windowDays . ' DAY)';
        $params = [];
        if ($authorityId !== null && $authorityId > 0) {
            $where .= ' AND authority_id = ?';
            $params[] = $authorityId;
        }
        try {
            $st = db()->prepare("
                SELECT authority_id, indicator_key, AVG(value_num) AS b, MIN(value_num) AS mn, MAX(value_num) AS mx, COUNT(*) AS n
                FROM city_indicator_values
                WHERE $where
                GROUP BY authority_id, indicator_key
                HAVING n >= 2
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return 0;
        }
        $n = 0;
        $ins = db()->prepare('
            INSERT INTO city_baselines
              (authority_id, indicator_key, spatial_key, window_days, baseline_value, baseline_min, baseline_max, sample_count, computed_at, method)
            VALUES (?, ?, \'city\', ?, ?, ?, ?, ?, NOW(), \'rolling_mean\')
            ON DUPLICATE KEY UPDATE
              baseline_value = VALUES(baseline_value), baseline_min = VALUES(baseline_min),
              baseline_max = VALUES(baseline_max), sample_count = VALUES(sample_count), computed_at = NOW()
        ');
        foreach ($rows as $r) {
            try {
                $ins->execute([
                    $r['authority_id'] !== null ? (int)$r['authority_id'] : null,
                    $r['indicator_key'],
                    $windowDays,
                    round((float)$r['b'], 6),
                    $r['mn'] !== null ? (float)$r['mn'] : null,
                    $r['mx'] !== null ? (float)$r['mx'] : null,
                    (int)$r['n'],
                ]);
                $n++;
            } catch (Throwable $e) {
            }
        }
        return $n;
    }

    public function get(?int $authorityId, string $indicatorKey, int $windowDays = 90): ?array
    {
        if (!CityIntelSchema::ensure()) {
            return null;
        }
        try {
            $st = db()->prepare('
                SELECT * FROM city_baselines
                WHERE indicator_key = ? AND window_days = ? AND (? IS NULL OR authority_id = ?)
                ORDER BY computed_at DESC LIMIT 1
            ');
            $st->execute([$indicatorKey, $windowDays, $authorityId, $authorityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

final class CityTrendEngine
{
    /**
     * @return array{trend:string,slope:?float,points:int,pct_change:?float}
     */
    public function compute(?int $authorityId, string $indicatorKey, int $points = 8): array
    {
        $eng = new CityIndicatorEngine();
        $hist = array_reverse($eng->history($authorityId, $indicatorKey, $points));
        $n = count($hist);
        if ($n < 2) {
            return ['trend' => 'stable', 'slope' => null, 'points' => $n, 'pct_change' => null];
        }
        $vals = array_map(fn($r) => (float)$r['value_num'], $hist);
        $first = $vals[0];
        $last = $vals[$n - 1];
        $pct = abs($first) > 1e-9 ? (($last - $first) / abs($first)) * 100.0 : null;

        // Simple linear slope via least squares on index
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $vals[$i];
            $sumXY += $i * $vals[$i];
            $sumXX += $i * $i;
        }
        $den = ($n * $sumXX - $sumX * $sumX);
        $slope = abs($den) > 1e-12 ? ($n * $sumXY - $sumX * $sumY) / $den : 0.0;
        $mean = $sumY / $n;
        $rel = abs($mean) > 1e-9 ? $slope / abs($mean) : $slope;

        if ($rel > 0.02 || ($pct !== null && $pct > 5)) {
            $trend = 'improving';
        } elseif ($rel < -0.02 || ($pct !== null && $pct < -5)) {
            $trend = 'deteriorating';
        } else {
            $trend = 'stable';
        }
        // For "bad when high" indicators (heat, drought, building density stress) callers may invert.
        return [
            'trend' => $trend,
            'slope' => round($slope, 6),
            'points' => $n,
            'pct_change' => $pct !== null ? round($pct, 2) : null,
        ];
    }
}

final class CityAnomalyEngine
{
    /** Indicators where higher is worse for severity direction. */
    private const HIGHER_IS_WORSE = [
        'climate.heat_risk',
        'climate.drought_index',
        'climate.temp_c',
        'air.no2',
        'air.pm25',
        'osm.building_density_per_km2',
        'citizen.open_reports',
        'green.drought_risk',
    ];

    /**
     * @return array{created:int,anomalies:list<array<string,mixed>>}
     */
    public function detect(?int $authorityId, float $thresholdPct = 20.0): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['created' => 0, 'anomalies' => []];
        }
        $indEng = new CityIndicatorEngine();
        $baseEng = new CityBaselineEngine();
        $latest = $indEng->latest($authorityId, 80);
        $created = 0;
        $out = [];
        $ins = db()->prepare('
            INSERT INTO city_anomalies
              (authority_id, indicator_key, spatial_key, detected_at, anomaly_score, direction,
               current_value, baseline_value, pct_deviation, confidence, severity, evidence_json, status)
            VALUES (?, ?, \'city\', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, \'open\')
        ');

        foreach ($latest as $row) {
            $key = (string)$row['indicator_key'];
            $aid = $row['authority_id'] !== null ? (int)$row['authority_id'] : $authorityId;
            $current = (float)$row['value_num'];
            $base = $baseEng->get($aid, $key, 90);
            if (!$base) {
                continue;
            }
            $b = (float)$base['baseline_value'];
            if (abs($b) < 1e-9) {
                continue;
            }
            $pct = (($current - $b) / abs($b)) * 100.0;
            if (abs($pct) < $thresholdPct) {
                continue;
            }
            $higherWorse = in_array($key, self::HIGHER_IS_WORSE, true) || str_contains($key, 'heat') || str_contains($key, 'drought') || str_contains($key, 'risk');
            $direction = $pct > 0 ? 'up' : 'down';
            $bad = ($higherWorse && $pct > 0) || (!$higherWorse && $pct < 0);
            if (!$bad && abs($pct) < ($thresholdPct * 1.5)) {
                continue;
            }
            $score = round(min(100, abs($pct)), 4);
            $sev = $score >= 40 ? 'high' : ($score >= 25 ? 'medium' : 'low');
            $evidence = [
                'measured' => true,
                'current' => $current,
                'baseline' => $b,
                'baseline_window_days' => (int)$base['window_days'],
                'pct_deviation' => round($pct, 2),
                'indicator_period_end' => $row['period_end'],
                'calc' => '(current - baseline) / |baseline| * 100',
                'sources' => json_decode((string)($row['evidence_json'] ?? '{}'), true)['sources'] ?? [],
            ];
            try {
                $ins->execute([
                    $aid,
                    $key,
                    $score,
                    $direction,
                    $current,
                    $b,
                    round($pct, 4),
                    $row['confidence'] !== null ? (float)$row['confidence'] : 0.7,
                    $sev,
                    json_encode($evidence, JSON_UNESCAPED_UNICODE),
                ]);
                $created++;
                $out[] = [
                    'indicator_key' => $key,
                    'anomaly_score' => $score,
                    'pct_deviation' => round($pct, 2),
                    'severity' => $sev,
                    'evidence' => $evidence,
                ];
            } catch (Throwable $e) {
            }
        }
        return ['created' => $created, 'anomalies' => $out];
    }

    /** @return list<array<string,mixed>> */
    public function listRecent(?int $authorityId, int $limit = 20): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            if ($authorityId !== null && $authorityId > 0) {
                $st = db()->prepare('SELECT * FROM city_anomalies WHERE authority_id = ? ORDER BY detected_at DESC LIMIT ' . $limit);
                $st->execute([$authorityId]);
            } else {
                $st = db()->query('SELECT * FROM city_anomalies ORDER BY detected_at DESC LIMIT ' . $limit);
            }
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
