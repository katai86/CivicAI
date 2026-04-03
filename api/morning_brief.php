<?php
/**
 * GET – compact operational brief: last 24h activity + top backlog categories (gov/admin scope).
 * Does not duplicate executive_summary.php (lighter than full CityHealth + predictions stack).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExecutiveSummaryService.php';
require_once __DIR__ . '/../services/PrioritizationEngine.php';

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

$pdo = db();

try {
  [$reportWhere, $reportParams, $_tree, $healthAuthorityId] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

  $cacheKey = gov_api_cache_scope_key('brief', $role, $uid, $adminAid);
  $cacheHit = gov_api_cache_get($cacheKey);
  if ($cacheHit !== null) {
    header('X-Gov-Api-Cache: HIT');
    json_response($cacheHit);
  }

  $created24 = 0;
  $resolved24 = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE ($reportWhere) AND r.created_at >= (NOW() - INTERVAL 24 HOUR)");
    $st->execute($reportParams);
    $created24 = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $created24 = 0;
  }
  try {
    $st = $pdo->prepare("
    SELECT COUNT(DISTINCT l.report_id) FROM report_status_log l
    INNER JOIN reports r ON r.id = l.report_id
    WHERE ($reportWhere) AND l.new_status IN ('solved','closed')
      AND l.changed_at >= (NOW() - INTERVAL 24 HOUR)
  ");
    $st->execute($reportParams);
    $resolved24 = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $resolved24 = 0;
  }

  $engine = new PrioritizationEngine();
  $prio = $engine->compute($pdo, $reportWhere, $reportParams, $healthAuthorityId);
  $focus = [];
  foreach (array_slice($prio['by_category'] ?? [], 0, 4) as $row) {
    if (!is_array($row)) {
      continue;
    }
    $focus[] = [
      'category' => (string)($row['category'] ?? ''),
      'open_count' => (int)($row['open_count'] ?? 0),
      'avg_age_days' => $row['avg_age_days'] ?? null,
      'rank' => (int)($row['rank'] ?? 0),
    ];
  }

  $out = [
    'ok' => true,
    'data' => [
      'as_of' => gmdate('c'),
      'last_24h' => [
        'reports_created' => $created24,
        'reports_resolved' => $resolved24,
      ],
      'open_backlog' => (int)($prio['totals']['open_reports'] ?? 0),
      'priority_focus' => $focus,
    ],
  ];
  gov_api_cache_set($cacheKey, $out);
  header('X-Gov-Api-Cache: MISS');
  json_response($out);
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('morning_brief: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  }
  json_response(['ok' => false, 'error' => t('common.error_load')], 500);
}
