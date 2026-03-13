<?php
/**
 * M3 Ideation – ötlet státusz módosítása (admin / gov user).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();
require_user();

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$isGov = ($role === 'govuser');
if (!$isAdmin && !$isGov) {
  json_response(['ok' => false, 'error' => t('api.unauthorized')], 403);
}

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : 0;
$status = isset($body['status']) ? trim((string)$body['status']) : '';

$allowed = ['submitted', 'under_review', 'planned', 'in_progress', 'completed'];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  json_response(['ok' => false, 'error' => t('api.invalid_data')], 400);
}

try {
  $pdo = db();
  $stmt = $pdo->prepare("UPDATE ideas SET status = :s, updated_at = NOW() WHERE id = :id");
  $stmt->execute([':s' => $status, ':id' => $id]);
  if ($stmt->rowCount() === 0) {
    json_response(['ok' => false, 'error' => t('api.report_not_found')], 404);
  }
  json_response(['ok' => true, 'id' => $id, 'status' => $status]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => t('api.db_error')], 500);
}
