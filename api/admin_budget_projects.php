<?php
/**
 * M4 Participatory Budgeting – admin CRUD (projektek).
 * GET: lista (összes projekt vote_count-tal), authorities a legördülőhöz.
 * POST: action = create | update | delete; create/update: title, description, budget, status, authority_id.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $projects = [];
  $authorities = [];
  try {
    $projects = db()->query("
      SELECT p.id, p.title, p.description, p.budget, p.status, p.authority_id, p.created_at,
             a.name AS authority_name,
             (SELECT COUNT(*) FROM budget_votes v WHERE v.project_id = p.id) AS vote_count
      FROM budget_projects p
      LEFT JOIN authorities a ON a.id = p.authority_id
      ORDER BY p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($projects as &$row) {
      $row['budget'] = (float)($row['budget'] ?? 0);
      $row['vote_count'] = (int)($row['vote_count'] ?? 0);
    }
    unset($row);
    $authorities = db()->query("SELECT id, name, city FROM authorities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    // táblák hiányozhatnak
  }
  json_response(['ok' => true, 'projects' => $projects, 'authorities' => $authorities]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$body = read_json_body();
$action = safe_str($body['action'] ?? null, 32);
$allowedStatuses = ['draft', 'published', 'closed'];

if ($action === 'create') {
  $title = trim((string)($body['title'] ?? ''));
  $description = trim((string)($body['description'] ?? ''));
  $budget = is_numeric($body['budget'] ?? null) ? (float)$body['budget'] : 0;
  $status = isset($body['status']) && in_array($body['status'], $allowedStatuses, true) ? $body['status'] : 'draft';
  $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
  if ($title === '') {
    json_response(['ok' => false, 'error' => t('api.facility_name_required')], 400);
  }
  try {
    $pdo = db();
    $pdo->prepare("
      INSERT INTO budget_projects (title, description, budget, status, authority_id)
      VALUES (:title, :desc, :budget, :status, :aid)
    ")->execute([
      ':title' => $title,
      ':desc' => $description,
      ':budget' => $budget,
      ':status' => $status,
      ':aid' => $authorityId > 0 ? $authorityId : null,
    ]);
    $id = (int)$pdo->lastInsertId();
    json_response(['ok' => true, 'id' => $id]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  exit;
}

if ($action === 'update') {
  $id = isset($body['id']) ? (int)$body['id'] : 0;
  $title = trim((string)($body['title'] ?? ''));
  $description = trim((string)($body['description'] ?? ''));
  $budget = is_numeric($body['budget'] ?? null) ? (float)$body['budget'] : 0;
  $status = isset($body['status']) && in_array($body['status'], $allowedStatuses, true) ? $body['status'] : 'draft';
  $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
  if ($id <= 0 || $title === '') {
    json_response(['ok' => false, 'error' => t('common.error_invalid_data')], 400);
  }
  try {
    db()->prepare("
      UPDATE budget_projects SET title = ?, description = ?, budget = ?, status = ?, authority_id = ?, updated_at = NOW()
      WHERE id = ?
    ")->execute([$title, $description, $budget, $status, $authorityId > 0 ? $authorityId : null, $id]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  exit;
}

if ($action === 'delete') {
  $id = isset($body['id']) ? (int)$body['id'] : 0;
  if ($id <= 0) {
    json_response(['ok' => false, 'error' => t('common.error_invalid_data')], 400);
  }
  try {
    db()->prepare("DELETE FROM budget_projects WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  exit;
}

json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 400);
