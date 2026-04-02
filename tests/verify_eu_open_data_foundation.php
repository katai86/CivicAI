<?php
/**
 * EU Open Data – alap séma + kapcsolódó fájlok (M1, M6–M9 smoke).
 * Futtatás: php tests/verify_eu_open_data_foundation.php
 */
$base = dirname(__DIR__);
require_once $base . '/db.php';

$db = db();

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $stmt && $stmt->rowCount() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $pdo->quote($column));
    return $stmt && $stmt->rowCount() > 0;
}

$ok = true;

foreach (['external_data_cache', 'external_data_provider_logs'] as $t) {
    if (!tableExists($db, $t)) {
        echo "FAIL: Table '$t' missing. Run sql/2026-25-eu-open-data-foundation.sql\n";
        $ok = false;
    } else {
        echo "OK: Table $t exists\n";
    }
}

if ($ok && tableExists($db, 'external_data_cache')) {
    foreach (['source_key', 'cache_key', 'payload_json', 'fetched_at', 'expires_at', 'status'] as $c) {
        if (!columnExists($db, 'external_data_cache', $c)) {
            echo "FAIL: external_data_cache.$c missing\n";
            $ok = false;
        }
    }
}

if ($ok && tableExists($db, 'external_data_provider_logs')) {
    foreach (['source_key', 'action', 'status', 'created_at'] as $c) {
        if (!columnExists($db, 'external_data_provider_logs', $c)) {
            echo "FAIL: external_data_provider_logs.$c missing\n";
            $ok = false;
        }
    }
}

// M6 / Eurostat: authorities.country
if ($ok && tableExists($db, 'authorities')) {
    if (!columnExists($db, 'authorities', 'country')) {
        echo "FAIL: authorities.country missing (Eurostat / EU country context)\n";
        $ok = false;
    } else {
        echo "OK: authorities.country exists\n";
    }
} elseif ($ok) {
    echo "SKIP: authorities table not found\n";
}

// M9: kritikus fájlok jelenléte (deploy / repo integritás)
$requiredFiles = [
    'api/eu_country_context.php',
    'api/eu_air_quality.php',
    'api/eu_climate_context.php',
    'api/eu_eea_inspire_context.php',
    'api/green_metrics.php',
    'api/gov_surveys.php',
    'api/gov_budget.php',
    'gov/index.php',
    'services/EurostatService.php',
    'services/ExternalDataCache.php',
];
foreach ($requiredFiles as $rel) {
    $path = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        echo "FAIL: Missing file $rel\n";
        $ok = false;
    }
}
if ($ok) {
    echo "OK: Required EU/gov files present (" . count($requiredFiles) . ")\n";
}

exit($ok ? 0 : 1);
