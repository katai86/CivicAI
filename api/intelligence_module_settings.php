<?php
/**
 * Intelligence modul beállítások – admin mentés (enabled, dashboard/map/report kapcsolók).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/IntelligenceModuleRegistry.php';

require_gov_or_admin();

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'data' => [
            'modules' => IntelligenceModuleRegistry::listWithStatus(),
            'can_edit' => $isAdmin,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!$isAdmin) {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    $body = $_POST;
}
$moduleKey = trim((string)($body['module_key'] ?? ''));
$settingKey = trim((string)($body['setting_key'] ?? ''));
$value = $body['value'] ?? null;

$allowedKeys = [];
foreach (IntelligenceModuleRegistry::definitions() as $def) {
    $allowedKeys[(string)$def['module_key']] = true;
}
if ($moduleKey === '' || !isset($allowedKeys[$moduleKey])) {
    json_response(['ok' => false, 'error' => 'invalid_module'], 400);
}

$allowedSettings = ['enabled', 'dashboard_widget', 'map_layer', 'report_enabled', 'api_key'];
if (!in_array($settingKey, $allowedSettings, true)) {
    json_response(['ok' => false, 'error' => 'invalid_setting'], 400);
}

$storeVal = is_bool($value) ? ($value ? '1' : '0') : trim((string)$value);
if (in_array($settingKey, ['enabled', 'dashboard_widget', 'map_layer', 'report_enabled'], true)) {
    $storeVal = ($storeVal === '1' || $storeVal === 'true' || $storeVal === true) ? '1' : '0';
}

try {
    set_module_setting($moduleKey, $settingKey, $storeVal);
    if ($settingKey === 'enabled' && $storeVal === '1') {
        set_module_setting($moduleKey, 'last_sync_at', gmdate('c'));
    }
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'save_failed'], 500);
}

json_response([
    'ok' => true,
    'data' => IntelligenceModuleRegistry::listWithStatus(),
]);
