<?php
/**
 * Unified city observation store – idempotent insert by dedupe_hash.
 */
require_once __DIR__ . '/CityIntelSchema.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../intelligence/UnifiedObservationTypes.php';

final class CityObservationStore
{
    /**
     * @param array{
     *   authority_id?:?int,
     *   source_key:string,
     *   source_type?:string,
     *   external_id?:?string,
     *   category?:string,
     *   indicator_type?:?string,
     *   observed_at?:string,
     *   valid_from?:?string,
     *   valid_to?:?string,
     *   lat?:?float,
     *   lng?:?float,
     *   admin_area?:?string,
     *   value_num?:?float,
     *   value_text?:?string,
     *   unit?:?string,
     *   confidence?:?float,
     *   quality?:?float,
     *   metadata?:?array,
     *   raw_ref?:?string,
     *   processing_version?:string
     * } $obs
     * @return array{ok:bool,inserted:bool,id:?int,error:?string}
     */
    public function upsert(array $obs): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['ok' => false, 'inserted' => false, 'id' => null, 'error' => 'schema_missing'];
        }
        $sourceKey = trim((string)($obs['source_key'] ?? ''));
        if ($sourceKey === '') {
            return ['ok' => false, 'inserted' => false, 'id' => null, 'error' => 'missing_source'];
        }
        $authorityId = isset($obs['authority_id']) && $obs['authority_id'] !== null ? (int)$obs['authority_id'] : null;
        $externalId = isset($obs['external_id']) ? (string)$obs['external_id'] : '';
        $indicatorType = isset($obs['indicator_type']) ? (string)$obs['indicator_type'] : '';
        $observedAt = (string)($obs['observed_at'] ?? date('Y-m-d H:i:s'));
        $valueNum = array_key_exists('value_num', $obs) && $obs['value_num'] !== null ? (float)$obs['value_num'] : null;
        $lat = isset($obs['lat']) && $obs['lat'] !== null ? (float)$obs['lat'] : null;
        $lng = isset($obs['lng']) && $obs['lng'] !== null ? (float)$obs['lng'] : null;

        $dedupeSeed = implode('|', [
            $sourceKey,
            $externalId,
            $indicatorType,
            substr($observedAt, 0, 13), // hour bucket for continuous sensors
            $valueNum === null ? '' : (string)round($valueNum, 4),
            $lat === null ? '' : (string)round($lat, 4),
            $lng === null ? '' : (string)round($lng, 4),
        ]);
        $hash = sha1($dedupeSeed);

        $metaJson = null;
        if (!empty($obs['metadata']) && is_array($obs['metadata'])) {
            $metaJson = json_encode($obs['metadata'], JSON_UNESCAPED_UNICODE);
        }

        try {
            $pdo = db();
            $hasM1 = db_table_has_column($pdo, 'city_observations', 'measurement_type');
            if ($hasM1) {
                $measurementType = UnifiedObservationTypes::normalize($obs['measurement_type'] ?? null);
                $sql = 'INSERT INTO city_observations
                  (authority_id, source_key, source_type, external_id, category, indicator_type, measurement_type,
                   provenance_layer, observed_at, valid_from, valid_to, lat, lng, admin_area, zone_key, geographic_scope,
                   value_num, value_text, unit, confidence, quality, dedupe_hash, metadata_json, raw_ref,
                   processing_version, model_provider, model_name, model_version, pipeline_version, entity_type, entity_id)
                  VALUES
                  (:authority_id, :source_key, :source_type, :external_id, :category, :indicator_type, :measurement_type,
                   :provenance_layer, :observed_at, :valid_from, :valid_to, :lat, :lng, :admin_area, :zone_key, :geographic_scope,
                   :value_num, :value_text, :unit, :confidence, :quality, :dedupe_hash, :metadata_json, :raw_ref,
                   :processing_version, :model_provider, :model_name, :model_version, :pipeline_version, :entity_type, :entity_id)
                  ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':authority_id' => $authorityId,
                    ':source_key' => mb_substr($sourceKey, 0, 64),
                    ':source_type' => mb_substr((string)($obs['source_type'] ?? 'open_data'), 0, 64),
                    ':external_id' => $externalId !== '' ? mb_substr($externalId, 0, 190) : null,
                    ':category' => mb_substr((string)($obs['category'] ?? 'general'), 0, 64),
                    ':indicator_type' => $indicatorType !== '' ? mb_substr($indicatorType, 0, 96) : null,
                    ':measurement_type' => $measurementType,
                    ':provenance_layer' => isset($obs['provenance_layer']) ? mb_substr((string)$obs['provenance_layer'], 0, 32) : null,
                    ':observed_at' => $observedAt,
                    ':valid_from' => $obs['valid_from'] ?? null,
                    ':valid_to' => $obs['valid_to'] ?? null,
                    ':lat' => $lat,
                    ':lng' => $lng,
                    ':admin_area' => isset($obs['admin_area']) ? mb_substr((string)$obs['admin_area'], 0, 160) : null,
                    ':zone_key' => isset($obs['zone_key']) ? mb_substr((string)$obs['zone_key'], 0, 96) : null,
                    ':geographic_scope' => mb_substr((string)($obs['geographic_scope'] ?? 'point'), 0, 32),
                    ':value_num' => $valueNum,
                    ':value_text' => isset($obs['value_text']) ? mb_substr((string)$obs['value_text'], 0, 255) : null,
                    ':unit' => isset($obs['unit']) ? mb_substr((string)$obs['unit'], 0, 32) : null,
                    ':confidence' => isset($obs['confidence']) ? (float)$obs['confidence'] : null,
                    ':quality' => isset($obs['quality']) ? (float)$obs['quality'] : null,
                    ':dedupe_hash' => $hash,
                    ':metadata_json' => $metaJson,
                    ':raw_ref' => isset($obs['raw_ref']) ? mb_substr((string)$obs['raw_ref'], 0, 255) : null,
                    ':processing_version' => mb_substr((string)($obs['processing_version'] ?? 'ci-1'), 0, 32),
                    ':model_provider' => isset($obs['model_provider']) ? mb_substr((string)$obs['model_provider'], 0, 64) : null,
                    ':model_name' => isset($obs['model_name']) ? mb_substr((string)$obs['model_name'], 0, 96) : null,
                    ':model_version' => isset($obs['model_version']) ? mb_substr((string)$obs['model_version'], 0, 64) : null,
                    ':pipeline_version' => isset($obs['pipeline_version']) ? mb_substr((string)$obs['pipeline_version'], 0, 32) : null,
                    ':entity_type' => isset($obs['entity_type']) ? mb_substr((string)$obs['entity_type'], 0, 32) : null,
                    ':entity_id' => isset($obs['entity_id']) ? (int)$obs['entity_id'] : null,
                ]);
            } else {
                $sql = 'INSERT INTO city_observations
                  (authority_id, source_key, source_type, external_id, category, indicator_type,
                   observed_at, valid_from, valid_to, lat, lng, admin_area, value_num, value_text, unit,
                   confidence, quality, dedupe_hash, metadata_json, raw_ref, processing_version)
                  VALUES
                  (:authority_id, :source_key, :source_type, :external_id, :category, :indicator_type,
                   :observed_at, :valid_from, :valid_to, :lat, :lng, :admin_area, :value_num, :value_text, :unit,
                   :confidence, :quality, :dedupe_hash, :metadata_json, :raw_ref, :processing_version)
                  ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':authority_id' => $authorityId,
                    ':source_key' => mb_substr($sourceKey, 0, 64),
                    ':source_type' => mb_substr((string)($obs['source_type'] ?? 'open_data'), 0, 64),
                    ':external_id' => $externalId !== '' ? mb_substr($externalId, 0, 190) : null,
                    ':category' => mb_substr((string)($obs['category'] ?? 'general'), 0, 64),
                    ':indicator_type' => $indicatorType !== '' ? mb_substr($indicatorType, 0, 96) : null,
                    ':observed_at' => $observedAt,
                    ':valid_from' => $obs['valid_from'] ?? null,
                    ':valid_to' => $obs['valid_to'] ?? null,
                    ':lat' => $lat,
                    ':lng' => $lng,
                    ':admin_area' => isset($obs['admin_area']) ? mb_substr((string)$obs['admin_area'], 0, 160) : null,
                    ':value_num' => $valueNum,
                    ':value_text' => isset($obs['value_text']) ? mb_substr((string)$obs['value_text'], 0, 255) : null,
                    ':unit' => isset($obs['unit']) ? mb_substr((string)$obs['unit'], 0, 32) : null,
                    ':confidence' => isset($obs['confidence']) ? (float)$obs['confidence'] : null,
                    ':quality' => isset($obs['quality']) ? (float)$obs['quality'] : null,
                    ':dedupe_hash' => $hash,
                    ':metadata_json' => $metaJson,
                    ':raw_ref' => isset($obs['raw_ref']) ? mb_substr((string)$obs['raw_ref'], 0, 255) : null,
                    ':processing_version' => mb_substr((string)($obs['processing_version'] ?? 'ci-1'), 0, 32),
                ]);
            }
            $id = (int)$pdo->lastInsertId();
            $inserted = $st->rowCount() === 1;
            return ['ok' => true, 'inserted' => $inserted, 'id' => $id > 0 ? $id : null, 'error' => null];
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('CityObservationStore::upsert: ' . $e->getMessage());
            }
            return ['ok' => false, 'inserted' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{ok:int,inserted:int,errors:int}
     */
    public function upsertMany(array $rows): array
    {
        $stats = ['ok' => 0, 'inserted' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $stats['errors']++;
                continue;
            }
            $r = $this->upsert($row);
            if ($r['ok']) {
                $stats['ok']++;
                if ($r['inserted']) {
                    $stats['inserted']++;
                }
            } else {
                $stats['errors']++;
            }
        }
        return $stats;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecent(?int $authorityId, int $limit = 100, ?string $sourceKey = null, ?string $indicatorType = null): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $where = ['1=1'];
        $params = [];
        if ($authorityId !== null && $authorityId > 0) {
            $where[] = 'authority_id = ?';
            $params[] = $authorityId;
        }
        if ($sourceKey) {
            $where[] = 'source_key = ?';
            $params[] = $sourceKey;
        }
        if ($indicatorType) {
            $where[] = 'indicator_type = ?';
            $params[] = $indicatorType;
        }
        $sql = 'SELECT * FROM city_observations WHERE ' . implode(' AND ', $where)
            . ' ORDER BY observed_at DESC, id DESC LIMIT ' . $limit;
        try {
            $st = db()->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
