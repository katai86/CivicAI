<?php
/**
 * M3 – City Health Score.
 * Egy 0–100-as index (overall + infrastructure, environment, engagement, maintenance).
 * Szabályalapú számítás; opcionálisan AI kiegészítheti (később).
 */
require_once __DIR__ . '/../db.php';

class CityHealthScore
{
    /** authority_id = null vagy 0: összes adat; >0: csak az adott hatóság reportjai (fák globális) */
    public function compute(?int $authorityId = null): array
    {
        $pdo = db();
        $out = [
            'city_health_score' => 50,
            'infrastructure_score' => 50,
            'environment_score' => 50,
            'engagement_score' => 50,
            'maintenance_score' => 50,
        ];

        $reportWhere = '1=1';
        $reportParams = [];
        if ($authorityId > 0) {
            $reportWhere = 'r.authority_id = ?';
            $reportParams = [$authorityId];
        }

        $openStatuses = "'new','approved','needs_info','forwarded','waiting_reply','in_progress','pending'";

        // Resolution rate (utolsó 90 nap)
        $total = 0;
        $resolved = 0;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.created_at >= (NOW() - INTERVAL 90 DAY)");
            $stmt->execute($reportParams);
            $total = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.created_at >= (NOW() - INTERVAL 90 DAY) AND r.status IN ('solved','closed')");
            $stmt->execute($reportParams);
            $resolved = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        $resolutionRate = $total > 0 ? $resolved / $total : 0.5;

        // Open issues count (backlog)
        $openCount = 0;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.status IN ($openStatuses)");
            $stmt->execute($reportParams);
            $openCount = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        // Infrastructure: resolution rate + kevés nyitott = jobb. Normálás: 0–50 open = 100, 200+ = 0; resolution 0.5–1.0 → 50–100
        $infraFromResolution = 50 + (int)round(50 * $resolutionRate);
        $infraFromBacklog = $openCount <= 20 ? 100 : ($openCount >= 100 ? 20 : 100 - (int)round(80 * ($openCount - 20) / 80));
        $out['infrastructure_score'] = (int)round(($infraFromResolution * 0.6 + $infraFromBacklog * 0.4));
        $out['infrastructure_score'] = max(0, min(100, $out['infrastructure_score']));

        // Environment: fa egészség (globális fák)
        $treeTotal = 0;
        $treeGood = 0;
        $treeRisk = 0;
        try {
            $treeTotal = (int)$pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1")->fetchColumn();
            $treeGood = (int)$pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND (health_status IN ('good','fair') OR health_status IS NULL) AND (risk_level IS NULL OR risk_level = 'low')")->fetchColumn();
            $treeRisk = (int)$pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND (risk_level = 'high' OR risk_level = 'medium')")->fetchColumn();
        } catch (Throwable $e) {}

        if ($treeTotal > 0) {
            $ratioGood = $treeGood / $treeTotal;
            $ratioRisk = $treeRisk / $treeTotal;
            $out['environment_score'] = (int)round(50 + 50 * $ratioGood - 30 * $ratioRisk);
        }
        $out['environment_score'] = max(0, min(100, $out['environment_score']));

        // Engagement: aktív user 7d, report 7d (hatósági scope)
        $active7 = 0;
        $reports7 = 0;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $reportWhere AND r.user_id IS NOT NULL AND r.user_id > 0 AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
            $stmt->execute($reportParams);
            $active7 = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
            $stmt->execute($reportParams);
            $reports7 = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        $engagementBase = 50;
        if ($active7 > 0) {
            $reportsPerUser = $reports7 / $active7;
            $engagementBase = 40 + (int)min(60, round($active7 * 2 + $reportsPerUser * 5));
        }
        $out['engagement_score'] = max(0, min(100, $engagementBase));

        // Maintenance: resolution rate + válaszidő ötlet (egyszerűsítve: resolution rate + kevés backlog)
        $avgHours = 72;
        try {
            $stmt = $pdo->prepare("
              SELECT AVG(TIMESTAMPDIFF(HOUR, r.created_at, (SELECT MIN(l.changed_at) FROM report_status_log l WHERE l.report_id = r.id AND l.new_status IN ('solved','closed')))) AS h
              FROM reports r
              WHERE $reportWhere AND r.status IN ('solved','closed')
              AND EXISTS (SELECT 1 FROM report_status_log l WHERE l.report_id = r.id AND l.new_status IN ('solved','closed'))
              LIMIT 500
            ");
            $stmt->execute($reportParams);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && $row['h'] !== null) {
                $avgHours = (float)$row['h'];
            }
        } catch (Throwable $e) {}

        $maintenanceFromRate = 50 + (int)round(50 * $resolutionRate);
        $maintenanceFromTime = $avgHours <= 24 ? 100 : ($avgHours >= 168 ? 30 : 100 - (int)round(70 * ($avgHours - 24) / 144));
        $out['maintenance_score'] = (int)round($maintenanceFromRate * 0.5 + $maintenanceFromTime * 0.5);
        $out['maintenance_score'] = max(0, min(100, $out['maintenance_score']));

        // Overall: súlyozott átlag
        $out['city_health_score'] = (int)round(
            $out['infrastructure_score'] * 0.25 +
            $out['environment_score'] * 0.25 +
            $out['engagement_score'] * 0.25 +
            $out['maintenance_score'] * 0.25
        );
        $out['city_health_score'] = max(0, min(100, $out['city_health_score']));

        return $out;
    }
}
