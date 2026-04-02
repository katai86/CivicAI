<?php
/**
 * Milestone 1 schema verification.
 * Run after 2026-13-tree-cadastre.sql: php tests/verify_m1_schema.php
 * Exit 0 = ok, 1 = missing table/column.
 */
$base = dirname(__DIR__);
require_once $base . '/db.php';

$db = db();
$dbName = defined('DB_NAME') ? DB_NAME : '';

function tableExists(PDO $pdo, string $table): bool {
  $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
  return $stmt && $stmt->rowCount() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
  $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
  return $stmt && $stmt->rowCount() > 0;
}

$ok = true;

if (!tableExists($db, 'trees')) {
  echo "FAIL: Table 'trees' does not exist. Run sql/2026-13-tree-cadastre.sql\n";
  $ok = false;
} else {
  echo "OK: Table trees exists\n";
}

if (!tableExists($db, 'tree_logs')) {
  echo "FAIL: Table 'tree_logs' does not exist. Run sql/2026-13-tree-cadastre.sql\n";
  $ok = false;
} else {
  echo "OK: Table tree_logs exists\n";
}

foreach (['related_tree_id', 'ai_category', 'ai_priority', 'gov_validated', 'impact_type'] as $col) {
  if (!columnExists($db, 'reports', $col)) {
    echo "FAIL: reports.$col missing. Run sql/2026-13-tree-cadastre.sql\n";
    $ok = false;
  } else {
    echo "OK: reports.$col exists\n";
  }
}

exit($ok ? 0 : 1);
