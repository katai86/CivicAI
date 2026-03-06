<?php
/**
 * Health check endpoint – üzemeltetés / monitoring.
 * Nincs auth; GET only. Ellenőrzi: DB elérhető, config review jelzés.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$payload = [
  'ok'    => true,
  'db'    => 'ok',
  'config_review' => defined('CONFIG_NEEDS_REVIEW') && CONFIG_NEEDS_REVIEW,
];

try {
  $pdo = db();
  $pdo->query('SELECT 1');
} catch (Throwable $e) {
  $payload['ok'] = false;
  $payload['db'] = 'error';
  $payload['message'] = 'Database unreachable';
  http_response_code(503);
}

echo json_encode($payload);
