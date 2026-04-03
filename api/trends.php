<?php
/**
 * Milestone 3 – Time series for gov dashboard (created vs resolved issues).
 * Reuses ExecutiveSummaryService scope; lightweight SQL only.
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

$pdo = db();
[$reportWhere, $reportParams] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

/**
 * @return array<string,int>
 */
function trends_daily_counts(PDO $pdo, string $reportWhere, array $reportParams, string $startDate, string $endDate, string $mode): array
{
  $start = $startDate . ' 00:00:00';
  $end = $endDate . ' 23:59:59';
  $out = [];
  if ($mode === 'created') {
    $sql = "
      SELECT DATE(r.created_at) AS d, COUNT(*) AS cnt
      FROM reports r
      WHERE ($reportWhere) AND r.created_at >= ? AND r.created_at <= ?
      GROUP BY DATE(r.created_at)
    ";
    $params = array_merge($reportParams, [$start, $end]);
  } else {
    $sql = "
      SELECT DATE(l.changed_at) AS d, COUNT(DISTINCT l.report_id) AS cnt
      FROM report_status_log l
      INNER JOIN reports r ON r.id = l.report_id
      WHERE ($reportWhere) AND l.new_status IN ('solved','closed')
        AND l.changed_at >= ? AND l.changed_at <= ?
      GROUP BY DATE(l.changed_at)
    ";
    $params = array_merge($reportParams, [$start, $end]);
  }
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $k = (string)($row['d'] ?? '');
      if ($k !== '') {
        $out[$k] = (int)($row['cnt'] ?? 0);
      }
    }
  } catch (Throwable $e) {
    if (function_exists('log_error')) {
      log_error('trends_daily_counts: ' . $e->getMessage());
    }
  }
  return $out;
}

/**
 * @return array{labels:string[],created:int[],resolved:int[]}
 */
function trends_build_daily_series(string $startDate, string $endDate, array $createdMap, array $resolvedMap): array
{
  $labels = [];
  $c = [];
  $r = [];
  try {
    $dt = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($dt <= $end) {
      $k = $dt->format('Y-m-d');
      $labels[] = $k;
      $c[] = $createdMap[$k] ?? 0;
      $r[] = $resolvedMap[$k] ?? 0;
      $dt->modify('+1 day');
    }
  } catch (Throwable $e) {
    return ['labels' => [], 'created' => [], 'resolved' => []];
  }
  return ['labels' => $labels, 'created' => $c, 'resolved' => $r];
}

/**
 * @return array{labels:string[],created:int[],resolved:int[]}
 */
function trends_monthly_series(PDO $pdo, string $reportWhere, array $reportParams, int $monthsBack): array
{
  $labels = [];
  $cMap = [];
  $rMap = [];
  try {
    $monthsBack = max(1, min(24, $monthsBack));
    $startDt = new DateTime('first day of this month');
    $startDt->modify('-' . ($monthsBack - 1) . ' months');
    $start = $startDt->format('Y-m-01 00:00:00');
    $end = (new DateTime('last day of this month'))->format('Y-m-d 23:59:59');

    $sqlC = "
      SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS m, COUNT(*) AS cnt
      FROM reports r
      WHERE ($reportWhere) AND r.created_at >= ? AND r.created_at <= ?
      GROUP BY DATE_FORMAT(r.created_at, '%Y-%m')
    ";
    $st = $pdo->prepare($sqlC);
    $st->execute(array_merge($reportParams, [$start, $end]));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $cMap[(string)$row['m']] = (int)$row['cnt'];
    }

    $sqlR = "
      SELECT DATE_FORMAT(l.changed_at, '%Y-%m') AS m, COUNT(DISTINCT l.report_id) AS cnt
      FROM report_status_log l
      INNER JOIN reports r ON r.id = l.report_id
      WHERE ($reportWhere) AND l.new_status IN ('solved','closed')
        AND l.changed_at >= ? AND l.changed_at <= ?
      GROUP BY DATE_FORMAT(l.changed_at, '%Y-%m')
    ";
    $st = $pdo->prepare($sqlR);
    $st->execute(array_merge($reportParams, [$start, $end]));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $rMap[(string)$row['m']] = (int)$row['cnt'];
    }

    $dt = clone $startDt;
    for ($i = 0; $i < $monthsBack; $i++) {
      $labels[] = $dt->format('Y-m');
      $dt->modify('+1 month');
    }
  } catch (Throwable $e) {
    if (function_exists('log_error')) {
      log_error('trends_monthly_series: ' . $e->getMessage());
    }
    return ['labels' => [], 'created' => [], 'resolved' => []];
  }

  $c = [];
  $r = [];
  foreach ($labels as $k) {
    $c[] = $cMap[$k] ?? 0;
    $r[] = $rMap[$k] ?? 0;
  }
  return ['labels' => $labels, 'created' => $c, 'resolved' => $r];
}

$end = date('Y-m-d');
$start30 = date('Y-m-d', strtotime('-29 days'));
$start90 = date('Y-m-d', strtotime('-89 days'));

$c30 = trends_daily_counts($pdo, $reportWhere, $reportParams, $start30, $end, 'created');
$r30 = trends_daily_counts($pdo, $reportWhere, $reportParams, $start30, $end, 'resolved');
$c90 = trends_daily_counts($pdo, $reportWhere, $reportParams, $start90, $end, 'created');
$r90 = trends_daily_counts($pdo, $reportWhere, $reportParams, $start90, $end, 'resolved');

$data = [
  'range_30d' => trends_build_daily_series($start30, $end, $c30, $r30),
  'range_90d' => trends_build_daily_series($start90, $end, $c90, $r90),
  'range_12m' => trends_monthly_series($pdo, $reportWhere, $reportParams, 12),
];

json_response(['ok' => true, 'data' => $data]);
