<?php
/**
 * M3 – Unified priority engine 0–100 (reports + trees + observations).
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../PrioritizationEngine.php';

final class UnifiedPriorityEngine
{
    /** @return array<int,array<string,mixed>> */
    public function computeForAuthority(int $authorityId, int $limit = 50): array
    {
        $items = [];
        $this->addReports($authorityId, $items);
        $this->addTrees($authorityId, $items);
        $this->addObservations($authorityId, $items);
        usort($items, static fn($a, $b) => ($b['priority_score'] ?? 0) <=> ($a['priority_score'] ?? 0));
        $items = array_slice($items, 0, $limit);
        $this->persist($authorityId, $items);
        return $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function addReports(int $authorityId, array &$items): void
    {
        try {
            $pdo = db();
            $where = 'r.authority_id = ?';
            $params = [$authorityId];
            $prio = new PrioritizationEngine();
            $result = $prio->compute($pdo, $where, $params, $authorityId);
            foreach ($result['by_category'] ?? [] as $r) {
                $score = min(100.0, max(0.0, (float)($r['priority_score'] ?? 50)));
                $items[] = [
                    'entity_type' => 'report_category',
                    'entity_id' => crc32((string)($r['category'] ?? '')),
                    'priority_score' => $score,
                    'factors' => ['category' => $r['category'] ?? '', 'open_count' => $r['open_count'] ?? 0],
                ];
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('UnifiedPriorityEngine reports: ' . $e->getMessage());
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function addTrees(int $authorityId, array &$items): void
    {
        try {
            $st = db()->prepare("
                SELECT id, health_status, risk_level, lat, lng FROM trees
                WHERE authority_id = ? AND public_visible = 1
                  AND (health_status IN ('stressed','diseased') OR risk_level IN ('medium','high'))
                LIMIT 100
            ");
            $st->execute([$authorityId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $score = 40.0;
                if (($row['risk_level'] ?? '') === 'high') $score += 35;
                elseif (($row['risk_level'] ?? '') === 'medium') $score += 20;
                if (($row['health_status'] ?? '') === 'diseased') $score += 25;
                elseif (($row['health_status'] ?? '') === 'stressed') $score += 15;
                $lat = isset($row['lat']) ? (float)$row['lat'] : null;
                $lng = isset($row['lng']) ? (float)$row['lng'] : null;
                $items[] = [
                    'entity_type' => 'tree',
                    'entity_id' => (int)$row['id'],
                    'priority_score' => min(100.0, $score),
                    'lat' => $lat,
                    'lng' => $lng,
                    'factors' => [
                        'health' => $row['health_status'],
                        'risk' => $row['risk_level'],
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                ];
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('UnifiedPriorityEngine trees: ' . $e->getMessage());
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function addObservations(int $authorityId, array &$items): void
    {
        try {
            if (!function_exists('db_table_has_column') || !db_table_has_column(db(), 'city_observations', 'value_num')) {
                return;
            }
            $st = db()->prepare("
                SELECT id, indicator_type, value_num, confidence, lat, lng FROM city_observations
                WHERE authority_id = ? AND observed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND (indicator_type LIKE '%anomaly%' OR indicator_type LIKE '%risk%')
                ORDER BY value_num DESC LIMIT 20
            ");
            $st->execute([$authorityId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $val = (float)($row['value_num'] ?? 0);
                $lat = isset($row['lat']) ? (float)$row['lat'] : null;
                $lng = isset($row['lng']) ? (float)$row['lng'] : null;
                $items[] = [
                    'entity_type' => 'observation',
                    'entity_id' => (int)$row['id'],
                    'priority_score' => min(100.0, $val),
                    'lat' => $lat,
                    'lng' => $lng,
                    'factors' => [
                        'indicator' => $row['indicator_type'],
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                ];
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('UnifiedPriorityEngine obs: ' . $e->getMessage());
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function persist(int $authorityId, array $items): void
    {
        try {
            if (!function_exists('db_table_has_column') || !db_table_has_column(db(), 'city_priorities', 'entity_type')) {
                return;
            }
            $pdo = db();
            foreach ($items as $it) {
                $pdo->prepare('
                    INSERT INTO city_priorities (authority_id, entity_type, entity_id, priority_score, factors_json)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE priority_score = VALUES(priority_score), factors_json = VALUES(factors_json), computed_at = NOW()
                ')->execute([
                    $authorityId,
                    $it['entity_type'],
                    $it['entity_id'],
                    $it['priority_score'],
                    json_encode($it['factors'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('UnifiedPriorityEngine persist: ' . $e->getMessage());
        }
    }
}
