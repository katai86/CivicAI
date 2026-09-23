<?php
/**
 * M10 – City Health 2.0 with snapshot history.
 */
require_once __DIR__ . '/../../services/CityHealthScore.php';
require_once __DIR__ . '/CityChangeIntelligence.php';
require_once __DIR__ . '/CityTimeMachine.php';

final class CityHealthScoreV2
{
    /** @return array<string,mixed> */
    public function compute(int $authorityId): array
    {
        $v1 = (new CityHealthScore())->compute($authorityId);
        $change = (new CityChangeIntelligence())->snapshot($authorityId);
        $timeline = (new CityTimeMachine())->timeline($authorityId, 30);
        $trend = 'stable';
        if (count($timeline) >= 2) {
            $first = (float)($timeline[0]['health_score'] ?? 0);
            $last = (float)($timeline[count($timeline) - 1]['health_score'] ?? 0);
            if ($last - $first > 3) {
                $trend = 'improving';
            } elseif ($first - $last > 3) {
                $trend = 'deteriorating';
            }
        }
        return array_merge($v1, [
            'version' => '2.0',
            'trend' => $trend,
            'timeline' => $timeline,
            'change' => $change['trends'] ?? [],
            'snapshot_health' => $change['health_score'] ?? null,
        ]);
    }
}
