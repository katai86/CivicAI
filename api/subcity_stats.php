<?php
/**
 * M12 – Zone distribution (sub-municipal or district) for gov scope; new reports in last N days.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExecutiveSummaryService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role() ?: '';
$uid = current_user_id() ? (int)current_user_id() : 0;
$adminAid = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $a = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $adminAid = $a > 0 ? $a : null;
}

$days = isset($_GET['days']) ? (int)$_GET['days'] : 90;
$days = max(7, min(365, $days));

$pdo = db();
[$reportWhere, $reportParams, , $healthAuthorityId] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

$useSubcity = admin_subdivision_analytics_use_subcity($healthAuthorityId);
$zoneExpr = $useSubcity
  ? "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.admin_subdivision_json, '\$.subcity_name'))), ''), NULLIF(TRIM(r.suburb), ''), NULLIF(TRIM(r.city), ''), '—')"
  : "COALESCE(NULLIF(TRIM(r.suburb), ''), NULLIF(TRIM(r.city), ''), '—')";

$rows = [];
try {
  $sql = "
    SELECT $zoneExpr AS zone_name, COUNT(*) AS cnt
    FROM reports r
    WHERE ($reportWhere) AND r.created_at >= (NOW() - INTERVAL $days DAY)
    GROUP BY zone_name
    ORDER BY cnt DESC
    LIMIT 24
  ";
  $st = $pdo->prepare($sql);
  $st->execute($reportParams);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = [
      'zone' => (string)($row['zone_name'] ?? ''),
      'count' => (int)($row['cnt'] ?? 0),
    ];
  }
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('subcity_stats: ' . $e->getMessage());
  }
}

$total = 0;
foreach ($rows as $r) {
  $total += $r['count'];
}

json_response([
  'ok' => true,
  'data' => [
    'days' => $days,
    'zone_mode' => $useSubcity ? 'subcity' : 'district',
    'total' => $total,
    'by_zone' => $rows,
    'i18n' => [
      'title' => $useSubcity ? t('gov.zone_chart_title_subcity') : t('gov.zone_chart_title_district'),
      'desc' => t('gov.zone_chart_desc'),
      'empty' => t('gov.zone_chart_empty'),
      'series_label' => t('gov.zone_chart_series_label'),
    ],
  ],
]);
