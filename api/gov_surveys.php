<?php
/**
 * M8 – Gov felmérések kezelés: list, create/update, eredmények.
 * GET: list (authority scope), GET id=X: egy felmérés kérdésekkel, GET id=X&results=1: eredmények.
 * POST: action=save survey + questions; action=set_status (draft|active|closed).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_gov_or_admin();

function gov_surveys_tables_exist(): bool {
  try {
    $r = db()->query("SHOW TABLES LIKE 'surveys'");
    return $r && $r->rowCount() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$role = current_user_role();
$uid = current_user_id() ? (int)current_user_id() : 0;
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
  if ($uid > 0) {
    try {
      $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ?");
      $stmt->execute([$uid]);
      $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
  }
}

$scopeWhere = '1=1';
$scopeParams = [];
if (!empty($authorityIds)) {
  $scopeWhere = 's.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')';
  $scopeParams = $authorityIds;
} elseif (!in_array($role, ['admin', 'superadmin'], true)) {
  $scopeWhere = '1=0';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (!gov_surveys_tables_exist()) {
    json_response(['ok' => true, 'data' => []]);
  }
  $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $results = !empty($_GET['results']);

  try {
  if ($id && $results) {
    $stmt = db()->prepare("SELECT id, title FROM surveys WHERE id = ? AND ($scopeWhere)");
    $stmt->execute(array_merge([$id], $scopeParams));
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
      json_response(['ok' => false, 'error' => t('survey.not_found')], 404);
    }
    $stmt = db()->prepare("SELECT id, question_text, question_type, sort_order, options_json FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id");
    $stmt->execute([$id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt = db()->prepare("SELECT response_json FROM survey_responses WHERE survey_id = ?");
    $stmt->execute([$id]);
    $responses = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $parsed = [];
    foreach ($responses as $json) {
      $dec = json_decode($json, true);
      if (is_array($dec)) $parsed[] = $dec;
    }
    $aggregated = [];
    foreach ($questions as $q) {
      $qid = (int)$q['id'];
      $agg = ['question_id' => $qid, 'question_text' => $q['question_text'], 'question_type' => $q['question_type'], 'answers' => [], 'total' => 0];
      foreach ($parsed as $r) {
        if (!isset($r[$qid])) continue;
        $agg['total']++;
        $v = $r[$qid];
        $key = is_array($v) ? json_encode($v) : (string)$v;
        if (!isset($agg['answers'][$key])) $agg['answers'][$key] = 0;
        $agg['answers'][$key]++;
      }
      $aggregated[] = $agg;
    }
    json_response(['ok' => true, 'survey' => $survey, 'questions' => $questions, 'response_count' => count($parsed), 'aggregated' => $aggregated]);
  }

  if ($id) {
    $stmt = db()->prepare("SELECT s.*, a.name AS authority_name FROM surveys s LEFT JOIN authorities a ON a.id = s.authority_id WHERE s.id = ? AND ($scopeWhere)");
    $stmt->execute(array_merge([$id], $scopeParams));
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
      json_response(['ok' => false, 'error' => t('survey.not_found')], 404);
    }
    $stmt = db()->prepare("SELECT id, question_text, question_type, sort_order, options_json FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id");
    $stmt->execute([$id]);
    $survey['questions'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt = db()->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?");
    $stmt->execute([$id]);
    $survey['response_count'] = (int)$stmt->fetchColumn();
    json_response(['ok' => true, 'data' => $survey]);
  }

  $stmt = db()->prepare("
    SELECT s.id, s.title, s.description, s.authority_id, s.starts_at, s.ends_at, s.status, s.created_at,
      a.name AS authority_name,
      (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = s.id) AS response_count
    FROM surveys s
    LEFT JOIN authorities a ON a.id = s.authority_id
    WHERE $scopeWhere
    ORDER BY s.created_at DESC
  ");
  $stmt->execute($scopeParams);
  $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($list as &$row) {
    $row['response_count'] = (int)($row['response_count'] ?? 0);
  }
  unset($row);
  $firstAid = !empty($authorityIds) ? (int)$authorityIds[0] : 0;
  json_response(['ok' => true, 'data' => $list, 'first_authority_id' => $firstAid]);
  } catch (Throwable $e) {
    if (function_exists('log_error')) log_error('gov_surveys GET: ' . $e->getMessage());
    json_response(['ok' => true, 'data' => []]);
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
if (!gov_surveys_tables_exist()) {
  json_response(['ok' => false, 'error' => t('survey.migration_required')], 503);
}

$input = $_POST;
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
  $input = json_decode(file_get_contents('php://input'), true) ?: [];
}
$action = $input['action'] ?? '';

if ($action === 'set_status') {
  $id = (int)($input['id'] ?? 0);
  $status = trim((string)($input['status'] ?? ''));
  if (!in_array($status, ['draft', 'active', 'closed'], true)) {
    json_response(['ok' => false, 'error' => t('common.error_invalid_data')]);
  }
  $stmt = db()->prepare("SELECT id FROM surveys WHERE id = ? AND ($scopeWhere)");
  $stmt->execute(array_merge([$id], $scopeParams));
  if (!$stmt->fetch()) {
    json_response(['ok' => false, 'error' => t('survey.not_found')], 404);
  }
  $stmt = db()->prepare("UPDATE surveys SET status = ?, updated_at = NOW() WHERE id = ?");
  $stmt->execute([$status, $id]);
  json_response(['ok' => true]);
}

if ($action === 'save') {
  $id = (int)($input['id'] ?? 0);
  $title = trim((string)($input['title'] ?? ''));
  $description = trim((string)($input['description'] ?? ''));
  $authorityId = isset($input['authority_id']) ? (int)$input['authority_id'] : null;
  $startsAt = trim((string)($input['starts_at'] ?? ''));
  $endsAt = trim((string)($input['ends_at'] ?? ''));
  $status = trim((string)($input['status'] ?? 'draft'));
  if (!in_array($status, ['draft', 'active', 'closed'], true)) $status = 'draft';
  if ($title === '') {
    json_response(['ok' => false, 'error' => t('survey.title_required')]);
  }
  if ($authorityId !== null && $authorityId > 0 && !in_array($authorityId, $authorityIds, true) && !in_array($role, ['admin', 'superadmin'], true)) {
    json_response(['ok' => false, 'error' => t('common.error_no_permission')], 403);
  }
  if ($startsAt === '') $startsAt = date('Y-m-d H:i:s');
  if ($endsAt === '') $endsAt = date('Y-m-d H:i:s', strtotime('+30 days'));

  try {
    if ($id > 0) {
      $stmt = db()->prepare("SELECT id FROM surveys WHERE id = ? AND ($scopeWhere)");
      $stmt->execute(array_merge([$id], $scopeParams));
      if (!$stmt->fetch()) {
        json_response(['ok' => false, 'error' => t('survey.not_found')], 404);
      }
      $stmt = db()->prepare("UPDATE surveys SET title = ?, description = ?, authority_id = ?, starts_at = ?, ends_at = ?, status = ?, updated_at = NOW() WHERE id = ?");
      $stmt->execute([$title, $description, $authorityId ?: null, $startsAt, $endsAt, $status, $id]);
      $surveyId = $id;
    } else {
      $stmt = db()->prepare("INSERT INTO surveys (title, description, authority_id, starts_at, ends_at, status) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->execute([$title, $description, $authorityId ?: null, $startsAt, $endsAt, $status]);
      $surveyId = (int)db()->lastInsertId();
    }

    $questions = $input['questions'] ?? [];
    if (is_array($questions)) {
      $stmt = db()->prepare("DELETE FROM survey_questions WHERE survey_id = ?");
      $stmt->execute([$surveyId]);
      $ins = db()->prepare("INSERT INTO survey_questions (survey_id, question_text, question_type, sort_order, options_json) VALUES (?, ?, ?, ?, ?)");
      foreach ($questions as $i => $q) {
        $text = trim((string)($q['question_text'] ?? ''));
        if ($text === '') continue;
        $type = trim((string)($q['question_type'] ?? 'text'));
        $order = (int)($q['sort_order'] ?? $i);
        $opts = isset($q['options']) ? (is_array($q['options']) ? json_encode($q['options']) : (string)$q['options']) : null;
        $ins->execute([$surveyId, $text, $type, $order, $opts]);
      }
    }
    json_response(['ok' => true, 'id' => $surveyId]);
  } catch (Throwable $e) {
    log_error('gov_surveys save: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
}

json_response(['ok' => false, 'error' => t('api.invalid_action')], 400);
