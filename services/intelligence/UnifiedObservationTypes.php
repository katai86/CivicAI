<?php
/**
 * M1 – Unified observation measurement types and provenance layers.
 */
final class UnifiedObservationTypes
{
    public const MEASURED = 'MEASURED';
    public const OBSERVED = 'OBSERVED';
    public const ESTIMATED = 'ESTIMATED';
    public const INFERRED = 'INFERRED';
    public const AI_INTERPRETATION = 'AI_INTERPRETATION';
    public const PROJECTED = 'PROJECTED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MEASURED,
            self::OBSERVED,
            self::ESTIMATED,
            self::INFERRED,
            self::AI_INTERPRETATION,
            self::PROJECTED,
        ];
    }

    public static function normalize(?string $type): string
    {
        $t = strtoupper(trim((string)$type));
        return in_array($t, self::all(), true) ? $t : self::MEASURED;
    }

    /** LLM-only layers must not be stored as MEASURED. */
    public static function isNumericClaimAllowed(string $type): bool
    {
        return !in_array($type, [self::AI_INTERPRETATION, self::PROJECTED], true);
    }
}
