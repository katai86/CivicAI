<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/ScenarioEngine.php';
require_once __DIR__ . '/../services/cityintel/PatternCorrelationEngine.php';
require_once __DIR__ . '/../services/cityintel/CityTimeMachine.php';
require_once __DIR__ . '/../services/cityintel/CityHealthScoreV2.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
if (!$aid) {
    json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}
$action = (string)($_GET['action'] ?? 'presets');
if ($action === 'run') {
    $preset = (string)($_GET['preset'] ?? 'drought_stress');
    json_response(['ok' => true, 'result' => (new ScenarioEngine())->run($aid, $preset)]);
}
if ($action === 'patterns') {
    json_response(['ok' => true, 'data' => (new PatternCorrelationEngine())->analyze($aid)]);
}
if ($action === 'timeline') {
    $days = min(365, max(7, (int)($_GET['days'] ?? 90)));
    json_response(['ok' => true, 'timeline' => (new CityTimeMachine())->timeline($aid, $days)]);
}
if ($action === 'health_v2') {
    json_response(['ok' => true, 'health' => (new CityHealthScoreV2())->compute($aid)]);
}
json_response(['ok' => true, 'presets' => (new ScenarioEngine())->listPresets($aid)]);
