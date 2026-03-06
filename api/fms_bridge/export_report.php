<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

start_secure_session();
require_user();

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if (!$isAdmin && $role !== 'govuser') {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if (!fms_enabled()) {
  json_response(['ok' => false, 'error' => 'FMS not configured'], 400);
}

$body = read_json_body();
$reportId = (int)($body['report_id'] ?? $body['id'] ?? 0);
if ($reportId <= 0) {
  json_response(['ok' => false, 'error' => 'Invalid report_id'], 400);
}

$pdo = db();

// Govuser: csak a saját hatóságához tartozó reportot exportálhatja
if (!$isAdmin) {
  $uid = current_user_id();
  $authorityIds = [];
  try {
    $stmt = $pdo->prepare("SELECT authority_id FROM authority_users WHERE user_id = :uid");
    $stmt->execute([':uid' => $uid]);
    $authorityIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'authority_id'));
  } catch (Throwable $e) {
    // authority_users hiányozhat
    json_response(['ok' => false, 'error' => 'Az authority_users tábla hiányozhat.'], 503);
  }
  if (!$authorityIds) {
    json_response(['ok' => false, 'error' => 'Nincs hatóság hozzárendelve ehhez a fiókhoz.'], 403);
  }

  $stmt = $pdo->prepare("SELECT authority_id FROM reports WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $reportId]);
  $aid = (int)($stmt->fetchColumn() ?: 0);
  if ($aid <= 0 || !in_array($aid, $authorityIds, true)) {
    json_response(['ok' => false, 'error' => 'Nincs jogosultság.'], 403);
  }
}

// Már exportált?
try {
  $stmt = $pdo->prepare("SELECT open311_service_request_id FROM fms_reports WHERE report_id = :rid LIMIT 1");
  $stmt->execute([':rid' => $reportId]);
  $existing = (string)($stmt->fetchColumn() ?: '');
  if ($existing !== '') {
    json_response(['ok' => true, 'service_request_id' => $existing, 'already_exported' => true]);
  }
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'A fms_reports tábla hiányozhat. Futtasd a megfelelő SQL migrációt.'], 503);
}

// Lokális report betöltése
$stmt = $pdo->prepare("
  SELECT id, category, service_code, title, description, lat, lng, address_approx,
         reporter_email, reporter_name, reporter_is_anonymous
  FROM reports
  WHERE id = :id
  LIMIT 1
");
$stmt->execute([':id' => $reportId]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) {
  json_response(['ok' => false, 'error' => 'Report not found'], 404);
}

$serviceCode = (string)($r['service_code'] ?: $r['category']);
$desc = (string)($r['description'] ?? '');
$latRaw = $r['lat'] ?? null;
$lngRaw = $r['lng'] ?? null;
if (!$serviceCode || $desc === '' || $latRaw === null || $lngRaw === null || !is_numeric($latRaw) || !is_numeric($lngRaw)) {
  json_response(['ok' => false, 'error' => 'Report missing required fields for export'], 400);
}
$lat = (float)$latRaw;
$lng = (float)$lngRaw;

$payload = [
  'api_key' => (string)FMS_OPEN311_API_KEY,
  'jurisdiction_id' => (string)FMS_OPEN311_JURISDICTION,
  'service_code' => $serviceCode,
  'lat' => (float)$lat,
  'long' => (float)$lng,
  'description' => $desc,
];
if (!empty($r['address_approx'])) $payload['address_string'] = (string)$r['address_approx'];
if (!empty($r['reporter_email'])) $payload['email'] = (string)$r['reporter_email'];
if (!empty($r['reporter_name']) && (int)($r['reporter_is_anonymous'] ?? 1) === 0) $payload['first_name'] = (string)$r['reporter_name'];

$resp = fms_open311_request($payload);
if (!$resp['ok']) {
  json_response(['ok' => false, 'error' => $resp['error']], 502);
}

$sid = '';
if (is_array($resp['data'])) {
  // Tipikus Open311 válasz: [{ service_request_id: "..." }]
  $first = $resp['data'][0] ?? null;
  if (is_array($first) && isset($first['service_request_id'])) {
    $sid = (string)$first['service_request_id'];
  }
}
if ($sid === '') {
  json_response(['ok' => false, 'error' => 'Unexpected Open311 response'], 502);
}

try {
  $pdo->prepare("
    INSERT INTO fms_reports (report_id, open311_service_request_id, last_status, last_updated_at)
    VALUES (:rid, :sid, :st, :ua)
  ")->execute([
    ':rid' => $reportId,
    ':sid' => $sid,
    ':st' => 'open',
    ':ua' => gmdate('Y-m-d H:i:s'),
  ]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Failed to store fms mapping'], 500);
}

// Best-effort: reports.external_id / external_status (ha van)
try {
  $pdo->prepare("UPDATE reports SET external_id = :sid, external_status = 'open' WHERE id = :id")
    ->execute([':sid' => $sid, ':id' => $reportId]);
} catch (Throwable $e) { /* ignore */ }

json_response(['ok' => true, 'service_request_id' => $sid, 'already_exported' => false]);

