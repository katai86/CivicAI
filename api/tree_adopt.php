<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => t('api.unauthorized')], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$treeId = isset($input['tree_id']) ? (int)$input['tree_id'] : 0;
$action = isset($input['action']) ? trim((string)$input['action']) : 'adopt';
if ($treeId <= 0 || !in_array($action, ['adopt','cancel'], true)) {
  json_response(['ok' => false, 'error' => 'Invalid data'], 400);
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT id, adopted_by_user_id FROM trees WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $treeId]);
  $tree = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$tree) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => t('api.tree_not_found')], 404);
  }

  $currentAdopter = $tree['adopted_by_user_id'] !== null ? (int)$tree['adopted_by_user_id'] : null;

  if ($action === 'adopt') {
    if ($currentAdopter !== null && $currentAdopter !== $uid) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => t('api.tree_already_adopted')], 409);
    }
    $pdo->prepare("INSERT INTO tree_adoptions (tree_id, user_id, status) VALUES (:tid, :uid, 'active')
                   ON DUPLICATE KEY UPDATE status = 'active', adopted_at = CURRENT_TIMESTAMP")
        ->execute([':tid' => $treeId, ':uid' => $uid]);
    $pdo->prepare("UPDATE trees SET adopted_by_user_id = :uid WHERE id = :id")
        ->execute([':uid' => $uid, ':id' => $treeId]);

    // XP – egyszeri nagyobb jutalom adott fára
    try {
      add_user_xp_once($uid, 20, 'adopt_tree_' . $treeId, 'adopting_tree', null);
    } catch (Throwable $e) {
      // ignore XP errors
    }
  } else {
    // cancel
    $pdo->prepare("UPDATE tree_adoptions SET status = 'inactive' WHERE tree_id = :tid AND user_id = :uid")
        ->execute([':tid' => $treeId, ':uid' => $uid]);
    if ($currentAdopter === $uid) {
      $pdo->prepare("UPDATE trees SET adopted_by_user_id = NULL WHERE id = :id")
          ->execute([':id' => $treeId]);
    }
  }

  $pdo->commit();

  json_response([
    'ok' => true,
    'tree_id' => $treeId,
    'adopted' => $action === 'adopt',
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  log_error('tree_adopt error: ' . $e->getMessage());
  json_response(['ok' => false, 'error' => t('api.server_error')], 500);
}

