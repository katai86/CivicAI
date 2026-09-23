<?php
/**
 * M1 – Unified observation facade: validates measurement_type, provenance, writes observation + provenance row.
 */
require_once __DIR__ . '/UnifiedObservationTypes.php';
require_once __DIR__ . '/../cityintel/CityObservationStore.php';
require_once __DIR__ . '/../cityintel/CityIntelSchema.php';
require_once __DIR__ . '/../../db.php';

final class UnifiedObservationLayer
{
    private CityObservationStore $store;

    public function __construct(?CityObservationStore $store = null)
    {
        $this->store = $store ?? new CityObservationStore();
    }

    /**
     * @param array<string,mixed> $obs
     * @return array{ok:bool,inserted:bool,observation_id:?int,error:?string}
     */
    public function record(array $obs): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['ok' => false, 'inserted' => false, 'observation_id' => null, 'error' => 'schema_missing'];
        }

        $measurementType = UnifiedObservationTypes::normalize($obs['measurement_type'] ?? null);
        if (!UnifiedObservationTypes::isNumericClaimAllowed($measurementType)
            && array_key_exists('value_num', $obs) && $obs['value_num'] !== null) {
            return ['ok' => false, 'inserted' => false, 'observation_id' => null, 'error' => 'numeric_not_allowed_for_layer'];
        }

        $meta = is_array($obs['metadata'] ?? null) ? $obs['metadata'] : [];
        $meta['measurement_type'] = $measurementType;
        if (!empty($obs['provenance_layer'])) {
            $meta['provenance_layer'] = (string)$obs['provenance_layer'];
        }
        if (!empty($obs['model_provider'])) {
            $meta['model_provider'] = (string)$obs['model_provider'];
            $meta['model_name'] = (string)($obs['model_name'] ?? '');
            $meta['model_version'] = (string)($obs['model_version'] ?? '');
            $meta['pipeline_version'] = (string)($obs['pipeline_version'] ?? '');
        }

        $payload = array_merge($obs, [
            'metadata' => $meta,
            'processing_version' => (string)($obs['pipeline_version'] ?? $obs['processing_version'] ?? 'ci-m1'),
        ]);

        $result = $this->store->upsert($payload);
        if (empty($result['ok']) || empty($result['id'])) {
            return [
                'ok' => !empty($result['ok']),
                'inserted' => !empty($result['inserted']),
                'observation_id' => $result['id'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        }

        $this->writeProvenance((int)$result['id'], $obs, $measurementType);

        return [
            'ok' => true,
            'inserted' => !empty($result['inserted']),
            'observation_id' => (int)$result['id'],
            'error' => null,
        ];
    }

    /** @param array<string,mixed> $obs */
    private function writeProvenance(int $observationId, array $obs, string $measurementType): void
    {
        try {
            $pdo = db();
            if (!db_table_has_column($pdo, 'civic_observation_provenance', 'observation_id')) {
                return;
            }
            $evidence = $obs['evidence'] ?? $obs['evidence_json'] ?? null;
            $st = $pdo->prepare('
                INSERT INTO civic_observation_provenance
                  (observation_id, authority_id, measurement_type, provenance_layer, source_key, raw_ref,
                   model_provider, model_name, model_version, pipeline_version, evidence_json)
                VALUES
                  (:observation_id, :authority_id, :measurement_type, :provenance_layer, :source_key, :raw_ref,
                   :model_provider, :model_name, :model_version, :pipeline_version, :evidence_json)
                ON DUPLICATE KEY UPDATE
                  measurement_type = VALUES(measurement_type),
                  provenance_layer = VALUES(provenance_layer),
                  evidence_json = VALUES(evidence_json)
            ');
            $st->execute([
                ':observation_id' => $observationId,
                ':authority_id' => isset($obs['authority_id']) ? (int)$obs['authority_id'] : null,
                ':measurement_type' => $measurementType,
                ':provenance_layer' => isset($obs['provenance_layer']) ? mb_substr((string)$obs['provenance_layer'], 0, 32) : null,
                ':source_key' => isset($obs['source_key']) ? mb_substr((string)$obs['source_key'], 0, 64) : null,
                ':raw_ref' => isset($obs['raw_ref']) ? mb_substr((string)$obs['raw_ref'], 0, 255) : null,
                ':model_provider' => isset($obs['model_provider']) ? mb_substr((string)$obs['model_provider'], 0, 64) : null,
                ':model_name' => isset($obs['model_name']) ? mb_substr((string)$obs['model_name'], 0, 96) : null,
                ':model_version' => isset($obs['model_version']) ? mb_substr((string)$obs['model_version'], 0, 64) : null,
                ':pipeline_version' => isset($obs['pipeline_version']) ? mb_substr((string)$obs['pipeline_version'], 0, 32) : null,
                ':evidence_json' => $evidence ? json_encode($evidence, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('UnifiedObservationLayer::writeProvenance: ' . $e->getMessage());
            }
        }
    }
}
