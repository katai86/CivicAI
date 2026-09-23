<?php
/**
 * M25 – Tree inspection → UnifiedObservationLayer → City Intelligence.
 */
require_once __DIR__ . '/../intelligence/UnifiedObservationLayer.php';
require_once __DIR__ . '/../intelligence/CivicEvidenceLinkStore.php';
require_once __DIR__ . '/../cityintel/CityIndicatorEngines.php';

final class PlantTreeCiBridge
{
    /**
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $context
     */
    public function ingest(array $analysis, array $context, ?int $inspectionId): array
    {
        $authorityId = isset($context['authority_id']) ? (int)$context['authority_id'] : null;
        if ($authorityId === null || $authorityId <= 0) {
            return ['ok' => false, 'inserted' => 0, 'error' => 'no_authority'];
        }
        $layer = new UnifiedObservationLayer();
        $health = is_array($analysis['health'] ?? null) ? $analysis['health'] : [];
        $risk = is_array($analysis['risk'] ?? null) ? $analysis['risk'] : [];
        $now = date('Y-m-d H:i:s');
        $lat = isset($context['lat']) ? (float)$context['lat'] : null;
        $lng = isset($context['lng']) ? (float)$context['lng'] : null;
        $treeId = isset($context['tree_id']) ? (int)$context['tree_id'] : null;
        $inserted = 0;
        $obsIds = [];

        if (isset($health['health_score'])) {
            $r = $layer->record([
                'authority_id' => $authorityId,
                'source_key' => 'plant_tree_ai',
                'source_type' => 'tree_inspection',
                'external_id' => 'tree_health_' . ($inspectionId ?? uniqid()),
                'category' => 'trees',
                'indicator_type' => 'trees.visual_health_score',
                'observed_at' => $now,
                'lat' => $lat,
                'lng' => $lng,
                'value_num' => (float)$health['health_score'],
                'unit' => 'score',
                'confidence' => 0.7,
                'measurement_type' => 'INFERRED',
                'provenance_layer' => 'plant_tree',
                'entity_type' => 'tree',
                'entity_id' => $treeId,
                'pipeline_version' => $analysis['pipeline_version'] ?? 'plant-cloud-1.0',
                'model_provider' => 'TreeHealthEngine',
                'evidence' => ['inspection_id' => $inspectionId, 'health_label' => $health['health_label'] ?? null],
            ]);
            if (!empty($r['inserted'])) $inserted++;
            if (!empty($r['observation_id'])) $obsIds[] = (int)$r['observation_id'];
        }

        if (!empty($risk['risk_level']) && ($risk['risk_level'] ?? 'NONE') !== 'NONE') {
            $riskScore = match (strtoupper((string)$risk['risk_level'])) {
                'HIGH' => 85.0, 'MEDIUM' => 55.0, 'LOW' => 25.0, default => 0.0,
            };
            $r = $layer->record([
                'authority_id' => $authorityId,
                'source_key' => 'plant_tree_ai',
                'source_type' => 'tree_inspection',
                'external_id' => 'tree_risk_' . ($inspectionId ?? uniqid()),
                'category' => 'trees',
                'indicator_type' => 'trees.public_risk_score',
                'observed_at' => $now,
                'lat' => $lat,
                'lng' => $lng,
                'value_num' => $riskScore,
                'unit' => 'score',
                'confidence' => 0.65,
                'measurement_type' => 'INFERRED',
                'provenance_layer' => 'plant_tree',
                'entity_type' => 'tree',
                'entity_id' => $treeId,
                'pipeline_version' => $analysis['pipeline_version'] ?? 'plant-cloud-1.0',
                'model_provider' => 'PublicRiskEngine',
                'evidence' => ['inspection_id' => $inspectionId, 'action' => $risk['recommended_action'] ?? null],
            ]);
            if (!empty($r['inserted'])) $inserted++;
            if (!empty($r['observation_id'])) $obsIds[] = (int)$r['observation_id'];
        }

        if ($inserted > 0) {
            try {
                (new CityIndicatorEngine())->recompute($authorityId, 30);
            } catch (Throwable $e) {
                if (function_exists('log_error')) {
                    log_error('PlantTreeCiBridge recompute: ' . $e->getMessage());
                }
            }
        }

        $evidence = new CivicEvidenceLinkStore();
        if ($inspectionId && $treeId) {
            $evidence->link([
                'authority_id' => $authorityId,
                'parent_type' => 'tree',
                'parent_id' => (int)$treeId,
                'child_type' => 'tree_inspection',
                'child_id' => (int)$inspectionId,
                'relation' => 'inspected_by',
                'layer' => 'plant_tree',
                'confidence' => 0.7,
            ]);
        }
        foreach ($obsIds as $oid) {
            if ($inspectionId) {
                $evidence->link([
                    'authority_id' => $authorityId,
                    'parent_type' => 'tree_inspection',
                    'parent_id' => (int)$inspectionId,
                    'child_type' => 'observation',
                    'child_id' => $oid,
                    'relation' => 'feeds',
                    'layer' => 'city_intel',
                    'confidence' => 0.65,
                ]);
            }
        }

        return ['ok' => $inserted > 0, 'inserted' => $inserted, 'observation_ids' => $obsIds];
    }
}
