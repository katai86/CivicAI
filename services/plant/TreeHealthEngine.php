<?php
/**
 * M22 – Deterministic tree health scoring (NOT LLM).
 */
final class TreeHealthEngine
{
    /** @param array<int,array<string,mixed>> $observations */
    public static function compute(array $observations, ?string $species = null): array
    {
        $score = 85.0;
        $signals = [];
        foreach ($observations as $obs) {
            if (!is_array($obs)) continue;
            $signal = (string)($obs['signal'] ?? '');
            $value = strtolower((string)($obs['value'] ?? ''));
            $severity = strtolower((string)($obs['severity'] ?? 'none'));
            $delta = match ($severity) {
                'severe' => 25.0,
                'moderate' => 15.0,
                'mild' => 8.0,
                default => 0.0,
            };
            if ($delta > 0) {
                $score -= $delta;
                $signals[] = $signal . ':' . $value;
            }
        }
        $score = max(0.0, min(100.0, round($score, 1)));
        $label = match (true) {
            $score >= 75 => 'HEALTHY',
            $score >= 50 => 'STRESSED',
            $score >= 25 => 'DECLINING',
            default => 'CRITICAL',
        };
        return [
            'health_score' => $score,
            'health_label' => $label,
            'signals' => $signals,
            'measurement_type' => 'INFERRED',
            'engine' => 'TreeHealthEngine',
            'engine_version' => '1.0',
        ];
    }
}
