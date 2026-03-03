<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$out = [
  'reports_7d' => 0,
  'users_7d' => 0,
  'status' => [],
];

try {
  $stmt = db()->query("SELECT COUNT(*) FROM reports WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
  $out['reports_7d'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

try {
  $rows = db()->query("SELECT status, COUNT(*) AS cnt FROM reports GROUP BY status")->fetchAll() ?: [];
  foreach ($rows as $r) {
    $out['status'][(string)$r['status']] = (int)$r['cnt'];
  }
} catch (Throwable $e) { /* ignore */ }

// users.created_at may not exist in older schema
try {
  $stmt = db()->query("SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
  $out['users_7d'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
  $out['users_7d'] = 0;
}

json_response(['ok'=>true,'data'=>$out]);
