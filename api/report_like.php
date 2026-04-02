<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_user();
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid id'], 400);

$uid = (int)current_user_id();
if ($uid <= 0) json_response(['ok' => false, 'error' => t('api.auth_required')], 401);

try {
  $pdo = db();
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT id FROM report_likes WHERE report_id=:rid AND user_id=:uid LIMIT 1");
  $stmt->execute([':rid' => $id, ':uid' => $uid]);
  $liked = (bool)$stmt->fetchColumn();

  if ($liked) {
    $pdo->prepare("DELETE FROM report_likes WHERE report_id=:rid AND user_id=:uid")->execute([':rid'=>$id, ':uid'=>$uid]);
    $liked = false;
  } else {
    $pdo->prepare("INSERT INTO report_likes (report_id, user_id) VALUES (:rid, :uid)")->execute([':rid'=>$id, ':uid'=>$uid]);
    $liked = true;
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM report_likes WHERE report_id=:rid");
  $stmt->execute([':rid' => $id]);
  $count = (int)$stmt->fetchColumn();

  $pdo->commit();
  json_response(['ok' => true, 'liked' => $liked, 'count' => $count]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => t('api.db_error')], 500);
}
