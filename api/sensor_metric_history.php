<?php
/**
 * Szenzor metrika történet – trend grafikonhoz (pl. 7 napos hőmérséklet).
 * GET: sensor_id, metric_key (default temperature), days (default 7).
 * Válasz: ok, data [ { date, value, measured_at } ] napi átlagokkal.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = current_user_role() ?: '';
if ($uid <= 0 || (!$role || !in_array($role, ['admin', 'superadmin', 'govuser'], true))) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$sensorId = isset($_GET['sensor_id']) ? (int)$_GET['sensor_id'] : 0;
$metricKey = isset($_GET['metric_key']) ? trim((string)$_GET['metric_key']) : 'temperature';
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
$days = min(90, max(1, $days));

if ($sensorId <= 0) {
  json_response(['ok' => false, 'error' => 'Missing sensor_id']);
}

$allowedKeys = ['temperature', 'temp', 'feels_like', 'humidity', 'pressure', 'wind_speed', 'aqi', 'pm25', 'uv_index', 'precipitation_rate', 'solar_irradiance', 'dew_point', 'wind_gust'];
if (!in_array($metricKey, $allowedKeys, true)) {
  $metricKey = 'temperature';
}

if ($role !== 'admin' && $role !== 'superadmin') {
  $stmt = db()->prepare("SELECT 1 FROM authority_users au JOIN virtual_sensors vs ON (vs.latitude IS NOT NULL AND vs.longitude IS NOT NULL) WHERE au.user_id = ? AND vs.id = ? LIMIT 1");
  $stmt->execute([$uid, $sensorId]);
  if (!$stmt->fetch()) {
    $stmt = db()->prepare("SELECT 1 FROM virtual_sensors WHERE id = ? LIMIT 1");
    $stmt->execute([$sensorId]);
    if (!$stmt->fetch()) {
      json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
  }
}

$dateFrom = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

$pdo = db();
$data = [];
try {
  $stmt = $pdo->prepare("
    SELECT measured_at, metric_value, metric_unit
    FROM virtual_sensor_metric_history
    WHERE virtual_sensor_id = ? AND metric_key = ? AND measured_at >= ?
    ORDER BY measured_at ASC
    LIMIT 5000
  ");
  $stmt->execute([$sensorId, $metricKey, $dateFrom]);
  $byDay = [];
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
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $measuredAt = (string)($row['measured_at'] ?? '');
    if ($measuredAt === '') continue;
    $value = $row['metric_value'] !== null ? (float)$row['metric_value'] : null;
    if (in_array($metricKey, ['temperature', 'temp', 'feels_like', 'dew_point'], true)) {
      $value = $normalizeTempCelsius($value, $row['metric_unit'] ?? null);
    }
    if ($value === null) continue;
    $d = substr($measuredAt, 0, 10);
    if (!isset($byDay[$d])) $byDay[$d] = ['sum' => 0.0, 'cnt' => 0, 'last_at' => $measuredAt];
    $byDay[$d]['sum'] += $value;
    $byDay[$d]['cnt'] += 1;
    if ($measuredAt > $byDay[$d]['last_at']) $byDay[$d]['last_at'] = $measuredAt;
  }
  ksort($byDay);
  foreach ($byDay as $d => $agg) {
    if ($agg['cnt'] <= 0) continue;
    $data[] = [
      'date' => $d,
      'value' => round($agg['sum'] / $agg['cnt'], 1),
      'measured_at' => $agg['last_at'],
    ];
  }
} catch (Throwable $e) {
  json_response(['ok' => true, 'data' => []]);
}

json_response(['ok' => true, 'data' => $data]);
