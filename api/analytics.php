<?php
/**
 * CivicAI Analytics – statisztika modul (M1).
 * Issue, citizen engagement, urban maintenance metrikák; export JSON / CSV.
 * Jogosultság: admin (bármely scope) vagy gov user (saját hatóság).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$uid = current_user_id();
$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
  if (is_api_request() || (isset($_GET['format']) && $_GET['format'] === 'json')) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

// Scope: admin can pass authority_id or city; gov user = first authority only
$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';
$format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'json';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

if (!$isAdmin) {
  $stmt = db()->prepare("
    SELECT authority_id FROM authority_users WHERE user_id = :uid ORDER BY authority_id ASC LIMIT 1
  ");
  $stmt->execute([':uid' => $uid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $authorityId = $row ? (int)$row['authority_id'] : null;
  $city = '';
}

$where = '1=1';
$params = [];
if ($authorityId > 0) {
  $where = 'r.authority_id = ?';
  $params[] = $authorityId;
} elseif ($city !== '') {
  $where = '(r.authority_id IS NULL AND r.city = ?) OR r.city = ?';
  $params[] = $city;
  $params[] = $city;
}

$dateWhere = '';
$dateParams = [];
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
  $dateWhere .= ' AND r.created_at >= ?';
  $dateParams[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
  $dateWhere .= ' AND r.created_at <= ?';
  $dateParams[] = $dateTo . ' 23:59:59';
}
$allParams = array_merge($params, $dateParams);

$pdo = db();

// ---------- Issue statistics ----------
$issues = [
  'total' => 0,
  'open' => 0,
  'resolved' => 0,
  'by_category' => [],
  'by_district' => [],
  'by_subcity' => [],
  'by_month' => [],
  'avg_resolution_days' => null,
];

try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere");
  $q->execute($allParams);
  $issues['total'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}

$openStatuses = ['pending', 'approved', 'new', 'needs_info', 'forwarded', 'waiting_reply', 'in_progress'];
$closedStatuses = ['solved', 'closed', 'rejected'];
$placeholders = implode(',', array_fill(0, count($openStatuses), '?'));
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere AND r.status IN ($placeholders)");
  $q->execute(array_merge($allParams, $openStatuses));
  $issues['open'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
try {
  $placeholders = implode(',', array_fill(0, count($closedStatuses), '?'));
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere AND r.status IN ($placeholders)");
  $q->execute(array_merge($allParams, $closedStatuses));
  $issues['resolved'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}

try {
  $q = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $where $dateWhere GROUP BY r.category ORDER BY cnt DESC");
  $q->execute($allParams);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $issues['by_category'][(string)$row['category']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}

// District = suburb or city (if suburb empty)
try {
  $q = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(r.suburb), ''), r.city, '—') AS district, COUNT(*) AS cnt
    FROM reports r WHERE $where $dateWhere
    GROUP BY district ORDER BY cnt DESC
  ");
  $q->execute($allParams);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $issues['by_district'][(string)$row['district']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}

$useSubcity = admin_subdivision_analytics_use_subcity($authorityId > 0 ? $authorityId : null);
if ($useSubcity) {
  try {
    $q = $pdo->prepare("
      SELECT COALESCE(
        NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.admin_subdivision_json, '\$.subcity_name'))), ''),
        NULLIF(TRIM(r.suburb), ''),
        NULLIF(TRIM(r.city), ''),
        '—'
      ) AS subcity, COUNT(*) AS cnt
      FROM reports r
      WHERE $where $dateWhere
      GROUP BY subcity
      ORDER BY cnt DESC
    ");
    $q->execute($allParams);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $issues['by_subcity'][(string)$row['subcity']] = (int)$row['cnt'];
    }
  } catch (Throwable $e) {
    $issues['by_subcity'] = [];
  }
}

// By month (last 12 months)
try {
  $q = $pdo->prepare("
    SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS month, COUNT(*) AS cnt
    FROM reports r WHERE $where $dateWhere
    AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month ASC
  ");
  $q->execute($allParams);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $issues['by_month'][(string)$row['month']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}

// Average resolution time (days): from report created_at to first solved/closed in report_status_log
try {
  $q = $pdo->prepare("
    SELECT AVG(DATEDIFF(s.first_closed, r.created_at)) AS avg_days
    FROM reports r
    INNER JOIN (
      SELECT report_id, MIN(changed_at) AS first_closed
      FROM report_status_log
      WHERE new_status IN ('solved','closed')
      GROUP BY report_id
    ) s ON s.report_id = r.id
    WHERE $where $dateWhere AND r.created_at <= s.first_closed
  ");
  $q->execute($allParams);
  $val = $q->fetchColumn();
  $issues['avg_resolution_days'] = $val !== null ? round((float)$val, 1) : null;
} catch (Throwable $e) {}

// ---------- Citizen engagement ----------
$engagement = [
  'active_users' => 0,
  'new_users_30d' => 0,
  'reports_per_user' => [],
  'upvotes_total' => 0,
  'upvotes_per_issue' => null,
  'participation_index' => null,
];

$userWhere = "r.user_id IS NOT NULL AND r.user_id > 0";
try {
  $q = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $where $dateWhere AND $userWhere");
  $q->execute($allParams);
  $engagement['active_users'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}

try {
  $q = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id) FROM users u
    INNER JOIN reports r ON r.user_id = u.id
    WHERE $where $dateWhere AND u.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  ");
  $q->execute($allParams);
  $engagement['new_users_30d'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}

try {
  $q = $pdo->prepare("
    SELECT r.user_id, COUNT(*) AS cnt FROM reports r
    WHERE $where $dateWhere AND $userWhere
    GROUP BY r.user_id ORDER BY cnt DESC LIMIT 100
  ");
  $q->execute($allParams);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $engagement['reports_per_user'][(string)$row['user_id']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}

try {
  $q = $pdo->prepare("
    SELECT COUNT(*) FROM report_likes rl
    INNER JOIN reports r ON r.id = rl.report_id
    WHERE $where $dateWhere
  ");
  $q->execute($allParams);
  $engagement['upvotes_total'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}

if ($issues['total'] > 0 && $engagement['upvotes_total'] >= 0) {
  $engagement['upvotes_per_issue'] = round($engagement['upvotes_total'] / $issues['total'], 2);
}
if ($issues['total'] > 0 && $engagement['active_users'] > 0) {
  $engagement['participation_index'] = round($issues['total'] / $engagement['active_users'], 2);
}

// ---------- Urban maintenance (category mapping: road, green, lighting, trash, drainage) ----------
$urban = [
  'pothole_reports' => 0,
  'illegal_dumping_reports' => 0,
  'streetlight_failures' => 0,
  'park_maintenance' => 0,
  'drainage_problems' => 0,
];

$catMap = [
  'road' => 'pothole_reports',
  'green' => 'park_maintenance',
  'lighting' => 'streetlight_failures',
  'trash' => 'illegal_dumping_reports',
  'drainage' => 'drainage_problems',
];
try {
  $q = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $where $dateWhere GROUP BY r.category");
  $q->execute($allParams);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cat = (string)$row['category'];
    $key = $catMap[$cat] ?? null;
    if ($key) {
      $urban[$key] = (int)$row['cnt'];
    }
  }
} catch (Throwable $e) {}

$payload = [
  'scope' => [
    'authority_id' => $authorityId,
    'city' => $city,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
  ],
  'issues' => $issues,
  'engagement' => $engagement,
  'urban_maintenance' => $urban,
  'generated_at' => date('c'),
];

if ($format === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="civicai-analytics-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
  fputcsv($out, ['metric', 'value'], ';');
  fputcsv($out, ['issues_total', $issues['total']], ';');
  fputcsv($out, ['issues_open', $issues['open']], ';');
  fputcsv($out, ['issues_resolved', $issues['resolved']], ';');
  fputcsv($out, ['avg_resolution_days', $issues['avg_resolution_days'] ?? ''], ';');
  fputcsv($out, ['active_users', $engagement['active_users']], ';');
  fputcsv($out, ['new_users_30d', $engagement['new_users_30d']], ';');
  fputcsv($out, ['upvotes_total', $engagement['upvotes_total']], ';');
  foreach ($issues['by_category'] as $cat => $cnt) {
    fputcsv($out, ['category_' . $cat, $cnt], ';');
  }
  foreach ($issues['by_district'] as $dist => $cnt) {
    fputcsv($out, ['district_' . $dist, $cnt], ';');
  }
  foreach ($issues['by_subcity'] as $sc => $cnt) {
    fputcsv($out, ['subcity_' . $sc, $cnt], ';');
  }
  foreach ($urban as $k => $v) {
    fputcsv($out, [$k, $v], ';');
  }
  fclose($out);
  exit;
}

json_response([
  'ok' => true,
  'analytics' => $payload,
]);
