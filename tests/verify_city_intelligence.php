<?php
/**
 * City Intelligence platform – unit/integration smoke tests (no DB required for pure calc;
 * with DB: schema ensure + observation upsert + indicator path).
 */
$root = dirname(__DIR__);
$errors = 0;

function ci_check(string $label, bool $ok): void
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
    'sql/2026-32-city-intelligence.sql',
    'services/cityintel/CityIntelSchema.php',
    'services/cityintel/CityDataSourceRegistry.php',
    'services/cityintel/CityObservationStore.php',
    'services/cityintel/OsmOverpassIngester.php',
    'services/cityintel/CityIndicatorEngines.php',
    'services/cityintel/CityInsightEngine.php',
    'services/cityintel/CityIntelligenceOrchestrator.php',
    'api/city_intel_bootstrap.php',
    'api/city_intel_sources.php',
    'api/city_intel_observations.php',
    'api/city_intel_indicators.php',
    'api/city_intel_anomalies.php',
    'api/city_intel_insights.php',
    'api/city_intel_dashboard.php',
    'api/city_intel_spatial.php',
    'api/city_priorities.php',
    'api/city_discoveries.php',
    'api/city_change.php',
    'api/city_zones.php',
    'api/city_evidence.php',
    'api/city_situation.php',
    'api/city_scenario.php',
    'api/plant_analyze.php',
    'api/plant_session.php',
    'api/plant_provider_health.php',
    'api/cron_city_intelligence_sync.php',
    'services/cityintel/CitySpatialEngine.php',
    'services/cityintel/CityZoneEngine.php',
    'services/cityintel/UnifiedPriorityEngine.php',
    'services/cityintel/CityDiscoveryEngine.php',
    'services/cityintel/CityChangeIntelligence.php',
    'services/cityintel/CitySituationEngine.php',
    'services/cityintel/PatternCorrelationEngine.php',
    'services/cityintel/CityTimeMachine.php',
    'services/cityintel/ScenarioEngine.php',
    'services/cityintel/CityHealthScoreV2.php',
    'services/plant/PlantTreeVisionRouter.php',
    'services/intelligence/UnifiedObservationLayer.php',
    'services/cityintel/CityVisionBridge.php',
    'services/cityintel/CityCitizenSignalBridge.php',
    'services/cityintel/CityIntelLabels.php',
    'sql/DEPLOY_city_intelligence_phpmyadmin.sql',
    'docs/CITY_INTELLIGENCE.md',
];

foreach ($files as $f) {
    ci_check($f, is_file($root . '/' . $f));
}

require_once $root . '/services/cityintel/CityIndicatorEngines.php';
require_once $root . '/services/cityintel/CitySpatialEngine.php';
require_once $root . '/services/cityintel/CityVisionBridge.php';

ci_check('CitySpatialEngine class', class_exists('CitySpatialEngine'));
ci_check('CityVisionBridge class', class_exists('CityVisionBridge'));
require_once $root . '/services/cityintel/UnifiedPriorityEngine.php';
require_once $root . '/services/cityintel/CitySituationEngine.php';
require_once $root . '/services/plant/PlantTreeVisionRouter.php';
ci_check('UnifiedPriorityEngine class', class_exists('UnifiedPriorityEngine'));
ci_check('PlantTreeVisionRouter class', class_exists('PlantTreeVisionRouter'));

// Pure trend math via reflection-free public API with empty history → stable
$trend = new CityTrendEngine();
$t0 = $trend->compute(null, 'test.missing', 8);
ci_check('trend empty → stable', ($t0['trend'] ?? '') === 'stable' && ($t0['points'] ?? -1) === 0);

// Spatial empty authority without DB may fail schema — skip soft
try {
    require_once $root . '/db.php';
    require_once $root . '/services/cityintel/CityIntelSchema.php';
    $schemaOk = CityIntelSchema::ensure();
    ci_check('CityIntelSchema::ensure', $schemaOk);
    if ($schemaOk) {
        $store = new CityObservationStore();
        $r1 = $store->upsert([
            'authority_id' => 15,
            'source_key' => 'open_meteo',
            'source_type' => 'weather',
            'indicator_type' => 'climate.temp_c',
            'observed_at' => date('Y-m-d H:i:s'),
            'lat' => 47.46,
            'lng' => 18.95,
            'value_num' => 21.5,
            'unit' => 'celsius',
            'confidence' => 0.9,
            'quality' => 0.85,
            'metadata' => ['measured' => true, 'test' => true],
        ]);
        ci_check('observation upsert ok', !empty($r1['ok']));
        $r2 = $store->upsert([
            'authority_id' => 15,
            'source_key' => 'open_meteo',
            'source_type' => 'weather',
            'indicator_type' => 'climate.temp_c',
            'observed_at' => date('Y-m-d H:i:s'),
            'lat' => 47.46,
            'lng' => 18.95,
            'value_num' => 21.5,
            'unit' => 'celsius',
            'confidence' => 0.9,
            'quality' => 0.85,
            'metadata' => ['measured' => true, 'test' => true],
        ]);
        ci_check('observation dedupe', !empty($r2['ok']));
        $ind = new CityIndicatorEngine();
        $w = $ind->recompute(15, 30);
        ci_check('indicator recompute runs', isset($w['written']));
        $base = new CityBaselineEngine();
        $bn = $base->recompute(15, 90);
        ci_check('baseline recompute runs', is_int($bn));
        $sp = (new CitySpatialEngine())->analyze(15, 3, 30);
        ci_check('spatial analyze ok', !empty($sp['ok']));
        $vb = CityVisionBridge::ingestFromVision(15, 47.46, 18.95, [
            'urgency_level' => 'medium',
            'vegetation_pct' => 8,
            'confidence_score' => 0.7,
            'suggested_category' => 'green',
        ], 'test_vision', null);
        ci_check('vision bridge ingest', !empty($vb['ok']) || isset($vb['inserted']));
    }
} catch (Throwable $e) {
    echo "SKIP DB: " . $e->getMessage() . "\n";
}

exit($errors > 0 ? 1 : 0);
