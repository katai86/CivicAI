<?php
/**
 * M11 – Pattern & correlation between indicators (rule-based).
 */
require_once __DIR__ . '/../../db.php';

final class PatternCorrelationEngine
{
    /** @return array<string,mixed> */
    public function analyze(int $authorityId, int $lookbackDays = 30): array
    {
        $patterns = [];
        try {
            $st = db()->prepare("
                SELECT indicator_type, AVG(value_num) AS avg_val, STDDEV(value_num) AS std_val, COUNT(*) AS cnt
                FROM city_observations
                WHERE authority_id = ? AND observed_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND value_num IS NOT NULL
                GROUP BY indicator_type
                HAVING cnt >= 3
            ");
            $st->execute([$authorityId, $lookbackDays]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byType = [];
            foreach ($rows as $r) {
                $byType[(string)$r['indicator_type']] = $r;
            }
            $pairs = [
                ['trees.visual_health_score', 'green.ndvi'],
                ['citizen.green_signal', 'vision.vegetation_stress_signals'],
                ['climate.drought_index', 'trees.needing_water'],
            ];
            foreach ($pairs as [$a, $b]) {
                if (!isset($byType[$a], $byType[$b])) {
                    continue;
                }
                $va = (float)$byType[$a]['avg_val'];
                $vb = (float)$byType[$b]['avg_val'];
                if ($va > 0.3 && $vb > 0.3) {
                    $patterns[] = [
                        'pattern_key' => $a . '_x_' . $b,
                        'indicators' => [$a, $b],
                        'strength' => min(1.0, round(($va + $vb) / 2, 3)),
                        'interpretation' => 'Co-occurring signals in ' . $lookbackDays . 'd window',
                    ];
                }
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('PatternCorrelationEngine: ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'patterns' => $patterns, 'count' => count($patterns)];
    }
}
