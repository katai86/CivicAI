<?php
/**
 * Run all CivicAI smoke / verify scripts (Docker-friendly).
 * Usage: php tests/run_all_verify.php
 * Exit 0 only if every script exits 0.
 */
$root = dirname(__DIR__);
$scripts = [
    'verify_m1_schema.php',
    'verify_city_intelligence.php',
    'verify_plant_intelligence.php',
    'verify_intelligence_platform.php',
    'verify_eu_open_data_foundation.php',
    'verify_report_routing.php',
    'i18n_sync_keys.php',
];

$failed = [];
foreach ($scripts as $script) {
    $path = $root . '/tests/' . $script;
    if (!is_file($path)) {
        echo "SKIP missing: $script\n";
        continue;
    }
    echo "\n========== $script ==========\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        $failed[] = $script;
    }
}

echo "\n========== SUMMARY ==========\n";
if ($failed === []) {
    echo "All verify scripts passed.\n";
    exit(0);
}
echo "FAILED: " . implode(', ', $failed) . "\n";
exit(1);
