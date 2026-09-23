<?php
/**
 * Citizen report → UnifiedObservationLayer (M1).
 */
require_once __DIR__ . '/CityDataSourceRegistry.php';
require_once __DIR__ . '/../intelligence/UnifiedObservationLayer.php';
require_once __DIR__ . '/../intelligence/CivicEvidenceLinkStore.php';

final class CityCitizenSignalBridge
{
    public static function fromReport(
        int $reportId,
        ?int $authorityId,
        string $category,
        ?float $lat,
        ?float $lng,
        ?string $city,
        ?string $suburb = null
    ): void {
        if ($reportId <= 0) {
            return;
        }
        try {
            $layer = new UnifiedObservationLayer();
            $reg = new CityDataSourceRegistry();
            $reg->markAttempt('citizen_reports');
            $cat = mb_substr($category !== '' ? $category : 'other', 0, 64);
            $inserted = 0;
            $obsId = null;

            $r = $layer->record([
                'authority_id' => $authorityId,
                'source_key' => 'citizen_reports',
                'source_type' => 'citizen',
                'external_id' => 'report_' . $reportId,
                'category' => 'citizen',
                'indicator_type' => 'citizen.report_event',
                'observed_at' => date('Y-m-d H:i:s'),
                'lat' => $lat,
                'lng' => $lng,
                'admin_area' => $suburb ?: $city,
                'value_num' => 1.0,
                'value_text' => $cat,
                'unit' => 'event',
                'confidence' => 0.65,
                'measurement_type' => 'MEASURED',
                'provenance_layer' => 'citizen',
                'entity_type' => 'report',
                'entity_id' => $reportId,
                'pipeline_version' => 'citizen-bridge-1.0',
                'evidence' => ['report_id' => $reportId, 'category' => $cat],
                'raw_ref' => 'reports:' . $reportId,
            ]);
            if (!empty($r['inserted'])) {
                $inserted++;
            }
            $obsId = $r['observation_id'] ?? null;

            if (in_array($cat, ['green', 'trash'], true)) {
                $r2 = $layer->record([
                    'authority_id' => $authorityId,
                    'source_key' => 'citizen_reports',
                    'source_type' => 'citizen',
                    'external_id' => 'report_green_' . $reportId,
                    'category' => 'citizen',
                    'indicator_type' => 'citizen.green_signal',
                    'observed_at' => date('Y-m-d H:i:s'),
                    'lat' => $lat,
                    'lng' => $lng,
                    'admin_area' => $suburb ?: $city,
                    'value_num' => 1.0,
                    'unit' => 'event',
                    'confidence' => 0.65,
                    'measurement_type' => 'MEASURED',
                    'provenance_layer' => 'citizen',
                    'entity_type' => 'report',
                    'entity_id' => $reportId,
                    'pipeline_version' => 'citizen-bridge-1.0',
                    'raw_ref' => 'reports:' . $reportId,
                ]);
                if (!empty($r2['inserted'])) {
                    $inserted++;
                }
            }

            if ($obsId) {
                (new CivicEvidenceLinkStore())->link([
                    'authority_id' => $authorityId,
                    'parent_type' => 'report',
                    'parent_id' => $reportId,
                    'child_type' => 'observation',
                    'child_id' => (int)$obsId,
                    'relation' => 'measured_as',
                    'layer' => 'citizen',
                    'confidence' => 0.65,
                ]);
            }
            $reg->markSuccess('citizen_reports', $inserted, 0.65);
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('CityCitizenSignalBridge: ' . $e->getMessage());
            }
        }
    }
}
