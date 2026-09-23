<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AuthorityJoinService.php';

start_secure_session();
require_user();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? 'user');
if (!in_array($role, ['govuser', 'admin', 'superadmin'], true)) {
    json_response(['ok' => false, 'error' => t('common.error_no_permission')], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$body = read_json_body();
if (!$body) {
    $body = $_POST;
}

$city = trim((string)($body['municipality_city'] ?? ''));
$org = trim((string)($body['organization_name'] ?? ''));
$job = trim((string)($body['job_title'] ?? ''));
$message = trim((string)($body['message'] ?? ''));

if ($city === '') {
    json_response(['ok' => false, 'error' => t('auth.municipality_city_required')], 400);
}

$result = AuthorityJoinService::requestJoin($userId, $city, $org ?: null, $job ?: null, $message ?: null);
if (empty($result['ok'])) {
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
}

$status = (string)($result['status'] ?? 'pending');
if ($status === 'auto_approved') {
    json_response([
        'ok' => true,
        'status' => $status,
        'message' => t('gov.join_auto_approved'),
        'authority_id' => (int)($result['authority_id'] ?? 0),
    ]);
}

json_response([
    'ok' => true,
    'status' => $status,
    'message' => t('gov.join_pending'),
    'matches' => (int)($result['matches'] ?? 0),
]);
