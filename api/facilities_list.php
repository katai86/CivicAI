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

$sql = "SELECT id, name, service_type, lat, lng, address, phone, email, hours_json, replacement_json
        FROM facilities WHERE is_active = 1";
$params = [];
if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
  $sql .= " AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?";
  $params[] = (float)$minLat;
  $params[] = (float)$maxLat;
  $params[] = (float)$minLng;
  $params[] = (float)$maxLng;
}
$sql .= " LIMIT $limit";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

json_response(['ok'=>true,'data'=>$rows]);
