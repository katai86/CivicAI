<?php
/**
 * M5 – Urban Prediction Engine.
 * Szabályalapú: történeti reportok térbeli klaszterei = predicted_issues / risk_zones; fák egészség = predicted_tree_failures.
 */
require_once __DIR__ . '/../db.php';

class UrbanPredictionEngine
{
    /** category -> risk zone type */
    private const CATEGORY_TO_TYPE = [
        'road' => 'pothole',
        'sidewalk' => 'pothole',
        'trash' => 'waste',
        'lighting' => 'lighting',
        'green' => 'tree_health',
    ];

    /**
     * @param string $reportWhere SQL WHERE for reports (e.g. "r.authority_id IN (1,2)")
     * @param array $reportParams
     * @param array $typesFilter optional: ['pothole','waste','lighting','tree_health'] – empty = all
     */
    public function predict(string $reportWhere, array $reportParams, array $typesFilter = []): array
    {
        $out = [
            'predicted_issues' => [],
            'risk_zones' => [],
            'predicted_tree_failures' => [],
        ];

        $pdo = db();
        $since = date('Y-m-d H:i:s', strtotime('-90 days'));

        $categoriesForTypes = $this->categoriesForTypes($typesFilter);

        try {
            $stmt = $pdo->prepare("
              SELECT r.category, r.lat, r.lng
              FROM reports r
              WHERE $reportWhere AND r.created_at >= ? AND r.lat IS NOT NULL AND r.lng IS NOT NULL
              LIMIT 2000
            ");
            $stmt->execute(array_merge($reportParams, [$since]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }

        $grid = [];
        foreach ($rows as $r) {
            $cat = (string)($r['category'] ?? '');
            if ($categoriesForTypes !== null && !in_array($cat, $categoriesForTypes, true)) {
                continue;
            }
            $lat = (float)$r['lat'];
            $lng = (float)$r['lng'];
            $cell = round($lat * 100) . '_' . round($lng * 100);
            if (!isset($grid[$cell])) {
                $grid[$cell] = ['lat' => $lat, 'lng' => $lng, 'categories' => [], 'count' => 0];
            }
            $grid[$cell]['count']++;
            if (!isset($grid[$cell]['categories'][$cat])) {
                $grid[$cell]['categories'][$cat] = 0;
            }
            $grid[$cell]['categories'][$cat]++;
        }

        foreach ($grid as $cell => $data) {
            if ($data['count'] < 2) {
                continue;
            }
            $riskLevel = $data['count'] >= 5 ? 'high' : ($data['count'] >= 3 ? 'medium' : 'low');
            $score = min(1.0, 0.3 + $data['count'] * 0.15);
            $topCat = '';
            $topCnt = 0;
            foreach ($data['categories'] as $c => $cnt) {
                if ($cnt > $topCnt) {
                    $topCnt = $cnt;
                    $topCat = $c;
                }
            }
            if ($topCat !== '') {
                $type = self::CATEGORY_TO_TYPE[$topCat] ?? $topCat;
                if ($typesFilter === [] || in_array($type, $typesFilter, true)) {
                    $out['predicted_issues'][] = [
                        'category' => $topCat,
                        'lat' => round($data['lat'], 5),
                        'lng' => round($data['lng'], 5),
                        'risk_level' => $riskLevel,
                    ];
                    $out['risk_zones'][] = [
                        'type' => $type,
                        'polygon_or_bounds' => round($data['lat'], 4) . ',' . round($data['lng'], 4) . ',0.01',
                        'score' => round($score, 2),
                    ];
                }
            }
        }

        $out['predicted_issues'] = array_slice($out['predicted_issues'], 0, 30);
        $out['risk_zones'] = array_slice($out['risk_zones'], 0, 20);

        if ($typesFilter === [] || in_array('tree_health', $typesFilter, true)) {
            try {
                $treeStmt = $pdo->query("
                  SELECT id, lat, lng, risk_level, health_status, last_watered
                  FROM trees
                  WHERE public_visible = 1 AND lat IS NOT NULL AND lng IS NOT NULL
                  AND (risk_level IN ('high','medium') OR (health_status IN ('poor','critical') AND (last_watered IS NULL OR last_watered < DATE_SUB(CURDATE(), INTERVAL 14 DAY))))
                  LIMIT 50
                ");
                while ($row = $treeStmt->fetch(PDO::FETCH_ASSOC)) {
                    $risk = (string)($row['risk_level'] ?? 'medium');
                    if ($risk === '' && (in_array($row['health_status'] ?? '', ['poor', 'critical'], true)) {
                        $risk = 'medium';
                    }
                    $out['predicted_tree_failures'][] = [
                        'tree_id' => (int)$row['id'],
                        'lat' => round((float)$row['lat'], 5),
                        'lng' => round((float)$row['lng'], 5),
                        'risk' => $risk ?: 'medium',
                    ];
                }
            } catch (Throwable $e) {}
        }

        return $out;
    }

    private function categoriesForTypes(array $typesFilter): ?array
    {
        if ($typesFilter === []) {
            return null;
        }
        $rev = array_flip(self::CATEGORY_TO_TYPE);
        $cats = [];
        foreach ($typesFilter as $t) {
            foreach (self::CATEGORY_TO_TYPE as $cat => $type) {
                if ($type === $t) {
                    $cats[] = $cat;
                }
            }
        }
        return $cats ?: null;
    }
}
