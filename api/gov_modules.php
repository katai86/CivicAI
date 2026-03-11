<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
require_user();

$role = current_user_role() ?: '';
if ($role !== 'govuser') {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$defs = [
  ['key' => 'mistral', 'label' => 'AI (Mistral)', 'description' => 'AI panel a közig dashboardon (összefoglaló, ESG).'],
  ['key' => 'openai', 'label' => 'AI (OpenAI/ChatGPT)', 'description' => 'AI panel – OpenAI provider (ha be van kapcsolva az adminban).'],
  ['key' => 'fms', 'label' => 'FixMyStreet / Open311', 'description' => 'Külső Open311 / FixMyStreet integráció UI elemei.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $out = [];
  foreach ($defs as $d) {
    $enabled = user_module_enabled($uid, $d['key']);
    $out[] = [
      'key' => $d['key'],
      'label' => $d['label'],
      'description' => $d['description'],
      'enabled' => $enabled ? 1 : 0,
    ];
  }
  json_response(['ok' => true, 'modules' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$body = read_json_body();
if ((string)($body['action'] ?? '') !== 'save') {
  json_response(['ok' => false, 'error' => 'Invalid action'], 400);
}
$key = safe_str($body['module_key'] ?? null, 64);
$enabled = !empty($body['enabled']) ? 1 : 0;
if (!$key) json_response(['ok' => false, 'error' => 'Missing module_key'], 400);
if (!in_array($key, array_column($defs, 'key'), true)) {
  json_response(['ok' => false, 'error' => 'Unknown module'], 400);
}

db()->prepare("
  INSERT INTO user_module_toggles (user_id, module_key, is_enabled)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
")->execute([$uid, $key, $enabled]);

json_response(['ok' => true]);

