<?php
/**
 * M22 – Public risk assessment (separate from health score).
 */
final class PublicRiskEngine
{
    /** @param array<int,array<string,mixed>> $observations */
    public static function compute(array $observations, float $healthScore): array
    {
        $risk = 0;
        foreach ($observations as $obs) {
            if (!is_array($obs)) continue;
            $signal = (string)($obs['signal'] ?? '');
            $severity = strtolower((string)($obs['severity'] ?? 'none'));
            if ($signal === 'damage' && in_array($severity, ['moderate', 'severe'], true)) {
                $risk += $severity === 'severe' ? 3 : 2;
            }
            if ($signal === 'disease_signs' && $severity === 'severe') {
                $risk += 1;
            }
        }
        if ($healthScore < 25) {
            $risk += 2;
        } elseif ($healthScore < 50) {
            $risk += 1;
        }
        $level = match (true) {
            $risk >= 4 => 'HIGH',
            $risk >= 2 => 'MEDIUM',
            $risk >= 1 => 'LOW',
            default => 'NONE',
        };
        $action = match ($level) {
            'HIGH' => 'INSPECTION_URGENT',
            'MEDIUM' => 'INSPECTION_RECOMMENDED',
            'LOW' => 'MONITOR',
            default => 'NONE',
        };
        return [
            'risk_level' => $level,
            'risk_score' => $risk,
            'recommended_action' => $action,
            'measurement_type' => 'INFERRED',
            'engine' => 'PublicRiskEngine',
            'engine_version' => '1.0',
        ];
    }
}
