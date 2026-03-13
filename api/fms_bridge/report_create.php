<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>t('api.method_not_allowed')], 405);
}

require_user();
start_secure_session();

if (!fms_enabled()) {
  json_response(['ok'=>false,'error'=>'FMS not configured'], 400);
}

$body = read_json_body();
$category = safe_str($body['category'] ?? null, 32);
$description = safe_str($body['description'] ?? null, 5000);
$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;
$address = safe_str($body['address'] ?? null, 255);
$email = safe_str($body['email'] ?? null, 190);
$name = safe_str($body['name'] ?? null, 80);

if (!$category || !$description) json_response(['ok'=>false,'error'=>t('api.missing_fields')], 400);
if (!is_numeric($lat) || !is_numeric($lng)) json_response(['ok'=>false,'error'=>t('api.invalid_coordinates')], 400);

$payload = [
  'api_key' => fms_config_api_key(),
  'jurisdiction_id' => fms_config_jurisdiction(),
  'service_code' => $category,
  'lat' => (float)$lat,
  'long' => (float)$lng,
  'description' => $description,
];
if ($address) $payload['address_string'] = $address;
if ($email) $payload['email'] = $email;
if ($name) $payload['first_name'] = $name;

$resp = fms_open311_request($payload);
if (!$resp['ok']) {
  json_response(['ok'=>false,'error'=>$resp['error']], 502);
}

json_response(['ok'=>true,'data'=>$resp['data']]);
