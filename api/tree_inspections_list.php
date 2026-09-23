<?php
/**
 * Citizen / gov tree inspection history (read-only).
 * GET: tree_id
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$treeId = (int)($_GET['tree_id'] ?? 0);
if ($treeId <= 0) {
    json_response(['ok' => false, 'error' => t('api.tree_invalid_id')], 400);
}

$pdo = db();
$st = $pdo->prepare('SELECT id, public_visible, adopted_by_user_id FROM trees WHERE id = ? LIMIT 1');
$st->execute([$treeId]);
$tree = $st->fetch(PDO::FETCH_ASSOC);
if (!$tree || !(int)($tree['public_visible'] ?? 0)) {
    json_response(['ok' => false, 'error' => t('api.tree_not_found')], 404);
}

$uid = current_user_id();
$isGov = function_exists('is_gov_user') && is_gov_user();
if (!$isGov) {
    if (!$uid) {
        json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
    }
}

if (!db_table_has_column($pdo, 'tree_inspections', 'id')) {
    json_response(['ok' => true, 'inspections' => [], 'status' => 'table_missing']);
}

$cols = ['id', 'health_label', 'health_score', 'risk_level', 'failure_state', 'created_at'];
if (db_table_has_column($pdo, 'tree_inspections', 'species_candidates_json')) {
    $cols[] = 'species_candidates_json';
}
if (db_table_has_column($pdo, 'tree_inspections', 'confidence_json')) {
    $cols[] = 'confidence_json';
}

$sql = 'SELECT ' . implode(', ', $cols) . ' FROM tree_inspections WHERE tree_id = ? ORDER BY created_at DESC LIMIT 15';
$st = $pdo->prepare($sql);
$st->execute([$treeId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_response(['ok' => true, 'inspections' => $rows]);
