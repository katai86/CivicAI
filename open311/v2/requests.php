<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

header('Content-Type: application/json; charset=utf-8');

$allowedCats = ['road','sidewalk','lighting','trash','green','traffic','idea'];
$statusOpen = ['new','approved','in_progress','needs_info','forwarded','waiting_reply'];
$statusClosed = ['solved','closed','rejected'];

$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = read_json_body();
}

$param = function(string $key) use ($body) {
  if (isset($_POST[$key])) return $_POST[$key];
  if (isset($_GET[$key])) return $_GET[$key];
  if (isset($body[$key])) return $body[$key];
  return null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $serviceCode = safe_str($param('service_code'), 64);
  $desc = safe_str($param('description'), 5000);
  $lat = $param('lat');
  $lng = $param('long');
  $addressStr = safe_str($param('address_string'), 255);
  $email = safe_str($param('email'), 190);
  $firstName = safe_str($param('first_name'), 80);
  $lastName = safe_str($param('last_name'), 80);
  $phone = safe_str($param('phone'), 40);
  $mediaUrl = safe_str($param('media_url'), 255);

  if (!$serviceCode || !in_array($serviceCode, $allowedCats, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid service_code']);
    exit;
  }
  if (!$desc) {
    http_response_code(400);
    echo json_encode(['error' => 'Description required']);
    exit;
  }
  if (!is_numeric($lat) || !is_numeric($lng)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid coordinates']);
    exit;
  }

  $lat = (float)$lat;
  $lng = (float)$lng;
  $reporterName = trim(($firstName ? $firstName : '') . ($lastName ? ' ' . $lastName : ''));
  $isAnonymous = $reporterName ? 0 : 1;
  $authorityId = find_authority_for_report(null, $serviceCode);

  $extra = [];
  if ($phone) $extra[] = 'Tel: ' . $phone;
  if ($mediaUrl) $extra[] = 'Media: ' . $mediaUrl;
  $descFull = $desc . ($extra ? ("\n\n" . implode("\n", $extra)) : '');

  db()->prepare("
    INSERT INTO reports
      (category, title, description, lat, lng, address_approx, status,
       user_id, reporter_email, reporter_name, reporter_is_anonymous,
       notify_token, notify_enabled, authority_id, service_code)
    VALUES
      (:cat, :title, :desc, :lat, :lng, :addr, 'new',
       NULL, :email, :rname, :anon,
       NULL, 0, :aid, :scode)
  ")->execute([
    ':cat' => $serviceCode,
    ':title' => mb_substr($desc, 0, 120),
    ':desc' => $descFull,
    ':lat' => $lat,
    ':lng' => $lng,
    ':addr' => $addressStr,
    ':email' => $email,
    ':rname' => $reporterName,
    ':anon' => $isAnonymous,
    ':aid' => $authorityId,
    ':scode' => $serviceCode
  ]);

  $id = (int)db()->lastInsertId();
  echo json_encode([
    ['service_request_id' => (string)$id, 'status' => 'open']
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$serviceCode = safe_str($param('service_code'), 64);
$status = safe_str($param('status'), 16);
$requestId = safe_str($param('service_request_id'), 64);
$startDate = safe_str($param('start_date'), 32);
$endDate = safe_str($param('end_date'), 32);
$updatedSince = safe_str($param('updated_since'), 32);
$bbox = safe_str($param('bbox'), 80);

$where = [];
$params = [];
if ($serviceCode) {
  $where[] = 'r.category = :cat';
  $params[':cat'] = $serviceCode;
}
if ($status === 'open') {
  $where[] = 'r.status IN (' . implode(',', array_fill(0, count($statusOpen), '?')) . ')';
  $params = array_merge($params, $statusOpen);
} elseif ($status === 'closed') {
  $where[] = 'r.status IN (' . implode(',', array_fill(0, count($statusClosed), '?')) . ')';
  $params = array_merge($params, $statusClosed);
}
if ($requestId) {
  $where[] = 'r.id = :rid';
  $params[':rid'] = $requestId;
}
if ($startDate) {
  $where[] = 'r.created_at >= :start_date';
  $params[':start_date'] = $startDate;
}
if ($endDate) {
  $where[] = 'r.created_at <= :end_date';
  $params[':end_date'] = $endDate;
}
if ($updatedSince) {
  $where[] = 'r.created_at >= :updated_since';
  $params[':updated_since'] = $updatedSince;
}
if ($bbox) {
  $parts = array_map('trim', explode(',', $bbox));
  if (count($parts) === 4 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2]) && is_numeric($parts[3])) {
    $minLng = (float)$parts[0];
    $minLat = (float)$parts[1];
    $maxLng = (float)$parts[2];
    $maxLat = (float)$parts[3];
    $where[] = 'r.lat BETWEEN :minlat AND :maxlat AND r.lng BETWEEN :minlng AND :maxlng';
    $params[':minlat'] = $minLat;
    $params[':maxlat'] = $maxLat;
    $params[':minlng'] = $minLng;
    $params[':maxlng'] = $maxLng;
  }
}

$sql = "
  SELECT r.id, r.category, r.description, r.lat, r.lng, r.status, r.created_at,
    (SELECT MAX(changed_at) FROM report_status_log l WHERE l.report_id = r.id) AS updated_at,
    r.address_approx
  FROM reports r
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY r.id DESC LIMIT 200";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
  $st = $r['status'];
  $isClosed = in_array($st, $statusClosed, true);
  $out[] = [
    'service_request_id' => (string)$r['id'],
    'status' => $isClosed ? 'closed' : 'open',
    'service_code' => (string)$r['category'],
    'service_name' => (string)$r['category'],
    'description' => (string)$r['description'],
    'address' => (string)($r['address_approx'] ?? ''),
    'lat' => (float)$r['lat'],
    'long' => (float)$r['lng'],
    'requested_datetime' => (string)$r['created_at'],
    'updated_datetime' => (string)($r['updated_at'] ?: $r['created_at'])
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
