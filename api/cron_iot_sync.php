<?php
/**
 * IoT virtual sensors sync – station discovery and latest metrics from configured providers.
 * Call via cron (e.g. every hour). Optional: ?token=ADMIN_TOKEN for auth.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if (defined('ADMIN_TOKEN') && ADMIN_TOKEN !== '') {
  $token = $_GET['token'] ?? '';
  if (!hash_equals((string)ADMIN_TOKEN, (string)$token)) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
}

if (get_module_setting('iot', 'enabled') !== '1') {
  json_response(['ok' => true, 'message' => 'IoT module disabled', 'providers' => []]);
}

$db = db();
$tablesOk = false;
try {
  $db->query("SELECT 1 FROM virtual_sensors LIMIT 1");
  $db->query("SELECT 1 FROM virtual_sensor_metrics_latest LIMIT 1");
  $db->query("SELECT 1 FROM virtual_sensor_provider_logs LIMIT 1");
  $tablesOk = true;
} catch (Throwable $e) {}

if (!$tablesOk) {
  json_response(['ok' => false, 'error' => 'IoT tables missing']);
}

require_once __DIR__ . '/iot/run_sync.php';
json_response(run_iot_sync());
