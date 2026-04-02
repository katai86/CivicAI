<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

start_secure_session();

$isAdmin = !empty($_SESSION['admin_logged_in']);
$uid = current_user_id();
if (!$isAdmin && !$uid) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
  json_response(['ok' => false, 'error' => 'Invalid id'], 400);
}

$stmt = db()->prepare("
  SELECT a.id, a.stored_name, a.report_id, r.user_id
  FROM report_attachments a
  JOIN reports r ON r.id = a.report_id
  WHERE a.id = :id
  LIMIT 1
");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
  json_response(['ok' => false, 'error' => 'Attachment not found'], 404);
}
if (!$isAdmin && (int)$row['user_id'] !== (int)$uid) {
  json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

// Delete DB row first to prevent dangling references if filesystem fails
$del = db()->prepare("DELETE FROM report_attachments WHERE id = :id");
$del->execute([':id' => $id]);

// Best-effort file deletion
$stored = (string)$row['stored_name'];
if ($stored !== '') {
  $path = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $stored;
  if (is_file($path)) {
    @unlink($path);
  }
}

json_response(['ok' => true]);
