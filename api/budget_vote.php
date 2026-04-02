<?php
/**
 * M4 Participatory Budgeting – projekt szavazás (toggle, mint idea_vote).
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

  $stmt = $pdo->prepare("SELECT id, status, authority_id FROM budget_projects WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  $project = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$project) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => t('api.report_not_found')], 404);
  }
  if (($project['status'] ?? '') !== 'published') {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => t('budget.voting_closed')], 400);
  }
  $aid = (int)($project['authority_id'] ?? 0);
  if ($aid > 0) {
    $st = $pdo->prepare("SELECT voting_closed FROM budget_settings WHERE authority_id = ? LIMIT 1");
    $st->execute([$aid]);
    $votingClosed = (int)($st->fetchColumn() ?: 0);
    if ($votingClosed === 1) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => t('budget.voting_closed')], 400);
    }
  }

  $stmt = $pdo->prepare("SELECT 1 FROM budget_votes WHERE project_id = :pid AND user_id = :uid LIMIT 1");
  $stmt->execute([':pid' => $id, ':uid' => $uid]);
  $voted = (bool)$stmt->fetchColumn();

  if ($voted) {
    $pdo->prepare("DELETE FROM budget_votes WHERE project_id = :pid AND user_id = :uid")
      ->execute([':pid' => $id, ':uid' => $uid]);
    $voted = false;
  } else {
    $pdo->prepare("INSERT INTO budget_votes (project_id, user_id) VALUES (:pid, :uid)")
      ->execute([':pid' => $id, ':uid' => $uid]);
    $voted = true;
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM budget_votes WHERE project_id = :pid");
  $stmt->execute([':pid' => $id]);
  $count = (int)$stmt->fetchColumn();

  $pdo->commit();
  json_response(['ok' => true, 'voted' => $voted, 'count' => $count]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => t('api.db_error')], 500);
}
