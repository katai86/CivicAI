<?php
/**
 * Részvételi költségvetés – gov user: projektek listája (saját hatóság), létrehozás, státusz.
 * GET: projektek a user hatóságaihoz; POST: create (authority_id = első hatóság), update, set_status (published/closed).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
require_user();

$role = current_user_role() ?: '';
if (!in_array($role, ['admin', 'superadmin', 'govuser'], true)) {
  json_response(['ok' => false, 'error' => t('api.unauthorized')], 401);
}

$uid = (int)current_user_id();
if ($uid <= 0) {
  json_response(['ok' => false, 'error' => t('api.unauthorized')], 401);
}

$authorityIds = [];
if (in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  if ($aid > 0) {
    $authorityIds = [$aid];
  } else {
    try {
      $authorityIds = array_map('intval', db()->query("SELECT id FROM authorities ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
  }
} else {
  try {
    $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ?");
    $stmt->execute([$uid]);
    $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {}
}

$scopeAids = array_filter($authorityIds, fn($id) => $id > 0);
$firstAid = !empty($scopeAids) ? (int)$scopeAids[0] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $projects = [];
  $settings = null;
  try {
    $sql = "
      SELECT p.id, p.title, p.description, p.budget, p.status, p.authority_id, p.created_at, p.submitted_by,
             a.name AS authority_name,
             (SELECT COUNT(*) FROM budget_votes v WHERE v.project_id = p.id) AS vote_count
      FROM budget_projects p
      LEFT JOIN authorities a ON a.id = p.authority_id
      WHERE 1=1
    ";
    $params = [];
    if (!empty($scopeAids)) {
      $ph = implode(',', array_fill(0, count($scopeAids), '?'));
      $sql .= " AND p.authority_id IN ($ph)";
      $params = array_values($scopeAids);
    }
    $sql .= " ORDER BY p.created_at DESC LIMIT 500";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($projects as &$row) {
      $row['budget'] = (float)($row['budget'] ?? 0);
      $row['vote_count'] = (int)($row['vote_count'] ?? 0);
    }
    unset($row);
    if ($firstAid > 0) {
      $st = db()->prepare("SELECT frame_amount, conditions_text, description, voting_closed FROM budget_settings WHERE authority_id = ?");
      $st->execute([$firstAid]);
      $settings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($settings !== null) {
        $settings['frame_amount'] = $settings['frame_amount'] !== null ? (float)$settings['frame_amount'] : null;
        $settings['voting_closed'] = (int)($settings['voting_closed'] ?? 0);
      }
    }
  } catch (Throwable $e) {
    // táblák hiányozhatnak
  }
  json_response(['ok' => true, 'projects' => $projects, 'authority_ids' => array_values($scopeAids), 'first_authority_id' => $firstAid, 'settings' => $settings]);
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
  $authorityId = $firstAid; // govuser: csak a saját hatóság
  if (in_array($role, ['admin', 'superadmin'], true) && isset($body['authority_id'])) {
    $authorityId = (int)$body['authority_id'];
    if ($authorityId > 0 && !in_array($authorityId, $scopeAids, true)) $authorityId = $firstAid;
  }
  if ($title === '') {
    json_response(['ok' => false, 'error' => t('api.facility_name_required') ?: 'Cím kötelező.'], 400);
  }
  try {
    db()->prepare("
      INSERT INTO budget_projects (title, description, budget, status, authority_id, submitted_by)
      VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$title, $description, $budget, $status, $authorityId > 0 ? $authorityId : null, $uid]);
    json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  exit;
}

if ($action === 'update' || $action === 'set_status') {
  $id = isset($body['id']) ? (int)$body['id'] : 0;
  if ($id <= 0) {
    json_response(['ok' => false, 'error' => t('common.error_invalid_data')], 400);
  }
  try {
    $stmt = db()->prepare("SELECT id, authority_id FROM budget_projects WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      json_response(['ok' => false, 'error' => t('common.error_invalid_data')], 404);
    }
    $pidAid = (int)($row['authority_id'] ?? 0);
    $allowed = in_array($role, ['admin', 'superadmin'], true) || in_array($pidAid, $scopeAids, true);
    if (!$allowed) {
      json_response(['ok' => false, 'error' => t('common.error_no_permission')], 403);
    }
    if ($action === 'set_status') {
      $newStatus = isset($body['status']) && in_array($body['status'], $allowedStatuses, true) ? $body['status'] : '';
      if ($newStatus === '') {
        json_response(['ok' => false, 'error' => t('common.error_invalid_data')], 400);
      }
      db()->prepare("UPDATE budget_projects SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $id]);
      json_response(['ok' => true]);
      exit;
    }
    $title = trim((string)($body['title'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $budget = is_numeric($body['budget'] ?? null) ? (float)$body['budget'] : 0;
    $status = isset($body['status']) && in_array($body['status'], $allowedStatuses, true) ? $body['status'] : 'draft';
    if ($title === '') {
      json_response(['ok' => false, 'error' => t('api.facility_name_required') ?: 'Cím kötelező.'], 400);
    }
    db()->prepare("UPDATE budget_projects SET title = ?, description = ?, budget = ?, status = ?, updated_at = NOW() WHERE id = ?")
      ->execute([$title, $description, $budget, $status, $id]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  exit;
}

if ($action === 'save_settings') {
  if ($firstAid <= 0) {
    json_response(['ok' => false, 'error' => t('gov.no_authority_assigned')], 400);
  }
  $frameAmount = isset($body['frame_amount']) && (is_numeric($body['frame_amount']) || $body['frame_amount'] === '') ? ($body['frame_amount'] === '' ? null : (float)$body['frame_amount']) : null;
  $conditionsText = isset($body['conditions_text']) ? trim((string)$body['conditions_text']) : null;
  $description = isset($body['description']) ? trim((string)$body['description']) : null;
  try {
    $pdo = db();
    $pdo->prepare("
      INSERT INTO budget_settings (authority_id, frame_amount, conditions_text, description, voting_closed)
      VALUES (?, ?, ?, ?, 0)
      ON DUPLICATE KEY UPDATE frame_amount = VALUES(frame_amount), conditions_text = VALUES(conditions_text), description = VALUES(description), updated_at = NOW()
    ")->execute([$firstAid, $frameAmount, $conditionsText, $description]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    if (function_exists('log_error')) {
      log_error('gov_budget save_settings: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    $msg = t('common.error_save_failed');
    if ($e->getCode() == 1146 || strpos((string)$e->getMessage(), 'budget_settings') !== false) {
      $msg = 'Részvételi költségvetés beállítások tábla hiányzik. Futtasd a migrációt (sql/01_consolidated_migrations.sql – budget_settings).';
    }
    json_response(['ok' => false, 'error' => $msg], 500);
  }
  exit;
}

if ($action === 'close_voting') {
  if ($firstAid <= 0) {
    json_response(['ok' => false, 'error' => t('gov.no_authority_assigned')], 400);
  }
  try {
    db()->prepare("INSERT INTO budget_settings (authority_id, voting_closed) VALUES (?, 1) ON DUPLICATE KEY UPDATE voting_closed = 1, updated_at = NOW()")->execute([$firstAid]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    if (function_exists('log_error')) {
      log_error('gov_budget close_voting: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    $msg = t('common.error_save_failed');
    if ($e->getCode() == 1146 || strpos((string)$e->getMessage(), 'budget_settings') !== false) {
      $msg = 'Részvételi költségvetés beállítások tábla hiányzik. Futtasd a migrációt (sql/01_consolidated_migrations.sql – budget_settings).';
    }
    json_response(['ok' => false, 'error' => $msg], 500);
  }
  exit;
}

json_response(['ok' => false, 'error' => t('api.invalid_action')], 400);
