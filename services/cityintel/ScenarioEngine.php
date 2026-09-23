<?php
/**
 * M13 – Scenario engine (what-if presets, deterministic).
 */
require_once __DIR__ . '/../../services/CityHealthScore.php';
require_once __DIR__ . '/../../db.php';

final class ScenarioEngine
{
    /** @return list<array<string,mixed>> */
    public function listPresets(int $authorityId): array
    {
        return [
            ['key' => 'drought_stress', 'title' => 'Drought stress +30%', 'params' => ['drought_multiplier' => 1.3]],
            ['key' => 'tree_planting', 'title' => 'Plant 100 trees', 'params' => ['trees_added' => 100]],
            ['key' => 'report_surge', 'title' => 'Report surge +50%', 'params' => ['reports_multiplier' => 1.5]],
        ];
    }

    /** @param array<string,mixed> $params */
    public function run(int $authorityId, string $presetKey, array $params = []): array
    {
        $health = (new CityHealthScore())->compute($authorityId);
        $base = (float)($health['city_health_score'] ?? 50);
        $projected = $base;
        $notes = [];
        if ($presetKey === 'drought_stress' || !empty($params['drought_multiplier'])) {
            $m = (float)($params['drought_multiplier'] ?? 1.3);
            $projected -= min(25, ($m - 1) * 40);
            $notes[] = 'Environment score reduced under drought scenario';
        }
        if ($presetKey === 'tree_planting' || !empty($params['trees_added'])) {
            $n = (int)($params['trees_added'] ?? 100);
            $projected += min(15, $n / 20);
            $notes[] = "Green cover uplift from {$n} new trees (estimated)";
        }
        if ($presetKey === 'report_surge' || !empty($params['reports_multiplier'])) {
            $m = (float)($params['reports_multiplier'] ?? 1.5);
            $projected -= min(20, ($m - 1) * 30);
            $notes[] = 'Maintenance score pressure from report surge';
        }
        $projected = max(0, min(100, round($projected, 1)));
        return [
            'ok' => true,
            'preset' => $presetKey,
            'baseline_score' => $base,
            'projected_score' => $projected,
            'delta' => round($projected - $base, 1),
            'notes' => $notes,
            'measurement_type' => 'PROJECTED',
        ];
    }
}
