<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$status = $_GET['status'] ?? 'pending';
$q = trim((string)($_GET['q'] ?? ''));
$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
$limit = (int)($_GET['limit'] ?? 300);
$offset = (int)($_GET['offset'] ?? 0);
if ($limit < 50 || $limit > 2000) $limit = 300;
if ($offset < 0) $offset = 0;

// bővített státusz készlet + legacy
$allowed = [
  'pending','approved','rejected',
  'new','needs_info','forwarded','waiting_reply','in_progress','solved','closed',
  'all'
];

if (!in_array($status, $allowed, true)) $status = 'pending';

$whereParts = [];
$params = [];

if ($status !== 'all') {
  $whereParts[] = "r.status = :st";
  $params[':st'] = $status;
}

$useAuthority = ($authorityId > 0);
if ($useAuthority) {
  $whereParts[] = "r.authority_id = :aid";
  $params[':aid'] = $authorityId;
}

$where = "";
if ($q !== '') {
  $whereParts[] = "(r.title LIKE :q OR r.description LIKE :q OR r.reporter_name LIKE :q OR u.display_name LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

if ($whereParts) {
  $where = "WHERE " . implode(" AND ", $whereParts);
}

$sql = "
  SELECT r.id, r.category, r.title, r.description, r.lat, r.lng,
         r.address_approx, r.house_number_approx, r.road, r.suburb, r.city, r.postcode,
         r.status, r.created_at, r.authority_id,
         r.reporter_name, r.reporter_is_anonymous,
         u.id AS reporter_user_id,
         u.display_name AS reporter_display_name,
         u.profile_public AS reporter_profile_public,
         u.level AS reporter_level,
         a.name AS authority_name
  FROM reports r
  LEFT JOIN users u ON u.id = r.user_id
  LEFT JOIN authorities a ON a.id = r.authority_id
  $where
  ORDER BY r.created_at DESC
  LIMIT $limit OFFSET $offset
";

$rows = [];
try {
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  // Fallback 1: nincs authority / reports.authority_id
  $wherePartsFallback = array_filter($whereParts, function ($w) { return strpos($w, 'authority_id') === false; });
  $paramsFallback = $params;
  unset($paramsFallback[':aid']);
  $whereFallback = $wherePartsFallback ? "WHERE " . implode(" AND ", $wherePartsFallback) : "";
  $sqlFallback = "
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
    $whereFallback
    ORDER BY r.created_at DESC
    LIMIT $limit OFFSET $offset
  ";
  try {
    $stmt = db()->prepare($sqlFallback);
    $stmt->execute($paramsFallback);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
      $row['authority_id'] = null;
      $row['authority_name'] = null;
    }
    unset($row);
  } catch (Throwable $e2) {
    // Fallback 2: minimális oszlopok (régi schema: nincs reporter_name, profile_public, level, stb.)
    if ($status !== 'all') {
      $whereMinimal = "WHERE r.status = :st";
      $paramsMinimal = [':st' => $status];
    } else {
      $whereMinimal = "";
      $paramsMinimal = [];
    }
    $sqlMinimal = "
      SELECT r.id, r.category, r.title, r.description, r.lat, r.lng, r.address_approx,
             r.status, r.created_at, r.user_id,
             u.id AS reporter_user_id, u.display_name AS reporter_display_name
      FROM reports r
      LEFT JOIN users u ON u.id = r.user_id
      $whereMinimal
      ORDER BY r.created_at DESC
      LIMIT $limit OFFSET $offset
    ";
    try {
      $stmt = db()->prepare($sqlMinimal);
      $stmt->execute($paramsMinimal);
      $rows = $stmt->fetchAll();
      foreach ($rows as &$row) {
        $row['authority_id'] = null;
        $row['authority_name'] = null;
        $row['reporter_name'] = null;
        $row['reporter_is_anonymous'] = null;
        $row['reporter_profile_public'] = null;
        $row['reporter_level'] = null;
        $row['house_number_approx'] = $row['road'] = $row['suburb'] = $row['city'] = $row['postcode'] = null;
      }
      unset($row);
    } catch (Throwable $e3) {
      log_error('admin_reports: ' . $e3->getMessage() . ' in ' . $e3->getFile() . ':' . $e3->getLine());
      json_response(['ok' => true, 'data' => [], 'warning' => 'Betöltési hiba (ellenőrizd az adatbázis séma megfelelőségét).']);
    }
  }
}

// Ügyszám hozzáadása (DB változtatás nélkül)
foreach ($rows as &$r) {
  $rid = (int)($r['id'] ?? 0);
  $createdAt = isset($r['created_at']) ? (string)$r['created_at'] : null;
  $r['case_no'] = $rid > 0 ? case_number($rid, $createdAt) : null;
}
unset($r);

json_response(['ok'=>true,'data'=>$rows]);