<?php
/**
 * M14 – POST: AI explanation of rule-based gov insights (bullets from client). Same auth as gov_insights; uses summary AI limits.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExecutiveSummaryService.php';
require_once __DIR__ . '/../services/AiRouter.php';
require_once __DIR__ . '/../services/AiPromptBuilder.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if (!$isAdmin) {
  if ($role !== 'govuser') {
    json_response(['ok' => false, 'error' => t('api.unauthorized')], 403);
  }
  $uidCheck = current_user_id();
  if (!$uidCheck || !user_module_enabled((int)$uidCheck, 'mistral')) {
    json_response(['ok' => false, 'error' => t('api.ai_disabled_user')], 403);
  }
}

if (!function_exists('ai_configured') || !ai_configured()) {
  json_response(['ok' => false, 'error' => t('api.ai_disabled')], 400);
}

$body = read_json_body();
$rawBullets = $body['bullets'] ?? null;
if (!is_array($rawBullets) || $rawBullets === []) {
  json_response(['ok' => false, 'error' => t('gov.insights_ai_no_bullets')], 400);
}

$adminAid = null;
if ($isAdmin) {
  $a = isset($body['authority_id']) ? (int)$body['authority_id'] : 0;
  $adminAid = $a > 0 ? $a : null;
}

$lines = [];
foreach (array_slice($rawBullets, 0, 12) as $row) {
  if (is_string($row)) {
    $t = $row;
  } elseif (is_array($row)) {
    $t = (string)($row['text'] ?? '');
  } else {
    continue;
  }
  $t = trim(strip_tags(str_replace(["\0"], '', $t)));
  if (mb_strlen($t) > 800) {
    $t = mb_substr($t, 0, 800) . '…';
  }
  if ($t !== '') {
    $lines[] = $t;
  }
}

if ($lines === []) {
  json_response(['ok' => false, 'error' => t('gov.insights_ai_no_bullets')], 400);
}

$pdo = db();
$uid = current_user_id() ? (int)current_user_id() : 0;
[, , , $healthAuthorityId] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

$scopeTitle = '';
if ($healthAuthorityId !== null && $healthAuthorityId > 0) {
  try {
    $st = $pdo->prepare('SELECT name FROM authorities WHERE id = ? LIMIT 1');
    $st->execute([$healthAuthorityId]);
    $scopeTitle = trim((string)($st->fetchColumn() ?: ''));
  } catch (Throwable $e) {
    $scopeTitle = '';
  }
}
if ($scopeTitle === '' && $isAdmin && $adminAid === null) {
  $scopeTitle = t('gov.insights_ai_scope_all');
}

$outputLang = function_exists('current_lang') ? current_lang() : 'hu';
$prompt = AiPromptBuilder::govInsightsExplain($lines, $outputLang, $scopeTitle);

$router = new AiRouter();
if (!$router->isEnabled()) {
  json_response(['ok' => false, 'error' => t('api.ai_disabled')], 400);
}

$taskType = 'gov_insights_explain';
$inputHash = hash('sha256', $taskType . '|' . $scopeTitle . '|' . json_encode($lines, JSON_UNESCAPED_UNICODE));

$resp = $router->callJson($taskType, $prompt, [
  'max_tokens' => 700,
  'temperature' => 0.2,
  'timeout' => 45,
  'response_format' => 'json_object',
]);

if (empty($resp['ok'])) {
  json_response(['ok' => false, 'error' => $resp['error'] ?? t('api.ai_failed')], 502);
}

$modelName = (string)($resp['model'] ?? '');
$data = is_array($resp['data']) ? $resp['data'] : null;
$text = '';
if (is_array($data)) {
  $text = (string)($data['text'] ?? $data['summary'] ?? '');
}
if ($text === '' && !empty($resp['raw']['choices'][0]['message']['content'])) {
  $rawContent = trim((string)$resp['raw']['choices'][0]['message']['content']);
  if (preg_match('/^\s*```\s*\w*\s*\n?(.*?)\n?\s*```\s*$/s', $rawContent, $m)) {
    $rawContent = trim($m[1]);
  }
  if (($jsonStart = strpos($rawContent, '{')) !== false) {
    $maybe = json_decode(substr($rawContent, $jsonStart), true);
    if (is_array($maybe)) {
      $data = $maybe;
      $text = (string)($maybe['text'] ?? $maybe['summary'] ?? '');
    } else {
      $text = $rawContent;
    }
  } else {
    $text = $rawContent;
  }
}

$text = trim($text);
if ($text === '') {
  json_response(['ok' => false, 'error' => t('api.ai_failed')], 502);
}

try {
  ai_store_result('gov', $healthAuthorityId, $taskType, $modelName, $inputHash, ['text' => mb_substr($text, 0, 2000)], null);
} catch (Throwable $e) { /* ignore */
}

json_response([
  'ok' => true,
  'data' => [
    'text' => $text,
    'model' => $modelName,
  ],
]);
