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
  SELECT r.id, r.category, r.title, r.description, r.lat, r.lng,
         r.address_approx, r.house_number_approx, r.road, r.suburb, r.city, r.postcode,
         r.status, r.created_at,
         r.reporter_name, r.reporter_is_anonymous,
         u.id AS reporter_user_id,
         u.display_name AS reporter_display_name,
         u.profile_public AS reporter_profile_public,
         u.level AS reporter_level
  FROM reports r
  LEFT JOIN users u ON u.id = r.user_id
  $where
  ORDER BY r.created_at DESC
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