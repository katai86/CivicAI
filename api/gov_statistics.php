<?php
/**
 * M2 – Government Statistics Hub API.
 * Csak gov/admin. Válasz: issue_trends, issue_trend_per_district, response_times, resolution_rate,
 * backlog_growth, citizen_participation_rate, tree_maintenance_stats, engagement_rate.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$role = current_user_role();
$uid = current_user_id() ? (int)current_user_id() : 0;
$authorityIds = [];
$authorityCities = [];

if ($role && in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  if ($aid > 0) {
    $authorityIds = [$aid];
  } else {
    try {
      $rows = db()->query("SELECT id FROM authorities ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
      $authorityIds = array_map('intval', $rows);
    } catch (Throwable $e) {
      $authorityIds = [];
    }
  }
} else {
  if ($uid) {
    try {
      $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id");
      $stmt->execute([$uid]);
      $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
      if (!empty($authorityIds)) {
        $stmt = db()->prepare("SELECT city FROM authorities WHERE id = ?");
        foreach ($authorityIds as $aid) {
          $stmt->execute([$aid]);
          $city = $stmt->fetchColumn();
          if ($city) $authorityCities[] = $city;
        }
        $authorityCities = array_values(array_unique(array_filter($authorityCities)));
      }
    } catch (Throwable $e) {}
  }
}

$baseWhere = '1=1';
$baseParams = [];
if (in_array($role, ['admin', 'superadmin'], true)) {
  if (!empty($authorityIds)) {
    $placeholders = implode(',', array_fill(0, count($authorityIds), '?'));
    $baseWhere = "r.authority_id IN ($placeholders)";
    $baseParams = $authorityIds;
  }
} else {
  if (empty($authorityIds)) {
    $baseWhere = '1=0';
  } else {
    $placeholders = implode(',', array_fill(0, count($authorityIds), '?'));
    $baseWhere = "r.authority_id IN ($placeholders)";
    $baseParams = $authorityIds;
    if (!empty($authorityCities)) {
      $baseWhere .= " OR (r.authority_id IS NULL AND r.city IN (" . implode(',', array_fill(0, count($authorityCities), '?')) . "))";
      $baseParams = array_merge($baseParams, $authorityCities);
    }
  }
}

$dateFrom = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$dateTo   = isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])   ? $_GET['date_to'] : date('Y-m-d');

$out = [
  'issue_trends' => [],
  'issue_trend_per_district' => [],
  'response_times' => [ 'avg_hours' => 0, 'median_hours' => 0, 'by_category' => [] ],
  'resolution_rate' => [ 'rate' => 0, 'total' => 0, 'resolved' => 0 ],
  'backlog_growth' => [ 'current_open' => 0, 'previous_period_open' => 0, 'trend' => 'stable' ],
  'citizen_participation_rate' => [ 'active_users_7d' => 0, 'reports_7d' => 0, 'rate_description' => '' ],
  'tree_maintenance_stats' => [ 'total_trees' => 0, 'watered_7d' => 0, 'adopted' => 0, 'health_at_risk_count' => 0 ],
  'engagement_rate' => [ 'reports_per_user_7d' => 0, 'new_users_7d' => 0 ],
];

$pdo = db();

try {
  // issue_trends: naponta vagy kategóriánként (dátum + kategória aggregátum, max 90 nap)
  $stmt = $pdo->prepare("
    SELECT DATE(r.created_at) AS d, r.category, COUNT(*) AS cnt
    FROM reports r
    WHERE $baseWhere AND r.created_at >= ? AND r.created_at <= ?
    GROUP BY DATE(r.created_at), r.category
    ORDER BY d ASC, r.category ASC
    LIMIT 500
  ");
  $stmt->execute(array_merge($baseParams, [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $out['issue_trends'][] = [ 'date' => $row['d'], 'category' => (string)$row['category'], 'count' => (int)$row['cnt'] ];
  }

  // issue_trend_per_district: authority_id, name, count (csak ha több hatóság)
  if (count($authorityIds) > 1 || empty($authorityIds)) {
    $sql = "SELECT r.authority_id, a.name, COUNT(*) AS cnt FROM reports r LEFT JOIN authorities a ON a.id = r.authority_id WHERE r.created_at >= ? AND r.created_at <= ? ";
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if (!empty($authorityIds)) {
      $sql .= " AND r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
      $params = array_merge($params, $authorityIds);
    }
    $sql .= " GROUP BY r.authority_id, a.name ORDER BY cnt DESC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out['issue_trend_per_district'][] = [ 'authority_id' => (int)$row['authority_id'], 'name' => (string)($row['name'] ?? ''), 'count' => (int)$row['cnt'] ];
    }
  } else {
    $stmt = $pdo->prepare("SELECT a.id AS authority_id, a.name, (SELECT COUNT(*) FROM reports r2 WHERE r2.authority_id = a.id AND r2.created_at >= ? AND r2.created_at <= ?) AS cnt FROM authorities a WHERE a.id = ?");
    $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $authorityIds[0]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $out['issue_trend_per_district'][] = [ 'authority_id' => (int)$row['authority_id'], 'name' => (string)$row['name'], 'count' => (int)$row['cnt'] ];
  }

  // response_times: átlag óra report created -> first solved/closed
  $sql = "
    SELECT r.id, r.category, r.created_at AS created,
           (SELECT MIN(l.changed_at) FROM report_status_log l WHERE l.report_id = r.id AND l.new_status IN ('solved','closed')) AS resolved_at
    FROM reports r
    WHERE $baseWhere AND r.status IN ('solved','closed')
    AND EXISTS (SELECT 1 FROM report_status_log l WHERE l.report_id = r.id AND l.new_status IN ('solved','closed'))
    LIMIT 500
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($baseParams);
  $hoursList = [];
  $byCat = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $resolvedAt = $row['resolved_at'] ?? null;
    if (!$resolvedAt) continue;
    $created = strtotime($row['created']);
    $resolved = strtotime($resolvedAt);
    if ($created && $resolved) {
      $h = ($resolved - $created) / 3600;
      $hoursList[] = $h;
      $cat = (string)$row['category'];
      if (!isset($byCat[$cat])) $byCat[$cat] = [];
      $byCat[$cat][] = $h;
    }
  }
  if (count($hoursList) > 0) {
    $out['response_times']['avg_hours'] = round(array_sum($hoursList) / count($hoursList), 1);
    sort($hoursList);
    $mid = (int)(count($hoursList) / 2);
    $out['response_times']['median_hours'] = round($hoursList[$mid], 1);
    foreach ($byCat as $c => $arr) {
      $out['response_times']['by_category'][$c] = round(array_sum($arr) / count($arr), 1);
    }
  }

  // resolution_rate: időszakban total vs solved+closed
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= ? AND r.created_at <= ?");
  $stmt->execute(array_merge($baseParams, [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']));
  $total = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= ? AND r.created_at <= ? AND r.status IN ('solved','closed')");
  $stmt->execute(array_merge($baseParams, [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']));
  $resolved = (int)$stmt->fetchColumn();
  $out['resolution_rate'] = [ 'rate' => $total > 0 ? round($resolved / $total, 2) : 0, 'total' => $total, 'resolved' => $resolved ];

  // backlog_growth: current open vs 7 nap ezelőtti open
  $openStatuses = "'new','approved','needs_info','forwarded','waiting_reply','in_progress','pending'";
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.status IN ($openStatuses)");
  $stmt->execute($baseParams);
  $out['backlog_growth']['current_open'] = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.status IN ($openStatuses) AND r.created_at < (NOW() - INTERVAL 7 DAY)");
  $stmt->execute($baseParams);
  $prev = (int)$stmt->fetchColumn();
  $out['backlog_growth']['previous_period_open'] = $prev;
  $out['backlog_growth']['trend'] = $out['backlog_growth']['current_open'] > $prev ? 'up' : ($out['backlog_growth']['current_open'] < $prev ? 'down' : 'stable');

  // citizen_participation_rate
  $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $baseWhere AND r.user_id IS NOT NULL AND r.user_id > 0 AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
  $stmt->execute($baseParams);
  $out['citizen_participation_rate']['active_users_7d'] = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
  $stmt->execute($baseParams);
  $out['citizen_participation_rate']['reports_7d'] = (int)$stmt->fetchColumn();
  $out['citizen_participation_rate']['rate_description'] = $out['citizen_participation_rate']['active_users_7d'] > 0
    ? round($out['citizen_participation_rate']['reports_7d'] / $out['citizen_participation_rate']['active_users_7d'], 1) . ' report/fő (7 nap)'
    : '-';

  // tree_maintenance_stats – hatósági fa-scope (gov_trees_scope), mint gov_trees_list
  try {
    if (!empty($authorityIds)) {
      [$tsc, $tsp] = gov_trees_scope_where_sql($pdo, $authorityIds, 't');
      $st = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tsc)");
      $st->execute($tsp);
      $out['tree_maintenance_stats']['total_trees'] = (int)$st->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(DISTINCT tw.tree_id) FROM tree_watering_logs tw INNER JOIN trees t ON t.id = tw.tree_id WHERE ($tsc) AND tw.created_at >= (NOW() - INTERVAL 7 DAY)");
      $st->execute($tsp);
      $out['tree_maintenance_stats']['watered_7d'] = (int)$st->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(DISTINCT ta.tree_id) FROM tree_adoptions ta INNER JOIN trees t ON t.id = ta.tree_id WHERE ta.status = 'active' AND ($tsc)");
      $st->execute($tsp);
      $out['tree_maintenance_stats']['adopted'] = (int)$st->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tsc) AND (t.risk_level = 'high' OR t.risk_level = 'medium')");
      $st->execute($tsp);
      $out['tree_maintenance_stats']['health_at_risk_count'] = (int)$st->fetchColumn();
    }
  } catch (Throwable $e) {}

  // engagement_rate
  $out['engagement_rate']['reports_per_user_7d'] = $out['citizen_participation_rate']['active_users_7d'] > 0
    ? round($out['citizen_participation_rate']['reports_7d'] / $out['citizen_participation_rate']['active_users_7d'], 1) : 0;
  try {
    $out['engagement_rate']['new_users_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
  } catch (Throwable $e) {
    $out['engagement_rate']['new_users_7d'] = 0;
  }

} catch (Throwable $e) {
  if (function_exists('log_error')) log_error('gov_statistics: ' . $e->getMessage());
  json_response(['ok' => false, 'error' => 'Server error'], 500);
}

json_response(['ok' => true, 'data' => $out]);
