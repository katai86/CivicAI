<?php
/**
 * IoT szenzor szinkronizálás a gov user hatóságának területére.
 * Csak a bejelentkezett gov user hatósága(i) scope – így pl. Budapest minden WeatherXM állomás lehúzható.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = current_user_role() ?: '';
if ($uid <= 0 || ($role !== 'govuser' && !in_array($role, ['admin', 'superadmin'], true))) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if (get_module_setting('iot', 'enabled') !== '1') {
  json_response(['ok' => false, 'error' => t('gov.iot_module_disabled')]);
}

$tablesOk = false;
try {
  db()->query("SELECT 1 FROM virtual_sensors LIMIT 1");
  db()->query("SELECT 1 FROM virtual_sensor_metrics_latest LIMIT 1");
  db()->query("SELECT 1 FROM virtual_sensor_provider_logs LIMIT 1");
  $tablesOk = true;
} catch (Throwable $e) {}

if (!$tablesOk) {
  json_response(['ok' => false, 'error' => t('gov.iot_tables_missing')]);
}

$authorityId = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $authorityId = isset($_POST['authority_id']) ? (int)$_POST['authority_id'] : (isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0);
} else {
  $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id LIMIT 1");
  $stmt->execute([$uid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $authorityId = $row ? (int)$row['authority_id'] : 0;
}

if ($authorityId <= 0) {
  json_response(['ok' => false, 'error' => t('gov.no_authority_assigned')]);
}

require_once __DIR__ . '/iot/run_sync.php';

try {
  $result = run_iot_sync(['authority_id' => $authorityId]);
  json_response($result);
} catch (Throwable $e) {
  json_response([
    'ok' => false,
    'error' => 'Hiba: ' . $e->getMessage(),
  ]);
}
