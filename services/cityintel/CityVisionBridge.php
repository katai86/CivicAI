<?php
/**
 * Vision / urban_observations → city_observations (+ optional lightweight insight refresh).
 */
require_once __DIR__ . '/CityObservationStore.php';
require_once __DIR__ . '/CityDataSourceRegistry.php';
require_once __DIR__ . '/CityIndicatorEngines.php';
require_once __DIR__ . '/CityInsightEngine.php';
require_once __DIR__ . '/../intelligence/UnifiedObservationLayer.php';

final class CityVisionBridge
{
    /**
     * @param array<string,mixed> $vision
     * @return array{ok:bool,inserted:int,insight_id:?int}
     */
    public static function ingestFromVision(
        ?int $authorityId,
        ?float $lat,
        ?float $lng,
        array $vision,
        string $source = 'citybrain_vision',
        ?int $urbanObservationId = null
    ): array {
        $store = new CityObservationStore();
        $reg = new CityDataSourceRegistry();
        $reg->markAttempt('urban_vision');
        $rows = [];
        $now = date('Y-m-d H:i:s');
        $conf = isset($vision['confidence_score']) && is_numeric($vision['confidence_score'])
            ? (float)$vision['confidence_score'] : 0.6;
        $severity = strtolower((string)($vision['urgency_level'] ?? $vision['hazard_level'] ?? 'medium'));
        $sevScore = $severity === 'high' || $severity === 'critical' ? 80.0 : ($severity === 'low' ? 20.0 : 50.0);

        $green = is_array($vision['green_surfaces'] ?? null) ? $vision['green_surfaces'] : [];
        $veg = null;
        if (isset($green['vegetation_pct']) && is_numeric($green['vegetation_pct'])) {
            $veg = (float)$green['vegetation_pct'];
        } elseif (isset($vision['vegetation_pct']) && is_numeric($vision['vegetation_pct'])) {
            $veg = (float)$vision['vegetation_pct'];
        }

        $metaBase = [
            'measured' => true,
            'vision_source' => $source,
            'urban_observation_id' => $urbanObservationId,
            'category' => $vision['suggested_category'] ?? null,
        ];

        $rows[] = [
            'authority_id' => $authorityId,
            'source_key' => 'urban_vision',
            'source_type' => 'vision',
            'external_id' => 'vision_sev_' . ($urbanObservationId ?: sha1($now . json_encode($metaBase))),
            'category' => 'vision',
            'indicator_type' => 'vision.severity_score',
            'observed_at' => $now,
            'lat' => $lat,
            'lng' => $lng,
            'value_num' => $sevScore,
            'unit' => 'index',
            'confidence' => $conf,
            'quality' => 0.6,
            'metadata' => $metaBase + ['severity' => $severity],
            'raw_ref' => 'urban_observations:' . ($urbanObservationId ?? 'n/a'),
        ];

        if ($veg !== null) {
            $rows[] = [
                'authority_id' => $authorityId,
                'source_key' => 'urban_vision',
                'source_type' => 'vision',
                'external_id' => 'vision_veg_' . ($urbanObservationId ?: 'x'),
                'category' => 'vision',
                'indicator_type' => 'vision.vegetation_pct',
                'observed_at' => $now,
                'lat' => $lat,
                'lng' => $lng,
                'value_num' => $veg,
                'unit' => 'percent',
                'confidence' => $conf,
                'quality' => 0.55,
                'metadata' => $metaBase,
                'raw_ref' => 'urban_observations:' . ($urbanObservationId ?? 'n/a'),
            ];
            if ($veg < 15 && ($severity === 'high' || $severity === 'medium')) {
                $rows[] = [
                    'authority_id' => $authorityId,
                    'source_key' => 'urban_vision',
                    'source_type' => 'vision',
                    'external_id' => 'vision_stress_' . ($urbanObservationId ?: 'x'),
                    'category' => 'vision',
                    'indicator_type' => 'vision.vegetation_stress_signals',
                    'observed_at' => $now,
                    'lat' => $lat,
                    'lng' => $lng,
                    'value_num' => 1.0,
                    'unit' => 'count',
                    'confidence' => $conf,
                    'quality' => 0.55,
                    'metadata' => $metaBase + ['reason' => 'low_vegetation_pct'],
                    'raw_ref' => 'urban_observations:' . ($urbanObservationId ?? 'n/a'),
                ];
            }
        }

        $layer = new UnifiedObservationLayer();
        $inserted = 0;
        foreach ($rows as $row) {
            $row['measurement_type'] = 'OBSERVED';
            $row['provenance_layer'] = 'vision';
            $row['pipeline_version'] = 'city-vision-bridge-1.0';
            $r = $layer->record($row);
            if (!empty($r['inserted'])) {
                $inserted++;
            }
        }
        $stats = ['ok' => $inserted, 'inserted' => $inserted, 'errors' => count($rows) - $inserted];
        $reg->markSuccess('urban_vision', $stats['inserted'], 0.6);

        // Lightweight indicator + insight refresh for this authority
        $insightId = null;
        if ($authorityId !== null && $authorityId > 0 && $stats['ok'] > 0) {
            try {
                (new CityIndicatorEngine())->recompute($authorityId, 30);
                (new CityBaselineEngine())->recompute($authorityId, 90);
                (new CityAnomalyEngine())->detect($authorityId, 20.0);
                $g = (new CityInsightEngine())->generate($authorityId);
                if (!empty($g['insights'][0]['id'])) {
                    $insightId = (int)$g['insights'][0]['id'];
                }
            } catch (Throwable $e) {
                if (function_exists('log_error')) {
                    log_error('CityVisionBridge refresh: ' . $e->getMessage());
                }
            }
        }

        return ['ok' => $stats['errors'] === 0, 'inserted' => $stats['inserted'], 'insight_id' => $insightId];
    }
}
