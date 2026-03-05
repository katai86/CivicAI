<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_user();
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
  $data = read_json_body();
} else {
  $data = $_POST;
}

$action = safe_str($data['action'] ?? null, 24);
$targetId = isset($data['target_id']) ? (int)$data['target_id'] : 0;
$requestId = isset($data['request_id']) ? (int)$data['request_id'] : 0;

$uid = (int)current_user_id();
if ($uid <= 0) json_response(['ok' => false, 'error' => 'Auth required'], 401);

try {
  $pdo = db();

  if ($action === 'send') {
    if ($targetId <= 0 || $targetId === $uid) json_response(['ok'=>false,'error'=>'Invalid target'], 400);
    $pdo->prepare("
      INSERT INTO friend_requests (from_user_id, to_user_id, status)
      VALUES (:from, :to, 'pending')
      ON DUPLICATE KEY UPDATE status='pending'
    ")->execute([':from'=>$uid, ':to'=>$targetId]);
    json_response(['ok' => true]);
  }

  if ($action === 'cancel') {
    if ($targetId <= 0) json_response(['ok'=>false,'error'=>'Invalid target'], 400);
    $pdo->prepare("DELETE FROM friend_requests WHERE from_user_id=:from AND to_user_id=:to")->execute([':from'=>$uid, ':to'=>$targetId]);
    json_response(['ok' => true]);
  }

  if ($action === 'accept') {
    if ($requestId <= 0) json_response(['ok'=>false,'error'=>'Invalid request'], 400);
    $stmt = $pdo->prepare("SELECT * FROM friend_requests WHERE id=:id AND to_user_id=:uid AND status='pending' LIMIT 1");
    $stmt->execute([':id'=>$requestId, ':uid'=>$uid]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) json_response(['ok'=>false,'error'=>'Not found'], 404);

    $from = (int)$req['from_user_id'];
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE friend_requests SET status='accepted' WHERE id=:id")->execute([':id'=>$requestId]);
    $pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_user_id) VALUES (:a,:b),(:b,:a)")
        ->execute([':a'=>$uid, ':b'=>$from]);
    $pdo->commit();
    json_response(['ok' => true]);
  }

  if ($action === 'decline') {
    if ($requestId <= 0) json_response(['ok'=>false,'error'=>'Invalid request'], 400);
    $pdo->prepare("UPDATE friend_requests SET status='declined' WHERE id=:id AND to_user_id=:uid")
        ->execute([':id'=>$requestId, ':uid'=>$uid]);
    json_response(['ok' => true]);
  }

  if ($action === 'remove') {
    if ($targetId <= 0) json_response(['ok'=>false,'error'=>'Invalid target'], 400);
    $pdo->prepare("DELETE FROM friends WHERE (user_id=:a AND friend_user_id=:b) OR (user_id=:b AND friend_user_id=:a)")
        ->execute([':a'=>$uid, ':b'=>$targetId]);
    json_response(['ok' => true]);
  }

  json_response(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => 'DB error'], 500);
}
