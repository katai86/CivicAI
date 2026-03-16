<?php
/**
 * IoT mérőeszközök listája – Gov dashboard.
 * GET: authority_id (opcionális). Válasz: ok, devices [ { id, name, type, lat, lng, visible_on_map, last_value } ].
 * Jogosultság: admin vagy gov user (saját hatóság scope).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
  echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
if (!$isAdmin && $authorityId > 0) {
  $stmt = db()->prepare("SELECT 1 FROM authority_users WHERE user_id = ? AND authority_id = ?");
  $stmt->execute([$uid, $authorityId]);
  if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// Placeholder: nincs még iot_devices tábla; üres lista. Később: SELECT * FROM iot_devices WHERE authority_id = ? AND ...
$devices = [];

echo json_encode([
  'ok' => true,
  'devices' => $devices,
], JSON_UNESCAPED_UNICODE);
