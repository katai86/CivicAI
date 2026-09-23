<?php
/**
 * M24 – Append-only tree inspection persistence.
 */
require_once __DIR__ . '/../../db.php';

final class TreeInspectionStore
{
    /**
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $context
     */
    public function saveInspection(string $imagePath, string $mime, array $analysis, array $context): ?int
    {
        try {
            $pdo = db();
            if (!function_exists('db_table_has_column') || !db_table_has_column($pdo, 'tree_inspections', 'id')) {
                return null;
            }
            $relPath = $this->relativeUploadPath($imagePath);
            $st = $pdo->prepare('
                INSERT INTO tree_inspections
                  (tree_id, authority_id, session_id, image_paths_json, species_candidates_json, species_consensus,
                   visual_observations_json, segmentation_json, measurements_json,
                   health_label, health_score, risk_level, confidence_json, failure_state,
                   model_metadata_json, evidence_json, lat, lng, created_at)
                VALUES
                  (:tree_id, :authority_id, :session_id, :image_paths, :species_candidates, :species_consensus,
                   :visual_observations, :segmentation, :measurements,
                   :health_label, :health_score, :risk_level, :confidence, :failure_state,
                   :model_metadata, :evidence, :lat, :lng, NOW())
            ');
            $health = is_array($analysis['health'] ?? null) ? $analysis['health'] : [];
            $risk = is_array($analysis['risk'] ?? null) ? $analysis['risk'] : [];
            $consensus = is_array($analysis['species_consensus'] ?? null) ? $analysis['species_consensus'] : [];
            $st->execute([
                ':tree_id' => isset($context['tree_id']) ? (int)$context['tree_id'] : null,
                ':authority_id' => isset($context['authority_id']) ? (int)$context['authority_id'] : null,
                ':session_id' => isset($context['session_id']) ? (string)$context['session_id'] : null,
                ':image_paths' => json_encode([$relPath], JSON_UNESCAPED_UNICODE),
                ':species_candidates' => json_encode($analysis['species']['candidates'] ?? [], JSON_UNESCAPED_UNICODE),
                ':species_consensus' => json_encode($consensus, JSON_UNESCAPED_UNICODE),
                ':visual_observations' => json_encode($analysis['condition']['observations'] ?? [], JSON_UNESCAPED_UNICODE),
                ':segmentation' => json_encode(['note' => $analysis['segmentation']['segmentation_note'] ?? ''], JSON_UNESCAPED_UNICODE),
                ':measurements' => json_encode($analysis['segmentation']['measurements'] ?? [], JSON_UNESCAPED_UNICODE),
                ':health_label' => (string)($health['health_label'] ?? ''),
                ':health_score' => isset($health['health_score']) ? (float)$health['health_score'] : null,
                ':risk_level' => (string)($risk['risk_level'] ?? ''),
                ':confidence' => json_encode(['label' => $analysis['fusion']['confidence_label'] ?? 'UNKNOWN'], JSON_UNESCAPED_UNICODE),
                ':failure_state' => (string)($analysis['status'] ?? 'SUCCESS'),
                ':model_metadata' => json_encode(['pipeline' => $analysis['pipeline_version'] ?? 'plant-cloud-1.0'], JSON_UNESCAPED_UNICODE),
                ':evidence' => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                ':lat' => isset($context['lat']) ? (float)$context['lat'] : null,
                ':lng' => isset($context['lng']) ? (float)$context['lng'] : null,
            ]);
            $id = (int)$pdo->lastInsertId();
            if (!empty($context['tree_id']) && $id > 0) {
                $this->updateTreeFromInspection((int)$context['tree_id'], $health, $risk, $consensus);
            }
            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('TreeInspectionStore::saveInspection: ' . $e->getMessage());
            }
            return null;
        }
    }

    /** @param array<string,mixed> $health @param array<string,mixed> $risk @param array<string,mixed> $consensus */
    private function updateTreeFromInspection(int $treeId, array $health, array $risk, array $consensus): void
    {
        try {
            $pdo = db();
            $species = null;
            if (is_array($consensus['consensus'] ?? null)) {
                $species = (string)($consensus['consensus']['scientific_name'] ?? '');
            }
            $healthStatus = strtolower((string)($health['health_label'] ?? ''));
            $riskLevel = strtolower((string)($risk['risk_level'] ?? ''));
            $mapHealth = ['healthy' => 'healthy', 'stressed' => 'stressed', 'declining' => 'stressed', 'critical' => 'diseased'];
            $mapRisk = ['none' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'];
            $hs = $mapHealth[strtolower($healthStatus)] ?? null;
            $rl = $mapRisk[strtolower($riskLevel)] ?? null;
            $sql = 'UPDATE trees SET updated_at = NOW()';
            $params = [];
            if ($hs !== null) { $sql .= ', health_status = ?'; $params[] = $hs; }
            if ($rl !== null) { $sql .= ', risk_level = ?'; $params[] = $rl; }
            if ($species !== null && $species !== '') { $sql .= ', species = ?'; $params[] = $species; }
            $sql .= ' WHERE id = ? LIMIT 1';
            $params[] = $treeId;
            if (count($params) > 1) {
                $pdo->prepare($sql)->execute($params);
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('TreeInspectionStore::updateTreeFromInspection: ' . $e->getMessage());
            }
        }
    }

    private function relativeUploadPath(string $absPath): string
    {
        $uploadDir = defined('UPLOAD_DIR') ? rtrim((string)UPLOAD_DIR, '/\\') : '';
        if ($uploadDir !== '' && str_starts_with($absPath, $uploadDir)) {
            return ltrim(substr($absPath, strlen($uploadDir)), '/\\');
        }
        return basename($absPath);
    }
}
