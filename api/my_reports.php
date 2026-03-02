<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$stmt = db()->prepare("
  SELECT
    id, category, title, description, status, created_at,
    address_approx, road, suburb, city, postcode,
    lat, lng,
    notify_enabled, notify_token
  FROM reports
  WHERE user_id = :uid
  ORDER BY created_at DESC
  LIMIT 1000
");
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
  $r['case'] = case_number((int)$r['id'], (string)$r['created_at']);
  $r['track_url'] = ($r['notify_enabled'] == 1 && !empty($r['notify_token']))
    ? app_url('/case.php?token=' . rawurlencode((string)$r['notify_token']))
    : null;
}
unset($r);

json_response(['ok' => true, 'data' => $rows]);
