<?php
/**
 * Urban ESG Dashboard – generate_esg_report(year).
 * E/S/G metrikák adott évre (vagy aktuális), export JSON / CSV.
 * Jogosultság: admin vagy gov user (saját hatóság).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$uid = current_user_id();
$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
  if (isset($_GET['format']) && in_array($_GET['format'], ['json', 'csv'], true)) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) {
  $year = (int)date('Y');
}
$format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'json';
$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';

if (!$isAdmin) {
  $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = :uid ORDER BY authority_id ASC LIMIT 1");
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

$yearStart = $year . '-01-01 00:00:00';
$yearEnd = $year . '-12-31 23:59:59';
$paramsYear = array_merge($params, [$yearStart, $yearEnd]);
$dateWhere = ' AND r.created_at >= ? AND r.created_at <= ?';

$pdo = db();

// ---------- Environment ----------
$environment = [
  'trees_total' => 0,
  'trees_planted_in_year' => 0,
  'green_reports' => 0,
  'trash_reports' => 0,
  'co2_estimate_kg' => null,
];
try {
  $environment['trees_total'] = (int)$pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1")->fetchColumn();
} catch (Throwable $e) {}
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND (planting_year = ? OR (planting_year IS NULL AND YEAR(created_at) = ?))");
  $stmt->execute([$year, $year]);
  $environment['trees_planted_in_year'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere AND r.category = 'green'");
  $q->execute($paramsYear);
  $environment['green_reports'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere AND r.category = 'trash'");
  $q->execute($paramsYear);
  $environment['trash_reports'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
// Egyszerű becslés: ~22 kg CO2/fa/év (közelítés)
if ($environment['trees_total'] > 0) {
  $environment['co2_estimate_kg'] = (int)($environment['trees_total'] * 22);
}

// ---------- Social ----------
$social = [
  'active_citizens' => 0,
  'new_reports_in_year' => 0,
  'tree_adopters' => 0,
  'watering_actions_in_year' => 0,
  'upvotes_in_year' => 0,
];
try {
  $q = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $where $dateWhere AND r.user_id IS NOT NULL AND r.user_id > 0");
  $q->execute($paramsYear);
  $social['active_citizens'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere");
  $q->execute($paramsYear);
  $social['new_reports_in_year'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
try {
  $social['tree_adopters'] = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM tree_adoptions WHERE status = 'active'")->fetchColumn();
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM tree_watering_logs WHERE created_at >= ? AND created_at <= ?");
  $q->execute([$yearStart, $yearEnd]);
  $social['watering_actions_in_year'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM report_likes rl INNER JOIN reports r ON r.id = rl.report_id WHERE $where $dateWhere");
  $q->execute($paramsYear);
  $social['upvotes_in_year'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}

// ---------- Governance ----------
$governance = [
  'reports_total_in_year' => 0,
  'reports_resolved_in_year' => 0,
  'reports_open_at_year_end' => 0,
  'avg_resolution_days' => null,
  'resolution_rate' => null,
];
try {
  $q = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere");
  $q->execute($paramsYear);
  $governance['reports_total_in_year'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("
    SELECT COUNT(DISTINCT r.id) FROM reports r
    JOIN report_status_log l ON l.report_id = r.id AND l.new_status IN ('solved','closed')
    WHERE $where $dateWhere AND l.changed_at >= ? AND l.changed_at <= ?
  ");
  $q->execute(array_merge($paramsYear, [$yearStart, $yearEnd]));
  $governance['reports_resolved_in_year'] = (int)$q->fetchColumn();
} catch (Throwable $e) {}
$governance['reports_open_at_year_end'] = max(0, $governance['reports_total_in_year'] - $governance['reports_resolved_in_year']);
try {
  $q = $pdo->prepare("
    SELECT AVG(DATEDIFF(l.changed_at, r.created_at)) AS avg_days
    FROM reports r
    JOIN report_status_log l ON l.report_id = r.id AND l.new_status IN ('solved','closed')
    WHERE $where $dateWhere AND l.changed_at >= ? AND l.changed_at <= ?
  ");
  $q->execute(array_merge($paramsYear, [$yearStart, $yearEnd]));
  $avg = $q->fetchColumn();
  if ($avg !== false && $avg !== null) {
    $governance['avg_resolution_days'] = round((float)$avg, 1);
  }
} catch (Throwable $e) {}
if ($governance['reports_total_in_year'] > 0 && $governance['reports_resolved_in_year'] >= 0) {
  $governance['resolution_rate'] = round(100.0 * $governance['reports_resolved_in_year'] / $governance['reports_total_in_year'], 1);
}

$payload = [
  'year' => $year,
  'scope' => ['authority_id' => $authorityId, 'city' => $city],
  'environment' => $environment,
  'social' => $social,
  'governance' => $governance,
  'generated_at' => date('c'),
];

if ($format === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="esg-report-' . $year . '.csv"');
  $out = fopen('php://output', 'w');
  fprintf($out, "\xEF\xBB\xBF");
  fputcsv($out, ['section', 'metric', 'value'], ';');
  foreach ($environment as $k => $v) {
    fputcsv($out, ['environment', $k, $v === null ? '' : $v], ';');
  }
  foreach ($social as $k => $v) {
    fputcsv($out, ['social', $k, $v], ';');
  }
  foreach ($governance as $k => $v) {
    fputcsv($out, ['governance', $k, $v === null ? '' : $v], ';');
  }
  fclose($out);
  exit;
}

json_response(['ok' => true, 'esg' => $payload]);
