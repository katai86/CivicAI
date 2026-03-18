<?php
/**
 * City Brain dashboard – összesítő adatok a Live, Environmental és Risk tabokhoz.
 * GET: authority_id (opcionális). Válasz: ok, live { sensors_summary, reports_24h, ideas_24h, open_reports },
 * environmental { summary, by_provider }, risks [ { type, severity, message, since } ].
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$authorityIds = [];
$authorityCities = [];
$requestedAid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;

if ($isAdmin) {
  if ($requestedAid > 0) {
    $authorityIds = [$requestedAid];
  } else {
    try {
      $authorityIds = array_map('intval', db()->query("SELECT id FROM authorities ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
  }
} else {
  try {
    $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ?");
    $stmt->execute([$uid]);
    $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($requestedAid > 0 && !in_array($requestedAid, $authorityIds, true)) {
      json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    if ($requestedAid > 0) $authorityIds = [$requestedAid];
  } catch (Throwable $e) {}
}

$cities = [];
$bounds = [];
try {
  $stmt = db()->prepare("SELECT id, city, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ?");
  foreach ($authorityIds as $aid) {
    $stmt->execute([$aid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      if (!empty(trim($row['city'] ?? ''))) $cities[] = trim($row['city']);
      if (isset($row['min_lat'], $row['max_lat'], $row['min_lng'], $row['max_lng']) &&
          $row['min_lat'] !== null && $row['max_lat'] !== null && $row['min_lng'] !== null && $row['max_lng'] !== null) {
        $bounds[] = [(float)$row['min_lat'], (float)$row['max_lat'], (float)$row['min_lng'], (float)$row['max_lng']];
      }
    }
  }
} catch (Throwable $e) {}

list($where, $params) = virtual_sensors_scope_for_authority($cities, $bounds);

$pdo = db();
$sensorsSummary = ['total' => 0, 'active' => 0, 'stale_count' => 0, 'avg_aqi' => null, 'avg_pm25' => null, 'avg_temperature' => null];
$byProvider = [];
$sensorRows = [];
$staleSeconds = 24 * 3600;
$now = time();

try {
  $pdo->query("SELECT 1 FROM virtual_sensors LIMIT 1");
} catch (Throwable $e) {
  json_response(['ok' => true, 'live' => build_live($sensorsSummary, 0, 0, 0), 'environmental' => ['summary' => $sensorsSummary, 'by_provider' => $byProvider], 'risks' => []]);
}

$sql = "SELECT vs.id, vs.source_provider, vs.last_seen_at FROM virtual_sensors vs WHERE $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sensorRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sensorIds = array_map(function ($r) { return (int)$r['id']; }, $sensorRows);
$sensorsSummary['total'] = count($sensorRows);

foreach ($sensorRows as $r) {
  $p = (string)($r['source_provider'] ?? 'other');
  $byProvider[$p] = ($byProvider[$p] ?? 0) + 1;
  if ($r['last_seen_at'] && strtotime($r['last_seen_at']) < $now - $staleSeconds) {
    $sensorsSummary['stale_count']++;
  }
}
$sensorsSummary['active'] = $sensorsSummary['total'] - $sensorsSummary['stale_count'];

if (!empty($sensorIds)) {
  $in = implode(',', array_fill(0, count($sensorIds), '?'));
  $mStmt = $pdo->prepare("SELECT virtual_sensor_id, metric_key, metric_value FROM virtual_sensor_metrics_latest WHERE virtual_sensor_id IN ($in)");
  $mStmt->execute($sensorIds);
  $aqi = []; $pm25 = []; $temp = [];
  while ($row = $mStmt->fetch(PDO::FETCH_ASSOC)) {
    $v = $row['metric_value'] !== null ? (float)$row['metric_value'] : null;
    if ($v === null) continue;
    if ($row['metric_key'] === 'aqi') $aqi[] = $v;
    if ($row['metric_key'] === 'pm25') $pm25[] = $v;
    if (in_array($row['metric_key'], ['temperature', 'temp'], true)) $temp[] = $v;
  }
  $avg = function ($arr) {
    if (empty($arr)) return null;
    return round(array_sum($arr) / count($arr), 1);
  };
  $sensorsSummary['avg_aqi'] = $avg($aqi);
  $sensorsSummary['avg_pm25'] = $avg($pm25);
  $sensorsSummary['avg_temperature'] = $avg($temp);
}

// Reports: authority scope (authority_id IN + city IN for null authority_id)
$reportWhere = '1=1';
$reportParams = [];
if (!empty($authorityIds)) {
  $reportWhere = "r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
  $reportParams = $authorityIds;
}
if (!empty($cities)) {
  $reportWhere .= " OR (r.authority_id IS NULL AND r.city IN (" . implode(',', array_fill(0, count($cities), '?')) . "))";
  $reportParams = array_merge($reportParams, $cities);
}

$reports_24h = 0;
$ideas_24h = 0;
$open_reports = 0;
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.created_at >= (NOW() - INTERVAL 24 HOUR)");
  $stmt->execute($reportParams);
  $reports_24h = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.status NOT IN ('solved','closed')");
  $stmt->execute($reportParams);
  $open_reports = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
try {
  $ideas_24h = (int)$pdo->query("SELECT COUNT(*) FROM ideas WHERE created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
} catch (Throwable $e) {}

$risks = [];
if ($sensorsSummary['avg_aqi'] !== null && $sensorsSummary['avg_aqi'] > 100) {
  $risks[] = ['type' => 'aqi', 'severity' => 'high', 'message' => 'Átlagos AQI magas: ' . $sensorsSummary['avg_aqi'], 'since' => date('Y-m-d H:i')];
}
if ($sensorsSummary['stale_count'] > 0) {
  $risks[] = ['type' => 'stale_sensors', 'severity' => 'medium', 'message' => $sensorsSummary['stale_count'] . ' szenzor 24 órája nem frissült', 'since' => null];
}
if ($open_reports > 50) {
  $risks[] = ['type' => 'backlog', 'severity' => 'medium', 'message' => $open_reports . ' nyitott bejelentés', 'since' => null];
}

function build_live($sensorsSummary, $reports_24h, $ideas_24h, $open_reports) {
  return [
    'sensors_summary' => $sensorsSummary,
    'reports_24h' => $reports_24h,
    'ideas_24h' => $ideas_24h,
    'open_reports' => $open_reports,
  ];
}

json_response([
  'ok' => true,
  'live' => build_live($sensorsSummary, $reports_24h, $ideas_24h, $open_reports),
  'environmental' => ['summary' => $sensorsSummary, 'by_provider' => $byProvider],
  'risks' => $risks,
]);
