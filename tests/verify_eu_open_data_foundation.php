<?php
/**
 * EU Open Data Milestone 1 – séma ellenőrzés.
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

exit($ok ? 0 : 1);
