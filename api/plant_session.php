<?php
/**
 * M19 – Open/close multi-image inspection session.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/plant/TreeInspectionSession.php';

start_secure_session();
require_user();

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $body = read_json_body();
    $action = (string)($body['action'] ?? $_POST['action'] ?? 'open');
    if ($action === 'close') {
        $key = (string)($body['session_key'] ?? $_POST['session_key'] ?? '');
        if ($key === '') {
            json_response(['ok' => false, 'error' => t('api.missing_fields')], 400);
        }
        json_response(TreeInspectionSession::close($key));
    }
    $treeId = isset($body['tree_id']) ? (int)$body['tree_id'] : (int)($_POST['tree_id'] ?? 0);
    $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
    if ($treeId > 0) {
        $st = db()->prepare('SELECT authority_id FROM trees WHERE id = ? LIMIT 1');
        $st->execute([$treeId]);
        $authorityId = (int)$st->fetchColumn();
    }
    $required = is_array($body['required_shots'] ?? null) ? $body['required_shots'] : ['whole_tree', 'leaves', 'trunk'];
    json_response(TreeInspectionSession::open($treeId > 0 ? $treeId : null, $authorityId, $required));
}

if ($method === 'GET') {
    require_gov_or_admin();
    $treeId = (int)($_GET['tree_id'] ?? 0);
    if ($treeId <= 0 || !db_table_has_column(db(), 'tree_inspections', 'tree_id')) {
        json_response(['ok' => false, 'error' => t('api.tree_invalid_id')], 400);
    }
    $st = db()->prepare('SELECT id, health_label, health_score, risk_level, failure_state, created_at FROM tree_inspections WHERE tree_id = ? ORDER BY created_at DESC LIMIT 20');
    $st->execute([$treeId]);
    json_response(['ok' => true, 'inspections' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
