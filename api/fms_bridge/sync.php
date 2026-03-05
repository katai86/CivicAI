<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

start_secure_session();

// admin-only or token
if (!empty($_SESSION['user_role']) && in_array((string)$_SESSION['user_role'], ['admin','superadmin'], true)) {
  // ok
} else {
  if (!defined('ADMIN_TOKEN') || !ADMIN_TOKEN || !isset($_GET['token']) || !hash_equals((string)ADMIN_TOKEN, (string)$_GET['token'])) {
    json_response(['ok'=>false,'error'=>'Unauthorized'], 401);
  }
}

if (!fms_enabled()) {
  json_response(['ok'=>false,'error'=>'FMS not configured'], 400);
}

$now = gmdate('c');
$last = null;
try {
  $stmt = db()->query("SELECT last_requests_sync_at FROM fms_sync_log ORDER BY id DESC LIMIT 1");
  $last = $stmt->fetchColumn() ?: null;
} catch (Throwable $e) { /* ignore */ }

if (!$last) {
  $last = gmdate('c', strtotime('-7 days'));
}

$query = [
  'jurisdiction_id' => (string)FMS_OPEN311_JURISDICTION,
  'start_date' => $last,
  'end_date' => $now,
  'max_requests' => 1000,
];

$resp = fms_open311_get('/open311/v2/requests.json', $query);
if (!$resp['ok']) {
  json_response(['ok'=>false,'error'=>$resp['error']], 502);
}

$items = is_array($resp['data']) ? $resp['data'] : [];
$updated = 0;

foreach ($items as $it) {
  $sid = isset($it['service_request_id']) ? (string)$it['service_request_id'] : '';
  if ($sid === '') continue;
  $status = isset($it['status']) ? (string)$it['status'] : '';
  $updatedAt = isset($it['updated_datetime']) ? (string)$it['updated_datetime'] : null;

  $map = [
    'open' => 'in_progress',
    'closed' => 'solved',
  ];
  if (!isset($map[$status])) continue;
  $newStatus = $map[$status];

  $stmt = db()->prepare("SELECT report_id, last_status FROM fms_reports WHERE open311_service_request_id = :sid LIMIT 1");
  $stmt->execute([':sid'=>$sid]);
  $row = $stmt->fetch();
  if (!$row) continue;

  $reportId = (int)$row['report_id'];
  $prevStatus = (string)($row['last_status'] ?? '');

  if ($prevStatus !== $status) {
    db()->prepare("UPDATE reports SET status = :st WHERE id = :id")->execute([':st'=>$newStatus, ':id'=>$reportId]);
    try {
      db()->prepare("INSERT INTO report_status_log (report_id, old_status, new_status, note, changed_by)
                     VALUES (:rid, :old, :new, :note, :by)")
        ->execute([
          ':rid'=>$reportId,
          ':old'=>$prevStatus ?: null,
          ':new'=>$newStatus,
          ':note'=>'FMS sync',
          ':by'=>'fms'
        ]);
    } catch (Throwable $e) { /* ignore */ }
    $updated++;
  }

  db()->prepare("UPDATE fms_reports SET last_status = :st, last_updated_at = :ua WHERE open311_service_request_id = :sid")
    ->execute([':st'=>$status, ':ua'=>$updatedAt ?: null, ':sid'=>$sid]);
}

db()->prepare("INSERT INTO fms_sync_log (last_requests_sync_at) VALUES (:t)")
  ->execute([':t'=>gmdate('Y-m-d H:i:s')]);

json_response(['ok'=>true,'count'=>count($items),'updated'=>$updated]);
