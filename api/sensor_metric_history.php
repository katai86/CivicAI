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
    SELECT DATE(measured_at) AS d, AVG(metric_value) AS v, MAX(measured_at) AS last_at
    FROM virtual_sensor_metric_history
    WHERE virtual_sensor_id = ? AND metric_key = ? AND measured_at >= ?
    GROUP BY DATE(measured_at)
    ORDER BY d ASC
    LIMIT 90
  ");
  $stmt->execute([$sensorId, $metricKey, $dateFrom]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data[] = [
      'date' => $row['d'],
      'value' => $row['v'] !== null ? (float)$row['v'] : null,
      'measured_at' => $row['last_at'],
    ];
  }
} catch (Throwable $e) {
  json_response(['ok' => true, 'data' => []]);
}

json_response(['ok' => true, 'data' => $data]);
