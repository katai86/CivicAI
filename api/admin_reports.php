<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$status = $_GET['status'] ?? 'pending';

// bővített státusz készlet + legacy
$allowed = [
  'pending','approved','rejected',
  'new','needs_info','forwarded','waiting_reply','in_progress','solved','closed',
  'all'
];

if (!in_array($status, $allowed, true)) $status = 'pending';

$where = "";
$params = [];

if ($status !== 'all') {
  $where = "WHERE status = :st";
  $params[':st'] = $status;
}

$sql = "
  SELECT id, category, title, description, lat, lng,
         address_approx, house_number_approx, road, suburb, city, postcode,
         status, created_at
  FROM reports
  $where
  ORDER BY created_at DESC
  LIMIT 2000
";

$stmt = db()->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll();

// Ügyszám hozzáadása (DB változtatás nélkül)
foreach ($rows as &$r) {
  $rid = (int)($r['id'] ?? 0);
  $createdAt = isset($r['created_at']) ? (string)$r['created_at'] : null;
  $r['case_no'] = $rid > 0 ? case_number($rid, $createdAt) : null;
}
unset($r);

json_response(['ok'=>true,'data'=>$rows]);