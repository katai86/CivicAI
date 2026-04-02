<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$minLat = $_GET['minLat'] ?? null;
$maxLat = $_GET['maxLat'] ?? null;
$minLng = $_GET['minLng'] ?? null;
$maxLng = $_GET['maxLng'] ?? null;
$limit = (int)($_GET['limit'] ?? 2000);
if ($limit < 100 || $limit > 5000) $limit = 2000;

$rows = [];
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
if (!in_array($type, ['','civil','green_action'], true)) {
  $type = '';
}
try {
  $today = date('Y-m-d');
  $sql = "SELECT id, title, description, start_date, end_date, lat, lng, address, event_type, participants_count
          FROM civil_events
          WHERE is_active = 1
            AND start_date <= :today AND end_date >= :today";
  $params = [':today'=>$today];

  if ($type !== '') {
    $sql .= " AND event_type = :etype";
    $params[':etype'] = $type;
  }

  if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
    $sql .= " AND lat BETWEEN :minLat AND :maxLat AND lng BETWEEN :minLng AND :maxLng";
    $params[':minLat'] = (float)$minLat;
    $params[':maxLat'] = (float)$maxLat;
    $params[':minLng'] = (float)$minLng;
    $params[':maxLng'] = (float)$maxLng;
  }
  $sql .= " LIMIT $limit";

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
  // civil_events tábla hiányozhat
}

json_response(['ok'=>true,'data'=>$rows]);
