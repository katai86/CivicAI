<?php
/**
 * M18 – Confidence tiers and unknown detection.
 */
final class ConfidencePolicy
{
    public const HIGH = 0.85;
    public const MEDIUM = 0.60;
    public const LOW = 0.35;

    public static function label(float $confidence): string
    {
        if ($confidence >= self::HIGH) return 'HIGH';
        if ($confidence >= self::MEDIUM) return 'MEDIUM';
        if ($confidence >= self::LOW) return 'LOW';
        return 'UNKNOWN';
    }

    /** @param array<int,array<string,mixed>> $candidates */
    public static function speciesConsensus(array $candidates): array
    {
        if ($candidates === []) {
            return ['consensus' => null, 'confidence' => 0.0, 'label' => 'UNKNOWN', 'margin' => 0.0];
        }
        usort($candidates, static fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $top = $candidates[0];
        $topScore = (float)($top['score'] ?? 0);
        $second = $candidates[1]['score'] ?? 0.0;
        $margin = $topScore - (float)$second;
        $label = self::label($topScore);
        if ($topScore < self::LOW || $margin < 0.05) {
            $label = 'UNKNOWN';
        }
        return [
            'consensus' => $top,
            'confidence' => $topScore,
            'label' => $label,
            'margin' => round($margin, 4),
        ];
    }
}
