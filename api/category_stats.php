<?php
/**
 * Milestone 4 – Category distribution for scope (last N days).
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
[$reportWhere, $reportParams] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

$rows = [];
try {
  $sql = "
    SELECT r.category AS cat, COUNT(*) AS cnt
    FROM reports r
    WHERE ($reportWhere) AND r.created_at >= (NOW() - INTERVAL $days DAY)
    GROUP BY r.category
    ORDER BY cnt DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($reportParams);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = [
      'category' => (string)($row['cat'] ?? ''),
      'count' => (int)($row['cnt'] ?? 0),
    ];
  }
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('category_stats: ' . $e->getMessage());
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
    'total' => $total,
    'by_category' => $rows,
  ],
]);
