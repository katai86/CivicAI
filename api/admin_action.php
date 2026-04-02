<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : 0;
$action = $body['action'] ?? '';

if ($id <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);
if (!in_array($action, ['approve','reject','delete'], true)) {
  json_response(['ok'=>false,'error'=>'Invalid action'], 400);
}

if ($action === 'delete') {
  $stmt = db()->prepare("DELETE FROM reports WHERE id=:id");
  $stmt->execute([':id'=>$id]);
  json_response(['ok'=>true,'message'=>'Deleted']);
}

$newStatus = ($action === 'approve') ? 'approved' : 'rejected';
$stmt = db()->prepare("UPDATE reports SET status=:st WHERE id=:id");
$stmt->execute([':st'=>$newStatus, ':id'=>$id]);

json_response(['ok'=>true,'message'=>($action==='approve'?'Approved':'Rejected')]);