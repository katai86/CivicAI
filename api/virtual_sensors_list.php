<?php
/**
 * Virtuális szenzorok listája – Gov dashboard / IoT tab.
 * GET: authority_id (opcionális). Válasz: ok, sensors [ { id, name, source_provider, lat, lng, municipality, last_seen_at, metrics } ], summary.
 * Szenzorok szűrése: hatóság városa (municipality) vagy hatóság bounds (min_lat/max_lat/min_lng/max_lng).
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

if (empty($authorityIds)) {
  json_response(['ok' => true, 'sensors' => [], 'summary' => ['total' => 0, 'active' => 0, 'stale_count' => 0, 'avg_aqi' => null, 'avg_pm25' => null, 'avg_temperature' => null]]);
}

try {
  $stmt = db()->prepare("SELECT id, city, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ?");
  $cities = [];
  $bounds = [];
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
} catch (Throwable $e) {
  json_response(['ok' => true, 'sensors' => [], 'summary' => ['total' => 0, 'active' => 0, 'stale_count' => 0, 'avg_aqi' => null, 'avg_pm25' => null, 'avg_temperature' => null]]);
}

$db = db();
$tableExists = false;
try {
  $db->query("SELECT 1 FROM virtual_sensors LIMIT 1");
  $tableExists = true;
} catch (Throwable $e) {}

if (!$tableExists) {
  json_response(['ok' => true, 'sensors' => [], 'summary' => ['total' => 0, 'active' => 0, 'stale_count' => 0, 'avg_aqi' => null, 'avg_pm25' => null, 'avg_temperature' => null]]);
}

list($where, $params) = virtual_sensors_scope_for_authority($cities, $bounds);

$sql = "SELECT vs.id, vs.source_provider, vs.external_station_id, vs.name, vs.sensor_type, vs.category,
        vs.latitude, vs.longitude, vs.address_or_area_name, vs.municipality, vs.country, vs.status,
        vs.trust_score, vs.confidence_score, vs.last_seen_at, vs.ownership_type
        FROM virtual_sensors vs WHERE $where ORDER BY vs.last_seen_at DESC, vs.id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sensorIds = array_map(function ($r) { return (int)$r['id']; }, $rows);
$metricsBySensor = [];
$summaryAqi = [];
$summaryPm25 = [];
$summaryTemp = [];
$staleCount = 0;
$now = time();
$staleSeconds = 24 * 3600;
// Backfill védelem: korábban hibásan eltárolt (F/K) hőmérsékletet is normalizáljuk °C-ra.
$normalizeTempCelsius = function (?float $value, ?string $unit): ?float {
  if ($value === null) return null;
  $u = strtolower(trim((string)($unit ?? '')));
  if ($u === 'fahrenheit' || $u === 'degf' || $u === 'f' || strpos($u, 'fahrenheit') !== false || strpos($u, 'degf') !== false) {
    $value = ($value - 32.0) * (5.0 / 9.0);
    return ($value > -60 && $value <= 50) ? $value : null;
  }
  if ($u === 'kelvin' || $u === 'k' || $u === 'degk' || strpos($u, 'kelvin') !== false || strpos($u, 'degk') !== false) {
    $value = $value - 273.15;
    return ($value > -60 && $value <= 50) ? $value : null;
  }
  // Lakott területen 50°C felett a nyers érték nem tekinthető valós Celsiusnak.
  // Előbb próbáljuk Fahrenheitnek, majd Kelvinnek értelmezni.
  if ($value > 50 && $value <= 180) {
    $f = ($value - 32.0) * (5.0 / 9.0);
    if ($f > -60 && $f <= 50) return $f;
  }
  if ($value > 180 && $value <= 400) {
    $k = $value - 273.15;
    if ($k > -60 && $k <= 50) return $k;
  }
  return ($value > -60 && $value <= 50) ? $value : null;
};

if (!empty($sensorIds)) {
  $in = implode(',', array_fill(0, count($sensorIds), '?'));
  $metricsSql = "SELECT virtual_sensor_id, metric_key, metric_value, metric_unit, measured_at FROM virtual_sensor_metrics_latest WHERE virtual_sensor_id IN ($in)";
  $mStmt = $db->prepare($metricsSql);
  $mStmt->execute($sensorIds);
  while ($row = $mStmt->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$row['virtual_sensor_id'];
    if (!isset($metricsBySensor[$sid])) $metricsBySensor[$sid] = [];
    $metricKey = (string)$row['metric_key'];
    $rawValue = $row['metric_value'] !== null ? (float)$row['metric_value'] : null;
    $metricUnit = $row['metric_unit'];
    $value = $rawValue;
    if (in_array($metricKey, ['temperature', 'temp'], true)) {
      $value = $normalizeTempCelsius($rawValue, $metricUnit);
      $metricUnit = 'celsius';
    }
    $metricsBySensor[$sid][$metricKey] = [
      'value' => $value,
      'unit' => $metricUnit,
      'measured_at' => $row['measured_at'],
    ];
    if ($metricKey === 'aqi' && $rawValue !== null) $summaryAqi[] = $rawValue;
    if ($metricKey === 'pm25' && $rawValue !== null) $summaryPm25[] = $rawValue;
    if (in_array($metricKey, ['temperature', 'temp'], true) && $value !== null) $summaryTemp[] = $value;
  }
  foreach ($rows as $r) {
    $last = $r['last_seen_at'];
    if ($last && (strtotime($last) < $now - $staleSeconds)) $staleCount++;
  }
}

$sensors = [];
foreach ($rows as $r) {
  $sid = (int)$r['id'];
  $sensors[] = [
    'id' => $sid,
    'name' => $r['name'] ?: ($r['source_provider'] . ' #' . $r['external_station_id']),
    'source_provider' => $r['source_provider'],
    'external_station_id' => $r['external_station_id'],
    'sensor_type' => $r['sensor_type'],
    'category' => $r['category'],
    'lat' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
    'lng' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
    'address_or_area_name' => $r['address_or_area_name'],
    'municipality' => $r['municipality'],
    'country' => $r['country'],
    'status' => $r['status'],
    'trust_score' => $r['trust_score'] !== null ? (float)$r['trust_score'] : null,
    'confidence_score' => isset($r['confidence_score']) && $r['confidence_score'] !== null ? (float)$r['confidence_score'] : null,
    'last_seen_at' => $r['last_seen_at'],
    'ownership_type' => $r['ownership_type'],
    'metrics' => $metricsBySensor[$sid] ?? [],
  ];
}

$avg = function ($arr) {
  if (empty($arr)) return null;
  return round(array_sum($arr) / count($arr), 1);
};

json_response([
  'ok' => true,
  'sensors' => $sensors,
  'summary' => [
    'total' => count($sensors),
    'active' => count($sensors) - $staleCount,
    'stale_count' => $staleCount,
    'avg_aqi' => $avg($summaryAqi),
    'avg_pm25' => $avg($summaryPm25),
    'avg_temperature' => $avg($summaryTemp),
  ],
]);
