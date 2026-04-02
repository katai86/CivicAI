<?php
/**
 * IoT szenzor szinkronizálás indítása admin munkamenetből (Beépülő modulok → Szinkronizálás most).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

try {
  require_admin();

  if (get_module_setting('iot', 'enabled') !== '1') {
    json_response(['ok' => false, 'error' => 'IoT modul nincs bekapcsolva']);
    exit;
  }

  $tablesOk = false;
  try {
    db()->query("SELECT 1 FROM virtual_sensors LIMIT 1");
    db()->query("SELECT 1 FROM virtual_sensor_metrics_latest LIMIT 1");
    db()->query("SELECT 1 FROM virtual_sensor_provider_logs LIMIT 1");
    $tablesOk = true;
  } catch (Throwable $e) {}

  if (!$tablesOk) {
    json_response(['ok' => false, 'error' => 'IoT táblák hiányoznak. Futtasd a migrációt (sql/01_consolidated_migrations.sql).']);
    exit;
  }

  require_once __DIR__ . '/iot/run_sync.php';

  $result = run_iot_sync();
  json_response($result);
} catch (Throwable $e) {
  json_response([
    'ok' => false,
    'error' => 'Hiba: ' . $e->getMessage(),
    'file' => basename($e->getFile()),
    'line' => $e->getLine(),
  ]);
}
