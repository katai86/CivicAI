<?php
/**
 * M8 Citizen Participation – felmérések.
 * GET: list active surveys (no id) or one survey with questions (id=...).
 * POST: action=submit_response, survey_id, responses (JSON object question_id => value).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
  if ($id) {
    // Egy felmérés kérdésekkel
    try {
      $stmt = db()->prepare("
        SELECT id, title, description, authority_id, starts_at, ends_at, status, created_at
        FROM surveys WHERE id = ? AND status = 'active'
      ");
      $stmt->execute([$id]);
      $survey = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$survey) {
        json_response(['ok' => false, 'error' => t('survey.not_found')]);
      }
      $now = date('Y-m-d H:i:s');
      if ($survey['starts_at'] > $now || $survey['ends_at'] < $now) {
        json_response(['ok' => false, 'error' => t('survey.not_active')]);
      }
      $stmt = db()->prepare("
        SELECT id, question_text, question_type, sort_order, options_json
        FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC
      ");
      $stmt->execute([$id]);
      $survey['questions'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($survey['questions'] as &$q) {
        if (!empty($q['options_json'])) {
          $q['options'] = json_decode($q['options_json'], true);
          if (!is_array($q['options'])) $q['options'] = [];
        } else {
          $q['options'] = [];
        }
        unset($q['options_json']);
      }
      unset($q);
      $uid = current_user_id() ? (int)current_user_id() : 0;
      $survey['voted_by_me'] = false;
      if ($uid) {
        $stmt = db()->prepare("SELECT 1 FROM survey_responses WHERE survey_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $uid]);
        $survey['voted_by_me'] = (bool)$stmt->fetch();
      }
      json_response(['ok' => true, 'data' => $survey]);
    } catch (Throwable $e) {
      log_error('survey get one: ' . $e->getMessage());
      json_response(['ok' => false, 'error' => t('common.error_server')], 500);
    }
  }

  // Lista: aktív felmérések (időszak + status)
  $authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
  $now = date('Y-m-d H:i:s');
  try {
    $sql = "
      SELECT s.id, s.title, s.description, s.authority_id, s.starts_at, s.ends_at, s.created_at,
        a.name AS authority_name,
        (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = s.id) AS response_count
      FROM surveys s
      LEFT JOIN authorities a ON a.id = s.authority_id
      WHERE s.status = 'active' AND s.starts_at <= :now AND s.ends_at >= :now
    ";
    $params = [':now' => $now];
    if ($authorityId !== null && $authorityId > 0) {
      $sql .= " AND (s.authority_id IS NULL OR s.authority_id = :aid)";
      $params[':aid'] = $authorityId;
    }
    $sql .= " ORDER BY s.ends_at ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($list as &$row) {
      $row['response_count'] = (int)($row['response_count'] ?? 0);
    }
    unset($row);
    json_response(['ok' => true, 'data' => $list]);
  } catch (Throwable $e) {
    if (function_exists('log_error')) log_error('survey list: ' . $e->getMessage());
    json_response(['ok' => true, 'data' => []]);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $input = [];
  if (!empty($_POST['action'])) {
    $input = $_POST;
  } elseif (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
  }
  $action = $input['action'] ?? '';

  if ($action === 'submit_response') {
    start_secure_session();
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($uid < 1) {
      json_response(['ok' => false, 'error' => t('survey.login_required')], 403);
    }
    $surveyId = isset($input['survey_id']) ? (int)$input['survey_id'] : 0;
    $responses = $input['responses'] ?? [];
    if (!is_array($responses)) $responses = [];
    if ($surveyId < 1) {
      json_response(['ok' => false, 'error' => t('common.error_invalid_data')]);
    }
    try {
      $stmt = db()->prepare("SELECT id, status, starts_at, ends_at FROM surveys WHERE id = ?");
      $stmt->execute([$surveyId]);
      $survey = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$survey || $survey['status'] !== 'active') {
        json_response(['ok' => false, 'error' => t('survey.not_active')]);
      }
      $now = date('Y-m-d H:i:s');
      if ($survey['starts_at'] > $now || $survey['ends_at'] < $now) {
        json_response(['ok' => false, 'error' => t('survey.not_active')]);
      }
      $stmt = db()->prepare("SELECT 1 FROM survey_responses WHERE survey_id = ? AND user_id = ? LIMIT 1");
      $stmt->execute([$surveyId, $uid]);
      if ($stmt->fetch()) {
        json_response(['ok' => false, 'error' => t('survey.already_responded')]);
      }
      $stmt = db()->prepare("SELECT id FROM survey_questions WHERE survey_id = ?");
      $stmt->execute([$surveyId]);
      $allowedIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
      $filtered = [];
      foreach ($responses as $qid => $value) {
        $qid = (int)$qid;
        if (in_array($qid, $allowedIds, true)) {
          $filtered[$qid] = is_array($value) ? $value : (string)$value;
        }
      }
      $responseJson = json_encode($filtered, JSON_UNESCAPED_UNICODE);
      $stmt = db()->prepare("INSERT INTO survey_responses (survey_id, user_id, response_json) VALUES (?, ?, ?)");
      $stmt->execute([$surveyId, $uid, $responseJson]);
      json_response(['ok' => true, 'message' => t('survey.thank_you')]);
    } catch (Throwable $e) {
      log_error('survey submit: ' . $e->getMessage());
      json_response(['ok' => false, 'error' => t('common.error_server')], 500);
    }
  }

  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 400);
}

json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
