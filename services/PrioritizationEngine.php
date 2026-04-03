<?php
/**
 * Rule-based backlog prioritisation for gov scope: open reports by category and zone (subcity/suburb/city).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

final class PrioritizationEngine
{
    private const OPEN_STATUSES = "'new','approved','needs_info','forwarded','waiting_reply','in_progress','pending'";

    /**
     * @return array{by_category: list<array>, by_zone: list<array>, totals: array{open_reports:int}, meta: array}
     */
    public function compute(PDO $pdo, string $reportWhere, array $reportParams, ?int $primaryAuthorityId): array
    {
        $open = self::OPEN_STATUSES;
        $out = [
            'by_category' => [],
            'by_zone' => [],
            'totals' => ['open_reports' => 0],
            'meta' => [
                'zone_mode' => 'district',
            ],
        ];

        try {
            $sql = "
              SELECT r.category AS category,
                     COUNT(*) AS open_count,
                     AVG(DATEDIFF(CURDATE(), DATE(r.created_at))) AS avg_age_days,
                     MAX(DATEDIFF(CURDATE(), DATE(r.created_at))) AS max_age_days
              FROM reports r
              WHERE ($reportWhere) AND r.status IN ($open)
              GROUP BY r.category
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($reportParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }

        $totalOpen = 0;
        $items = [];
        foreach ($rows as $row) {
            $cnt = (int)($row['open_count'] ?? 0);
            if ($cnt <= 0) {
                continue;
            }
            $totalOpen += $cnt;
            $avgAge = (float)($row['avg_age_days'] ?? 0);
            $maxAge = (int)($row['max_age_days'] ?? 0);
            $items[] = [
                'category' => (string)($row['category'] ?? ''),
                'open_count' => $cnt,
                'avg_age_days' => round($avgAge, 1),
                'max_age_days' => $maxAge,
                'priority_score' => self::scoreRow($cnt, $avgAge),
            ];
        }
        usort($items, static function ($a, $b) {
            return ($b['priority_score'] <=> $a['priority_score']);
        });
        $rank = 1;
        foreach ($items as &$it) {
            $it['rank'] = $rank++;
        }
        unset($it);
        $out['by_category'] = array_slice($items, 0, 12);
        $out['totals']['open_reports'] = $totalOpen;

        $useSubcity = admin_subdivision_analytics_use_subcity($primaryAuthorityId);
        if ($useSubcity && !db_table_has_column($pdo, 'reports', 'admin_subdivision_json')) {
            $useSubcity = false;
        }
        if ($useSubcity) {
            $out['meta']['zone_mode'] = 'subcity';
            $zoneExpr = "COALESCE(
              NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.admin_subdivision_json, '\$.subcity_name'))), ''),
              NULLIF(TRIM(r.suburb), ''),
              NULLIF(TRIM(r.city), ''),
              '—'
            )";
        } else {
            $zoneExpr = "COALESCE(NULLIF(TRIM(r.suburb), ''), NULLIF(TRIM(r.city), ''), '—')";
        }

        try {
            $sqlZ = "
              SELECT $zoneExpr AS zone,
                     COUNT(*) AS open_count,
                     AVG(DATEDIFF(CURDATE(), DATE(r.created_at))) AS avg_age_days
              FROM reports r
              WHERE ($reportWhere) AND r.status IN ($open)
              GROUP BY zone
              ORDER BY open_count DESC
              LIMIT 24
            ";
            $stz = $pdo->prepare($sqlZ);
            $stz->execute($reportParams);
            $zrows = $stz->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $zrows = [];
        }

        $zones = [];
        foreach ($zrows as $zr) {
            $zc = (int)($zr['open_count'] ?? 0);
            if ($zc <= 0) {
                continue;
            }
            $zavg = (float)($zr['avg_age_days'] ?? 0);
            $zones[] = [
                'zone' => (string)($zr['zone'] ?? ''),
                'open_count' => $zc,
                'avg_age_days' => round($zavg, 1),
                'priority_score' => self::scoreRow($zc, $zavg),
            ];
        }
        usort($zones, static function ($a, $b) {
            return ($b['priority_score'] <=> $a['priority_score']);
        });
        $r = 1;
        foreach ($zones as &$z) {
            $z['rank'] = $r++;
        }
        unset($z);
        $out['by_zone'] = array_slice($zones, 0, 12);

        return $out;
    }

    private static function scoreRow(int $openCount, float $avgAgeDays): float
    {
        $ageFactor = 1.0 + min(3.0, $avgAgeDays / 21.0);

        return round($openCount * 10.0 * $ageFactor, 1);
    }
}
