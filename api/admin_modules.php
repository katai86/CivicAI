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
      ['key' => 'default_ai_provider', 'label' => 'Alapértelmezett AI provider (összefoglaló, gov)', 'type' => 'select', 'options' => ['mistral' => 'Mistral', 'openai' => 'OpenAI (ChatGPT)']],
      ['key' => 'ai_summary_limit', 'label' => 'Napi max AI összefoglaló hívás (gov/admin)', 'type' => 'number', 'placeholder' => '20'],
      ['key' => 'ai_max_reports_per_day', 'label' => 'Napi max bejelentés-kategorizálás (AI)', 'type' => 'number', 'placeholder' => '1000'],
      ['key' => 'ai_image_analysis_limit', 'label' => 'Napi max kép-elemzés (AI)', 'type' => 'number', 'placeholder' => '300'],
    ],
  ],
  'openai' => [
    'name' => 'OpenAI / ChatGPT',
    'description' => 'Opcionális AI provider (kategorizálás, összefoglaló). Ugyanazok a limitek (napi összefoglaló stb.). API kulcs: platform.openai.com',
    'settings' => [
      ['key' => 'enabled', 'label' => 'Bekapcsolva', 'type' => 'checkbox'],
      ['key' => 'api_key', 'label' => 'API kulcs', 'type' => 'password', 'mask' => true],
      ['key' => 'model', 'label' => 'Modell (pl. gpt-4o-mini)', 'type' => 'text', 'placeholder' => 'gpt-4o-mini'],
    ],
  ],
  'geocode' => [
    'name' => t('geocode.module_name'),
    'description' => t('geocode.module_desc'),
    'settings' => [
      ['key' => 'enabled', 'label' => t('admin.enabled'), 'type' => 'checkbox'],
      ['key' => 'search_provider_mode', 'label' => t('geocode.search_mode'), 'type' => 'select', 'options' => [
        'nominatim' => t('geocode.opt_nominatim'),
        'tomtom' => t('geocode.opt_tomtom'),
        'both' => t('geocode.opt_both'),
      ]],
      ['key' => 'tomtom_api_key', 'label' => t('geocode.tomtom_api_key'), 'type' => 'password', 'mask' => true],
      ['key' => 'country_codes', 'label' => t('geocode.country_codes'), 'type' => 'text', 'placeholder' => 'hu'],
    ],
  ],
  'participatory_budget' => [
    'name' => 'Részvételi költségvetés',
    'description' => 'Időszakos szavazás a projektekre. Ha kikapcsolt, a menüben és a nyilvános oldalon nem aktív.',
    'settings' => [
      ['key' => 'enabled', 'label' => 'Szavazás aktív (menü és oldal látható)', 'type' => 'checkbox'],
    ],
  ],
  'surveys' => [
    'name' => 'Felmérések',
    'description' => 'Kérdőívek létrehozása és kitöltése. Ha kikapcsolt, a menüben és a nyilvános oldalon nem látható.',
    'settings' => [
      ['key' => 'enabled', 'label' => 'Felmérések aktív (menü és oldal látható)', 'type' => 'checkbox'],
    ],
  ],
  'iot' => [
    'name' => 'IoT / Virtuális szenzorok',
    'description' => 'Külső adatforrások (légszennyezés, időjárás) virtuális szenzorként a közig dashboardon és térképen. API kulcsok a providerekhez.',
    'settings' => [
      ['key' => 'enabled', 'label' => 'IoT modul bekapcsolva (gov felületen ki/be kapcsolható)', 'type' => 'checkbox'],
      ['key' => 'openaq_api_key', 'label' => 'OpenAQ API token (v3-hez szükséges, ingyenes: openaq.org)', 'type' => 'password', 'mask' => true],
      ['key' => 'aqicn_api_key', 'label' => 'AQICN / WAQI API token (ingyenes: aqicn.org/data-platform)', 'type' => 'password', 'mask' => true],
      ['key' => 'openweather_api_key', 'label' => 'OpenWeather API kulcs (openweathermap.org)', 'type' => 'password', 'mask' => true],
      ['key' => 'weatherxm_api_key', 'label' => 'WeatherXM API token (opcionális)', 'type' => 'password', 'mask' => true],
      ['key' => 'aeris_client_id', 'label' => 'AerisWeather / PWS Client ID (opcionális)', 'type' => 'password', 'mask' => true],
      ['key' => 'aeris_client_secret', 'label' => 'AerisWeather Client Secret (opcionális)', 'type' => 'password', 'mask' => true],
      ['key' => 'iot_sync_interval_min', 'label' => 'Sync gyakoriság (perc)', 'type' => 'number', 'placeholder' => '60'],
      ['key' => 'iot_max_stations_per_city', 'label' => 'Max állomás (sok szenzorhoz pl. Budapest: 300–1000)', 'type' => 'number', 'placeholder' => '300'],
    ],
  ],
  'eu_open_data' => [
    'group' => 'eu_open_data',
    'name' => t('eu_open_data.module_name'),
    'description' => t('eu_open_data.module_desc'),
    'settings' => [
      ['key' => 'enabled', 'label' => t('admin.enabled'), 'type' => 'checkbox'],
      ['key' => 'copernicus_enabled', 'label' => t('eu_open_data.lbl_copernicus'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'copernicus_client_id', 'label' => t('eu_open_data.lbl_copernicus_client_id'), 'type' => 'text', 'placeholder' => ''],
      ['key' => 'copernicus_client_secret', 'label' => t('eu_open_data.lbl_copernicus_client_secret'), 'type' => 'password', 'mask' => true],
      ['key' => 'clms_enabled', 'label' => t('eu_open_data.lbl_clms'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'cams_enabled', 'label' => t('eu_open_data.lbl_cams'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'cds_enabled', 'label' => t('eu_open_data.lbl_cds'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'eurostat_enabled', 'label' => t('eu_open_data.lbl_eurostat'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'eea_enabled', 'label' => t('eu_open_data.lbl_eea'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'inspire_enabled', 'label' => t('eu_open_data.lbl_inspire'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
      ['key' => 'request_timeout_seconds', 'label' => t('eu_open_data.lbl_timeout'), 'type' => 'number', 'placeholder' => '30'],
      ['key' => 'cache_ttl_minutes', 'label' => t('eu_open_data.lbl_cache_ttl'), 'type' => 'number', 'placeholder' => '60'],
      ['key' => 'sync_enabled', 'label' => t('eu_open_data.lbl_sync'), 'type' => 'select', 'options' => ['0' => t('eu_open_data.opt_off'), '1' => t('eu_open_data.opt_on')]],
    ],
  ],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  try {
    $list = [];
    foreach ($MODULE_DEFS as $moduleKey => $def) {
      $settingsList = [];
      foreach ($def['settings'] as $s) {
        $v = get_module_setting($moduleKey, $s['key']);
        $masked = !empty($s['mask']) && $v !== null && $v !== '';
        $setting = [
          'key' => $s['key'],
          'label' => $s['label'],
          'type' => $s['type'] ?? 'text',
          'mask' => !empty($s['mask']),
          'value' => $masked ? '' : ($v ?? ''),
          'set' => $v !== null && $v !== '',
          'placeholder' => !empty($s['placeholder']) ? $s['placeholder'] : '',
        ];
        if (!empty($s['options']) && is_array($s['options'])) {
          $setting['options'] = $s['options'];
        }
        $settingsList[] = $setting;
      }
      $list[] = [
        'id' => $moduleKey,
        'name' => $def['name'],
        'description' => $def['description'],
        'settings' => $settingsList,
        'group' => $def['group'] ?? null,
      ];
    }
    json_response(['ok' => true, 'modules' => $list]);
  } catch (Throwable $e) {
    if (function_exists('log_error')) log_error('admin_modules GET: ' . $e->getMessage());
    json_response(['ok' => true, 'modules' => $list ?? []]);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$body = read_json_body();
$action = (string)($body['action'] ?? '');

// Teszt Mistral – minimális hívás, siker/hiba vissza
if ($action === 'test_mistral') {
  require_once __DIR__ . '/../services/AiRouter.php';
  $router = new \AiRouter();
  if (!$router->isEnabled()) {
    json_response(['ok' => false, 'error' => 'AI nincs bekapcsolva vagy nincs API kulcs (Mistral).']);
  }
  $resp = $router->callJson('gov_summary', 'Reply with exactly: OK', ['max_tokens' => 10]);
  if (!empty($resp['ok'])) {
    json_response(['ok' => true, 'message' => 'Mistral: kapcsolat rendben.']);
  }
  json_response(['ok' => false, 'error' => $resp['error'] ?? 'Mistral hiba (pl. érvénytelen kulcs vagy limit).']);
}

if ($action === 'test_openai') {
  require_once __DIR__ . '/../services/OpenAIProvider.php';
  $apiKey = (string)(get_module_setting('openai', 'api_key') ?? '');
  if ($apiKey === '' && defined('OPENAI_API_KEY')) {
    $apiKey = (string) OPENAI_API_KEY;
  }
  if ($apiKey === '') {
    json_response(['ok' => false, 'error' => 'OpenAI API kulcs nincs megadva (admin vagy .env).']);
  }
  $provider = new \OpenAIProvider($apiKey, get_module_setting('openai', 'model') ?: null);
  $resp = $provider->complete('', 'Reply with exactly: OK', ['max_tokens' => 10]);
  if (!empty($resp['ok'])) {
    json_response(['ok' => true, 'message' => 'OpenAI: kapcsolat rendben.']);
  }
  json_response(['ok' => false, 'error' => $resp['error'] ?? 'OpenAI hiba (pl. érvénytelen kulcs).']);
}

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
