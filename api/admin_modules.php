<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

/**
 * Beépülő modulok – GET lista (maszkolt kulcsok), POST mentés.
 * Modulok: fms (FixMyStreet), mistral (AI).
 */
$MODULE_DEFS = [
  'fms' => [
    'name' => 'FixMyStreet / Open311',
    'description' => 'Opcionális külső rendszer – bejelentések kiküldése és státusz szinkron.',
    'settings' => [
      ['key' => 'enabled', 'label' => 'Bekapcsolva', 'type' => 'checkbox'],
      ['key' => 'base_url', 'label' => 'Alap URL (pl. https://fixmystreet.example.com)', 'type' => 'text', 'placeholder' => 'https://...'],
      ['key' => 'jurisdiction', 'label' => 'Jurisdiction ID', 'type' => 'text'],
      ['key' => 'api_key', 'label' => 'API kulcs', 'type' => 'password', 'mask' => true],
    ],
  ],
  'mistral' => [
    'name' => 'Mistral AI',
    'description' => 'Bejelentés kategorizálás, szöveg elemzés. API kulcs: platform.mistral.ai',
    'settings' => [
      ['key' => 'enabled', 'label' => 'Bekapcsolva', 'type' => 'checkbox'],
      ['key' => 'api_key', 'label' => 'API kulcs', 'type' => 'password', 'mask' => true],
    ],
  ],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $list = [];
  foreach ($MODULE_DEFS as $moduleKey => $def) {
    $settingsList = [];
    foreach ($def['settings'] as $s) {
      $v = get_module_setting($moduleKey, $s['key']);
      $masked = !empty($s['mask']) && $v !== null && $v !== '';
      $settingsList[] = [
        'key' => $s['key'],
        'label' => $s['label'],
        'type' => $s['type'] ?? 'text',
        'mask' => !empty($s['mask']),
        'value' => $masked ? '' : ($v ?? ''),
        'set' => $v !== null && $v !== '',
        'placeholder' => !empty($s['placeholder']) ? $s['placeholder'] : '',
      ];
    }
    $list[] = [
      'id' => $moduleKey,
      'name' => $def['name'],
      'description' => $def['description'],
      'settings' => $settingsList,
    ];
  }
  json_response(['ok' => true, 'modules' => $list]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$action = (string)($body['action'] ?? '');
if ($action !== 'save_module') {
  json_response(['ok' => false, 'error' => 'Invalid action'], 400);
}

$moduleId = (string)($body['module_id'] ?? '');
if (!isset($MODULE_DEFS[$moduleId])) {
  json_response(['ok' => false, 'error' => 'Unknown module'], 400);
}

$enabled = !empty($body['enabled']) ? '1' : '0';
$settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

$pdo = db();
// enabled mindig
$pdo->prepare("
  INSERT INTO module_settings (module_key, setting_key, value) VALUES (?, 'enabled', ?)
  ON DUPLICATE KEY UPDATE value = VALUES(value)
")->execute([$moduleId, $enabled]);

foreach ($MODULE_DEFS[$moduleId]['settings'] as $s) {
  if ($s['key'] === 'enabled') continue;
  $value = isset($settings[$s['key']]) ? (string)$settings[$s['key']] : '';
  // Jelszó mező: ha üres, ne írjuk felül (megtartjuk a meglévőt)
  if (!empty($s['mask']) && $value === '') continue;
  if ($value === '') {
    $pdo->prepare("DELETE FROM module_settings WHERE module_key = ? AND setting_key = ?")->execute([$moduleId, $s['key']]);
  } else {
    $pdo->prepare("
      INSERT INTO module_settings (module_key, setting_key, value) VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE value = VALUES(value)
    ")->execute([$moduleId, $s['key'], $value]);
  }
}

json_response(['ok' => true]);
