<?php
/**
 * M23 – Multi-model fusion: species consensus + vision cross-check.
 */
require_once __DIR__ . '/ConfidencePolicy.php';

final class PlantVisionFusionService
{
    /**
     * @param array<string,mixed> $speciesResult
     * @param array<string,mixed> $conditionResult
     * @return array<string,mixed>
     */
    public static function fuse(array $speciesResult, array $conditionResult): array
    {
        $candidates = is_array($speciesResult['candidates'] ?? null) ? $speciesResult['candidates'] : [];
        $consensus = ConfidencePolicy::speciesConsensus($candidates);
        $conflict = false;
        $failureState = null;

        if (empty($speciesResult['ok']) && empty($conditionResult['ok'])) {
            $failureState = $speciesResult['failure_state'] ?? $conditionResult['failure_state'] ?? 'PROCESSING_FAILED';
        } elseif (($consensus['label'] ?? '') === 'UNKNOWN' && !empty($conditionResult['ok'])) {
            $failureState = 'LOW_CONFIDENCE';
        }

        if (!empty($speciesResult['ok']) && !empty($conditionResult['ok'])) {
            $stress = strtolower((string)($conditionResult['overall_stress'] ?? 'none'));
            if (($consensus['label'] ?? '') === 'UNKNOWN' && in_array($stress, ['moderate', 'severe'], true)) {
                $conflict = true;
            }
        }

        $status = 'SUCCESS';
        if ($failureState !== null) {
            $status = ($speciesResult['ok'] || $conditionResult['ok']) ? 'PARTIAL_SUCCESS' : $failureState;
        } elseif ($conflict) {
            $status = 'CONFLICTED_IDENTIFICATION';
        }

        return [
            'status' => $status,
            'species_consensus' => $consensus,
            'condition' => $conditionResult,
            'conflict' => $conflict,
            'confidence_label' => $consensus['label'] ?? 'UNKNOWN',
        ];
    }
}
