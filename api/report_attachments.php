<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$isAdmin = !empty($_SESSION['admin_logged_in']);
$uid = current_user_id();
if (!$isAdmin && !$uid) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}
$rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rid <= 0) {
  json_response(['ok'=>false,'error'=>'Invalid id'], 400);
}

$chk = db()->prepare('SELECT id, user_id FROM reports WHERE id=:id LIMIT 1');
$chk->execute([':id'=>$rid]);
$row = $chk->fetch();
if (!$row) {
  json_response(['ok'=>false,'error'=>'Report not found'], 404);
}
if (!$isAdmin && (int)$row['user_id'] !== (int)$uid) {
  json_response(['ok'=>false,'error'=>'Forbidden'], 403);
}

$stmt = db()->prepare('SELECT id, filename, mime, size_bytes, created_at, stored_name FROM report_attachments WHERE report_id=:rid ORDER BY created_at DESC, id DESC');
$stmt->execute([':rid'=>$rid]);
$rows = $stmt->fetchAll() ?: [];

$out = [];
foreach ($rows as $r) {
  $out[] = [
    'id' => (int)$r['id'],
    'filename' => (string)$r['filename'],
    'mime' => (string)$r['mime'],
    'size_bytes' => (int)$r['size_bytes'],
    'created_at' => (string)$r['created_at'],
    'url' => UPLOAD_PUBLIC . '/' . rawurlencode((string)$r['stored_name']),
  ];
}

json_response(['ok'=>true,'data'=>$out]);
