<?php
/**
 * M26 – Plant & Tree Intelligence verification script.
 * Usage: php tests/verify_plant_intelligence.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$checks = [];
function pt_check(string $name, bool $ok, string $detail = ''): void {
    global $checks;
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . ($detail ? ' – ' . $detail : '') . PHP_EOL;
}

$files = [
    'services/plant/PlantTreeVisionRouter.php',
    'services/plant/PlantNetSpeciesProvider.php',
    'services/plant/HuggingFaceInferenceProvider.php',
    'services/plant/CloudVisionConditionProvider.php',
    'services/plant/TreeHealthEngine.php',
    'services/plant/PublicRiskEngine.php',
    'api/plant_analyze.php',
    'api/plant_provider_health.php',
];
foreach ($files as $f) {
    pt_check('file:' . $f, is_file(__DIR__ . '/../' . $f));
}

pt_check('plant_tree_enabled fn', function_exists('plant_tree_enabled'));
pt_check('plantnet_api_key fn', function_exists('plantnet_api_key'));
pt_check('huggingface_api_key fn', function_exists('huggingface_api_key'));

require_once __DIR__ . '/../services/plant/TreeHealthEngine.php';
$health = TreeHealthEngine::compute([
    ['signal' => 'dryness', 'value' => 'moderate', 'severity' => 'moderate'],
]);
pt_check('TreeHealthEngine compute', isset($health['health_score']) && $health['health_score'] < 85);

require_once __DIR__ . '/../services/plant/ImageQualityGate.php';
$bad = ImageQualityGate::check('/nonexistent.jpg');
pt_check('ImageQualityGate rejects missing', empty($bad['ok']));

require_once __DIR__ . '/../services/plant/PlantTreeVisionRouter.php';
$router = new PlantTreeVisionRouter();
$healthStatus = $router->providerHealth();
pt_check('providerHealth returns array', is_array($healthStatus));

$failed = count(array_filter($checks, fn($c) => !$c['ok']));
echo PHP_EOL . 'Total: ' . count($checks) . ', Failed: ' . $failed . PHP_EOL;
exit($failed > 0 ? 1 : 0);
