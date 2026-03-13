<?php
/**
 * M3 Ideation – ötletre szavazás (toggle, mint report_like).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();
require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => t('api.auth_required')], 401);
}

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
  json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT id FROM ideas WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  if (!$stmt->fetchColumn()) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => t('api.report_not_found')], 404);
  }

  $stmt = $pdo->prepare("SELECT 1 FROM idea_votes WHERE idea_id = :iid AND user_id = :uid LIMIT 1");
  $stmt->execute([':iid' => $id, ':uid' => $uid]);
  $voted = (bool)$stmt->fetchColumn();

  if ($voted) {
    $pdo->prepare("DELETE FROM idea_votes WHERE idea_id = :iid AND user_id = :uid")
      ->execute([':iid' => $id, ':uid' => $uid]);
    $voted = false;
  } else {
    $pdo->prepare("INSERT INTO idea_votes (idea_id, user_id) VALUES (:iid, :uid)")
      ->execute([':iid' => $id, ':uid' => $uid]);
    $voted = true;
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM idea_votes WHERE idea_id = :iid");
  $stmt->execute([':iid' => $id]);
  $count = (int)$stmt->fetchColumn();

  $pdo->commit();
  json_response(['ok' => true, 'voted' => $voted, 'count' => $count]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => t('api.db_error')], 500);
}
