<?php
/**
 * Intelligence Platform – fájl- és osztály integritás smoke teszt.
 */
$root = dirname(__DIR__);
$errors = 0;

function intel_check(string $label, bool $ok): void
{
    global $errors;
    if ($ok) {
        echo "OK: $label\n";
    } else {
        echo "FAIL: $label\n";
        $errors++;
    }
}

$files = [
    'services/IntelligenceModuleRegistry.php',
    'services/IntelligenceHub.php',
    'services/ClimateIndexService.php',
    'services/AiVisionService.php',
    'services/IntelligenceReportGenerator.php',
    'services/intelligence/IntelligenceModuleTrait.php',
    'services/intelligence/GbifDataService.php',
    'services/intelligence/HungaroMetDataService.php',
    'services/intelligence/PvgisDataService.php',
    'services/intelligence/OpenChargeMapDataService.php',
    'services/intelligence/ViirsDataService.php',
    'api/intelligence_modules.php',
    'api/intelligence_dashboard.php',
    'api/intelligence_context.php',
    'api/intelligence_map_layers.php',
    'api/intelligence_report.php',
    'api/intelligence_module_settings.php',
    'api/intelligence_test_module.php',
    'api/intelligence_provider_logs.php',
    'api/ai_vision_analyze.php',
];

foreach ($files as $f) {
    intel_check($f, is_file($root . '/' . $f));
}

require_once $root . '/util.php';
require_once $root . '/services/IntelligenceModuleRegistry.php';
require_once $root . '/services/IntelligenceHub.php';

intel_check('IntelligenceModuleRegistry::definitions', count(IntelligenceModuleRegistry::definitions()) >= 10);
intel_check('IntelligenceHub::availableMapLayers', count((new IntelligenceHub())->availableMapLayers()) >= 5);

exit($errors > 0 ? 1 : 0);
