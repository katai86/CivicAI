<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rid <= 0) {
  json_response(['ok' => false, 'error' => 'Invalid id'], 400);
}

// Csak saját ügy
$stmt = db()->prepare("\n  SELECT\n    id, category, title, description, status, created_at,\n    reviewed_at, reviewer,\n    address_approx, house_number_approx, road, suburb, city, postcode,\n    lat, lng,\n    reporter_name, reporter_is_anonymous,\n    notify_enabled, notify_token\n  FROM reports\n  WHERE id = :id AND user_id = :uid\n  LIMIT 1\n");
$stmt->execute([':id' => $rid, ':uid' => $userId]);
$r = $stmt->fetch();

if (!$r) {
  json_response(['ok' => false, 'error' => 'Not found'], 404);
}

$logs = [];
try {
  $logStmt = db()->prepare("\n    SELECT old_status, new_status, note, changed_by, changed_at\n    FROM report_status_log\n    WHERE report_id = :id\n    ORDER BY changed_at DESC, id DESC\n    LIMIT 200\n  ");
  $logStmt->execute([':id' => $rid]);
  $logs = $logStmt->fetchAll();
} catch (Throwable $e) {
  // Ha a tábla még nincs (vagy nincs jogosultság), akkor üresen hagyjuk.
  $logs = [];
}

json_response([
  'ok' => true,
  'report' => $r,
  'log' => $logs,
]);
