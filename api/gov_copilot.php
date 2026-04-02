<?php
/**
 * M10 – AI Government Copilot API.
 * POST { "question": "..." } → { "ok": true, "data": { "answer": "..." } }
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/GovCopilot.php';

start_secure_session();
require_user();

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if (!$isAdmin && $role !== 'govuser') {
  json_response(['ok' => false, 'error' => t('api.unauthorized')], 401);
}
if (!$isAdmin) {
  $uid = current_user_id();
  if (!function_exists('user_module_enabled') || !user_module_enabled($uid, 'mistral')) {
    json_response(['ok' => false, 'error' => t('api.ai_disabled_user')], 403);
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$body = read_json_body();
$question = isset($body['question']) ? trim((string)$body['question']) : '';
if ($question === '') {
  json_response(['ok' => false, 'error' => t('gov.copilot_question_required')]);
}

$authorityId = null;
$scopeTitle = t('gov.scope_area');
if ($isAdmin) {
  $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
  if ($authorityId > 0) {
    try {
      $stmt = db()->prepare("SELECT name, city FROM authorities WHERE id = ? LIMIT 1");
      $stmt->execute([$authorityId]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $scopeTitle = $row ? (trim((string)($row['city'] ?? $row['name'] ?? '')) ?: $row['name']) : t('gov.scope_area');
    } catch (Throwable $e) {}
  } else {
    $scopeTitle = t('gov.scope_all_authorities');
  }
} else {
  $uid = current_user_id();
  try {
    $stmt = db()->prepare("
      SELECT a.id, a.name, a.city
      FROM authority_users au
      JOIN authorities a ON a.id = au.authority_id
      WHERE au.user_id = ?
      ORDER BY a.name ASC
      LIMIT 1
    ");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $authorityId = (int)$row['id'];
      $scopeTitle = trim((string)($row['city'] ?? $row['name'] ?? '')) ?: (string)$row['name'];
    }
  } catch (Throwable $e) {}
}

$copilot = new GovCopilot($authorityId, $scopeTitle);
$outputLang = function_exists('current_lang') ? current_lang() : 'hu';
$result = $copilot->ask($question, $outputLang);

if (empty($result['ok'])) {
  json_response(['ok' => false, 'error' => $result['error'] ?? t('api.ai_failed')], 502);
}

json_response(['ok' => true, 'data' => ['answer' => $result['answer']]]);
