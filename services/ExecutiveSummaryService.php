<?php
/**
 * Executive summary aggregation (Milestone 1) – reuses CityHealthScore, GreenIntelligence,
 * gov_compute_esg_snapshot, UrbanPredictionEngine. No duplicate SQL beyond thin wrappers.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/CityHealthScore.php';
require_once __DIR__ . '/GreenIntelligence.php';
require_once __DIR__ . '/UrbanPredictionEngine.php';

final class ExecutiveSummaryService
{
    /**
     * @return array{reportWhere:string,reportParams:array,treeScopeIds:array<int,int>,healthAuthorityId:?int}
     */
    public static function resolveScopes(PDO $pdo, string $role, int $uid, ?int $adminRequestedAuthorityId): array
    {
        $authorityIds = [];
        $authorityCities = [];

        if (in_array($role, ['admin', 'superadmin'], true)) {
            if ($adminRequestedAuthorityId !== null && $adminRequestedAuthorityId > 0) {
                $authorityIds = [$adminRequestedAuthorityId];
            } else {
                try {
                    $rows = $pdo->query('SELECT id FROM authorities ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
                    $authorityIds = array_values(array_filter(array_map('intval', $rows ?: []), static fn ($x) => $x > 0));
                } catch (Throwable $e) {
                    $authorityIds = [];
                }
            }
        } else {
            try {
                $stmt = $pdo->prepare('
                  SELECT a.id, a.city FROM authority_users au
                  INNER JOIN authorities a ON a.id = au.authority_id
                  WHERE au.user_id = ?
                  ORDER BY a.id ASC
                ');
                $stmt->execute([$uid]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) {
                        $authorityIds[] = $id;
                    }
                    $ct = trim((string)($row['city'] ?? ''));
                    if ($ct !== '') {
                        $authorityCities[] = $ct;
                    }
                }
            } catch (Throwable $e) {
                $authorityIds = [];
            }
            $authorityIds = array_values(array_unique($authorityIds));
            $authorityCities = array_values(array_unique(array_filter($authorityCities)));
        }

        $reportWhere = '1=1';
        $reportParams = [];

        if (in_array($role, ['admin', 'superadmin'], true)) {
            if (!empty($authorityIds)) {
                $reportWhere = 'r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')';
                $reportParams = $authorityIds;
            }
        } else {
            if (empty($authorityIds)) {
                $reportWhere = '1=0';
            } else {
                $reportWhere = 'r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')';
                $reportParams = $authorityIds;
                if (!empty($authorityCities)) {
                    $reportWhere .= ' OR (r.authority_id IS NULL AND r.city IN (' . implode(',', array_fill(0, count($authorityCities), '?')) . '))';
                    $reportParams = array_merge($reportParams, $authorityCities);
                }
            }
        }

        $treeScopeIds = [];
        if ($adminRequestedAuthorityId !== null && $adminRequestedAuthorityId > 0 && in_array($role, ['admin', 'superadmin'], true)) {
            $treeScopeIds = [$adminRequestedAuthorityId];
        } elseif (!empty($authorityIds)) {
            $treeScopeIds = $authorityIds;
        }

        $healthAuthorityId = null;
        if (in_array($role, ['admin', 'superadmin'], true)) {
            if ($adminRequestedAuthorityId !== null && $adminRequestedAuthorityId > 0) {
                $healthAuthorityId = $adminRequestedAuthorityId;
            }
        } else {
            $healthAuthorityId = !empty($authorityIds) ? (int)$authorityIds[0] : null;
        }

        return [$reportWhere, $reportParams, $treeScopeIds, $healthAuthorityId];
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $role, int $uid, ?int $adminRequestedAuthorityId): array
    {
        $pdo = db();
        [$reportWhere, $reportParams, $treeScopeIds, $healthAid] = self::resolveScopes($pdo, $role, $uid, $adminRequestedAuthorityId);

        $health = (new CityHealthScore())->compute($healthAid);
        $green = (new GreenIntelligence())->compute($healthAid);
        $esg = gov_compute_esg_snapshot($pdo, $treeScopeIds, $reportWhere, $reportParams);
        $pred = (new UrbanPredictionEngine())->predict($reportWhere, $reportParams, []);

        $gov = $esg['governance'] ?? [];
        $env = $esg['environment'] ?? [];

        $openIssues = (int)($gov['reports_open'] ?? 0);
        $resolved30 = (int)($gov['reports_solved_30d'] ?? 0);
        $avgRes = $gov['avg_resolution_days'] ?? null;
        $avgResolution = ($avgRes !== null && is_numeric($avgRes)) ? round((float)$avgRes, 1) : null;

        $cityHealth = (int)max(0, min(100, (int)($health['city_health_score'] ?? 50)));
        $engagement = (int)max(0, min(100, (int)($health['engagement_score'] ?? 50)));

        $drought = isset($green['drought_risk']) ? (float)$green['drought_risk'] : 0.0;
        $drought = max(0.0, min(1.0, $drought));
        $climateRisk = (int)round(100 * $drought);

        $deficitRaw = isset($green['green_deficit_score']) ? (float)$green['green_deficit_score'] : 0.0;
        $deficitRaw = max(0.0, min(1.0, $deficitRaw));
        $greenDeficit = (int)round(100 * $deficitRaw);

        $trend = self::inferTrend($openIssues, $resolved30, (float)($health['maintenance_score'] ?? 50), (float)($health['infrastructure_score'] ?? 50));

        $topRisks = self::buildTopRisks($pred, $env);
        $topZones = self::buildTopZones($pred);

        $aiSummary = self::buildAiSummary($cityHealth, $trend, $openIssues, $resolved30, $climateRisk, $greenDeficit, $engagement);

        return [
            'city_health_score' => $cityHealth,
            'trend' => $trend,
            'open_issues' => $openIssues,
            'resolved_last_30_days' => $resolved30,
            'avg_resolution_time' => $avgResolution,
            'citizen_engagement_score' => $engagement,
            'climate_risk_score' => $climateRisk,
            'green_deficit_score' => $greenDeficit,
            'top_risks' => $topRisks,
            'top_priority_zones' => $topZones,
            'ai_summary' => $aiSummary,
        ];
    }

    private static function inferTrend(int $open, int $resolved30, float $maintenance, float $infra): string
    {
        if ($open > 0 && $resolved30 > $open * 0.35) {
            return 'improving';
        }
        if ($open > 80 || ($open > 35 && $resolved30 < 4) || ($maintenance < 38 && $infra < 40)) {
            return 'declining';
        }
        return 'stable';
    }

    /**
     * @param array<string,mixed> $pred
     * @param array<string,mixed> $env
     * @return list<array<string,mixed>>
     */
    private static function buildTopRisks(array $pred, array $env): array
    {
        $out = [];
        $needWater = (int)($env['trees_needing_water'] ?? 0);
        $danger = (int)($env['trees_dangerous'] ?? 0);
        if ($danger > 0) {
            $out[] = [
                'type' => 'tree_risk',
                'severity' => 'high',
                'code' => 'trees_dangerous',
                'count' => $danger,
            ];
        }
        if ($needWater > 3) {
            $out[] = [
                'type' => 'tree_water',
                'severity' => $needWater > 15 ? 'high' : 'medium',
                'code' => 'trees_needing_water',
                'count' => $needWater,
            ];
        }
        foreach ($pred['predicted_issues'] ?? [] as $pi) {
            if (!is_array($pi)) {
                continue;
            }
            $lvl = (string)($pi['risk_level'] ?? '');
            if ($lvl !== 'high' && $lvl !== 'medium') {
                continue;
            }
            $out[] = [
                'type' => 'issue_cluster',
                'severity' => $lvl,
                'code' => 'predicted_issue',
                'category' => (string)($pi['category'] ?? ''),
                'lat' => isset($pi['lat']) ? (float)$pi['lat'] : null,
                'lng' => isset($pi['lng']) ? (float)$pi['lng'] : null,
            ];
            if (count($out) >= 5) {
                break;
            }
        }
        foreach ($pred['predicted_tree_failures'] ?? [] as $tf) {
            if (count($out) >= 5) {
                break;
            }
            if (!is_array($tf)) {
                continue;
            }
            $out[] = [
                'type' => 'tree_failure',
                'severity' => (string)($tf['risk'] ?? 'medium'),
                'code' => 'predicted_tree_failure',
                'tree_id' => isset($tf['tree_id']) ? (int)$tf['tree_id'] : null,
            ];
        }
        return array_slice(array_values($out), 0, 5);
    }

    /**
     * @param array<string,mixed> $pred
     * @return list<array<string,mixed>>
     */
    private static function buildTopZones(array $pred): array
    {
        $zones = $pred['risk_zones'] ?? [];
        if (!is_array($zones)) {
            return [];
        }
        usort($zones, static function ($a, $b) {
            $sa = is_array($a) ? (float)($a['score'] ?? 0) : 0.0;
            $sb = is_array($b) ? (float)($b['score'] ?? 0) : 0.0;
            return $sb <=> $sa;
        });
        $slice = array_slice($zones, 0, 5);
        $out = [];
        foreach ($slice as $z) {
            if (!is_array($z)) {
                continue;
            }
            $out[] = [
                'type' => (string)($z['type'] ?? ''),
                'score' => isset($z['score']) ? round((float)$z['score'], 2) : null,
                'bounds_hint' => (string)($z['polygon_or_bounds'] ?? ''),
            ];
        }
        return $out;
    }

    private static function buildAiSummary(
        int $health,
        string $trend,
        int $open,
        int $resolved30,
        int $climateRisk,
        int $greenDeficit,
        int $engagement
    ): string {
        $trendKey = 'executive.trend.' . $trend;
        $trendLabel = t($trendKey);
        if ($trendLabel === $trendKey) {
            $trendLabel = $trend;
        }
        $s1 = str_replace([':health', ':trend'], [(string)$health, $trendLabel], t('executive.ai_sentence_health'));
        $s2 = str_replace([':open', ':resolved'], [(string)$open, (string)$resolved30], t('executive.ai_sentence_ops'));
        $s3 = str_replace([':climate', ':deficit', ':engagement'], [(string)$climateRisk, (string)$greenDeficit, (string)$engagement], t('executive.ai_sentence_green'));
        return trim($s1 . ' ' . $s2 . ' ' . $s3);
    }
}
