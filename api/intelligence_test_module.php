<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/IntelligenceHub.php';

require_gov_or_admin();

$role = current_user_role() ?: '';
if (!in_array($role, ['admin', 'superadmin'], true)) {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    $body = $_POST;
}
$moduleKey = trim((string)($body['module_key'] ?? ''));
$aid = gov_primary_authority_id();
if (isset($body['authority_id']) && (int)$body['authority_id'] > 0) {
    $aid = (int)$body['authority_id'];
}

if ($moduleKey === '') {
    json_response(['ok' => false, 'error' => 'invalid_module'], 400);
}

$result = (new IntelligenceHub())->testModule($moduleKey, $aid);
json_response(['ok' => !empty($result['ok']), 'data' => $result]);
