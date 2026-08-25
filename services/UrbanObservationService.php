<?php
/**
 * Tartós városi megfigyelések (Vision → City Brain dataset).
 * Multi-tenant: authority_id scope. Auto-create table if missing (demo resilience).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

final class UrbanObservationService
{
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            db()->query('SELECT 1 FROM urban_observations LIMIT 1');
        } catch (Throwable $e) {
            try {
                db()->exec("
CREATE TABLE IF NOT EXISTS urban_observations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'citybrain_vision',
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  image_path VARCHAR(512) NULL,
  street_condition VARCHAR(32) NULL,
  severity VARCHAR(16) NULL,
  category VARCHAR(64) NULL,
  vegetation_pct DECIMAL(6,2) NULL,
  scene_summary TEXT NULL,
  recommended_action TEXT NULL,
  wow_highlights_json MEDIUMTEXT NULL,
  trees_json MEDIUMTEXT NULL,
  green_surfaces_json MEDIUMTEXT NULL,
  street_issues_json MEDIUMTEXT NULL,
  output_json LONGTEXT NULL,
  confidence_score DECIMAL(5,4) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_uo_auth_created (authority_id, created_at),
  KEY idx_uo_geo (lat, lng),
  KEY idx_uo_source (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
            } catch (Throwable $e2) {
                if (function_exists('log_error')) {
                    log_error('UrbanObservationService schema: ' . $e2->getMessage());
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $vision Normalized citybrain / civic vision payload
     * @return array{ok:bool,id:?int,error?:string}
     */
    public function save(
        array $vision,
        ?int $authorityId,
        ?float $lat,
        ?float $lng,
        string $source = 'citybrain_vision',
        ?string $imagePath = null,
        ?int $createdBy = null
    ): array {
        self::ensureSchema();
        $street = is_array($vision['street_condition'] ?? null) ? $vision['street_condition'] : [];
        $green = is_array($vision['green_surfaces'] ?? null) ? $vision['green_surfaces'] : [];
        $veg = null;
        if (isset($green['vegetation_pct']) && is_numeric($green['vegetation_pct'])) {
            $veg = (float)$green['vegetation_pct'];
        } elseif (isset($green['vegetation']) && is_numeric($green['vegetation'])) {
            $veg = (float)$green['vegetation'];
        }

        $severity = (string)($vision['urgency_level'] ?? $vision['hazard_level'] ?? '');
        $streetCond = null;
        if (!empty($street['condition'])) {
            $streetCond = mb_substr((string)$street['condition'], 0, 32);
        } elseif (!empty($street['state'])) {
            $streetCond = mb_substr((string)$street['state'], 0, 32);
        } elseif (is_string($vision['street_condition'] ?? null) && trim((string)$vision['street_condition']) !== '') {
            $streetCond = mb_substr(trim((string)$vision['street_condition']), 0, 32);
        }
        if ($severity === '' && $streetCond) {
            $cond = strtolower($streetCond);
            if (in_array($cond, ['poor', 'critical', 'bad'], true)) {
                $severity = 'high';
            } elseif (in_array($cond, ['fair', 'medium'], true)) {
                $severity = 'medium';
            } else {
                $severity = 'low';
            }
        }
        $severity = in_array($severity, ['high', 'medium', 'low', 'critical'], true) ? $severity : 'medium';

        $json = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $enc === false ? null : $enc;
        };

        try {
            $stmt = db()->prepare("
                INSERT INTO urban_observations
                  (authority_id, source, lat, lng, image_path, street_condition, severity, category,
                   vegetation_pct, scene_summary, recommended_action,
                   wow_highlights_json, trees_json, green_surfaces_json, street_issues_json,
                   output_json, confidence_score, created_by)
                VALUES
                  (:aid, :src, :lat, :lng, :img, :sc, :sev, :cat,
                   :veg, :sum, :act,
                   :wow, :trees, :green, :issues,
                   :out, :conf, :uid)
            ");
            $stmt->execute([
                ':aid' => $authorityId,
                ':src' => mb_substr($source, 0, 32),
                ':lat' => $lat,
                ':lng' => $lng,
                ':img' => $imagePath !== null ? mb_substr($imagePath, 0, 512) : null,
                ':sc' => $streetCond,
                ':sev' => mb_substr($severity, 0, 16),
                ':cat' => isset($vision['suggested_category']) ? mb_substr((string)$vision['suggested_category'], 0, 64) : null,
                ':veg' => $veg,
                ':sum' => isset($vision['scene_summary']) ? mb_substr((string)$vision['scene_summary'], 0, 4000)
                    : (isset($vision['description']) ? mb_substr((string)$vision['description'], 0, 4000) : null),
                ':act' => isset($vision['recommended_action']) ? mb_substr((string)$vision['recommended_action'], 0, 2000) : null,
                ':wow' => $json($vision['wow_highlights'] ?? null),
                ':trees' => $json($vision['trees'] ?? null),
                ':green' => $json($green ?: null),
                ':issues' => $json($vision['street_issues'] ?? $vision['objects'] ?? null),
                ':out' => $json($vision),
                ':conf' => isset($vision['confidence_score']) && is_numeric($vision['confidence_score'])
                    ? (float)$vision['confidence_score'] : null,
                ':uid' => $createdBy,
            ]);
            return ['ok' => true, 'id' => (int)db()->lastInsertId()];
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('UrbanObservationService::save: ' . $e->getMessage());
            }
            return ['ok' => false, 'id' => null, 'error' => 'save_failed'];
        }
    }

    /**
     * @param list<int> $authorityIds empty = none (caller decides)
     * @return list<array<string,mixed>>
     */
    public function listRecent(array $authorityIds, int $limit = 40, ?string $source = null): array
    {
        self::ensureSchema();
        $limit = max(1, min(100, $limit));
        $authorityIds = array_values(array_filter(array_map('intval', $authorityIds), static fn ($x) => $x > 0));
        try {
            if (empty($authorityIds)) {
                $sql = "SELECT id, authority_id, source, lat, lng, image_path, street_condition, severity, category,
                           vegetation_pct, scene_summary, recommended_action, wow_highlights_json, trees_json,
                           confidence_score, created_at
                    FROM urban_observations";
                $params = [];
                if ($source) {
                    $sql .= " WHERE source = ?";
                    $params[] = $source;
                }
                $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
                $st = db()->prepare($sql);
                $st->execute($params);
            } else {
                $in = implode(',', array_fill(0, count($authorityIds), '?'));
                $sql = "SELECT id, authority_id, source, lat, lng, image_path, street_condition, severity, category,
                           vegetation_pct, scene_summary, recommended_action, wow_highlights_json, trees_json,
                           confidence_score, created_at
                    FROM urban_observations
                    WHERE authority_id IN ($in)";
                $params = $authorityIds;
                if ($source) {
                    $sql .= " AND source = ?";
                    $params[] = $source;
                }
                $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
                $st = db()->prepare($sql);
                $st->execute($params);
            }
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                foreach (['wow_highlights_json', 'trees_json'] as $jk) {
                    if (!empty($r[$jk]) && is_string($r[$jk])) {
                        $dec = json_decode($r[$jk], true);
                        $r[str_replace('_json', '', $jk)] = is_array($dec) ? $dec : [];
                    }
                }
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Best-effort: vision trees → tree_logs note (nem módosít trees.health_status automatikusan geo nélkül).
     * Ha van report_id: ai_category/ai_priority már a report_upload-ban kezelve.
     *
     * @param array<string,mixed> $vision
     */
    public function writeBackHints(array $vision, ?int $authorityId): array
    {
        $notes = [];
        $trees = $vision['trees'] ?? [];
        if (!is_array($trees) || empty($trees)) {
            return ['ok' => true, 'notes' => ['no_trees']];
        }
        // Ne találjunk ki fa ID-t geo nélkül – csak naplózzuk megfigyelésként (már save-elve).
        $notes[] = 'trees_stored_in_observation';
        $notes[] = 'count=' . count($trees);
        if ($authorityId) {
            $notes[] = 'authority=' . $authorityId;
        }
        return ['ok' => true, 'notes' => $notes];
    }
}
