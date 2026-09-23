<?php
/**
 * Admin: bejelentés(ek) hatóság újrarendelése GPS / város alapján.
 *
 * Böngésző (bejelentkezett admin):
 *   GET  /api/admin_reassign_report_authority.php?report_id=285
 *   GET  /api/admin_reassign_report_authority.php?all=1&limit=500
 *
 * JSON POST:
 *   { "report_id": 285 }  vagy  { "all_misrouted": true, "limit": 200 }
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = [];
if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw ?: '', true);
  $body = is_array($decoded) ? $decoded : $_POST;
} elseif ($method === 'GET') {
  $body = $_GET;
} else {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$reportId = isset($body['report_id']) ? (int)$body['report_id'] : 0;
$allMisrouted = !empty($body['all_misrouted']) || !empty($body['all']);
$limit = isset($body['limit']) ? (int)$body['limit'] : 200;
if ($limit < 1 || $limit > 1000) {
  $limit = 200;
}

if ($reportId > 0) {
  if (!function_exists('reassign_report_authority_by_location')) {
    json_response([
      'ok' => false,
      'error' => 'util.php nincs frissítve a szerveren (reassign_report_authority_by_location hiányzik). Használd az SQL-t: sql/fix_report_authority_budaors.sql',
      'error_code' => 'util_outdated',
    ], 500);
  }
  $res = reassign_report_authority_by_location($reportId);
  json_response([
    'ok' => (bool)$res['ok'],
    'report_id' => $reportId,
    'authority_id' => $res['authority_id'],
    'changed' => (bool)$res['changed'],
    'error' => $res['error'],
  ], !empty($res['ok']) ? 200 : 400);
}

if (!$allMisrouted) {
  json_response([
    'ok' => false,
    'error' => 'Használat: ?report_id=285  vagy  ?all=1&limit=500  (admin session szükséges)',
    'hint' => 'Ne írd a body-t a címbe. Vagy futtasd: sql/fix_report_authority_budaors.sql',
  ], 400);
}

if (!function_exists('reassign_report_authority_by_location')) {
  json_response([
    'ok' => false,
    'error' => 'util.php nincs frissítve a szerveren. Használd az SQL-t.',
    'error_code' => 'util_outdated',
  ], 500);
}

$changed = 0;
$checked = 0;
$errors = [];
try {
  $pdo = db();
  $stmt = $pdo->query("
    SELECT id FROM reports
    WHERE lat IS NOT NULL AND lng IS NOT NULL
    ORDER BY id DESC
    LIMIT " . (int)$limit
  );
  $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
  foreach ($ids as $id) {
    $checked++;
    $res = reassign_report_authority_by_location((int)$id);
    if (!empty($res['changed'])) {
      $changed++;
    } elseif (!empty($res['error']) && $res['error'] !== 'no_matching_authority') {
      $errors[] = ['id' => (int)$id, 'error' => $res['error']];
    }
  }
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

json_response([
  'ok' => true,
  'checked' => $checked,
  'changed' => $changed,
  'errors' => array_slice($errors, 0, 20),
]);
