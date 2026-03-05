<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_user();
start_secure_session();

$uid = (int)current_user_id();
if ($uid <= 0) json_response(['ok' => false, 'error' => 'Auth required'], 401);

$friends = [];
$incoming = [];
$outgoing = [];

try {
  $stmt = db()->prepare("
    SELECT u.id, u.display_name, u.email, u.level
    FROM friends f
    JOIN users u ON u.id = f.friend_user_id
    WHERE f.user_id = :uid
    ORDER BY u.display_name ASC
  ");
  $stmt->execute([':uid' => $uid]);
  $friends = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stmt = db()->prepare("
    SELECT fr.id, u.id AS user_id, u.display_name, u.email
    FROM friend_requests fr
    JOIN users u ON u.id = fr.from_user_id
    WHERE fr.to_user_id = :uid AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
  ");
  $stmt->execute([':uid' => $uid]);
  $incoming = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stmt = db()->prepare("
    SELECT fr.id, u.id AS user_id, u.display_name, u.email
    FROM friend_requests fr
    JOIN users u ON u.id = fr.to_user_id
    WHERE fr.from_user_id = :uid AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
  ");
  $stmt->execute([':uid' => $uid]);
  $outgoing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_response(['ok' => true, 'friends' => $friends, 'incoming' => $incoming, 'outgoing' => $outgoing]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'DB error'], 500);
}
