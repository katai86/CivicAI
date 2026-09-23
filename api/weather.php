<?php
/**
 * Időjárás API – Open-Meteo összekapcsolás.
 * GET: lat, lng (opcionális, alapértelmezett: MAP_CENTER), vagy authority scope.
 * Válasz: ok, data { temp, feels_like, humidity, description, icon, updated }.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('WEATHER_ENABLED') || !WEATHER_ENABLED) {
  echo json_encode(['ok' => false, 'error' => 'Weather not enabled'], JSON_UNESCAPED_UNICODE);
  exit;
}

$lat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float)$_GET['lat'] : (defined('MAP_CENTER_LAT') ? (float)MAP_CENTER_LAT : 47.1625);
$lng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float)$_GET['lng'] : (defined('MAP_CENTER_LNG') ? (float)MAP_CENTER_LNG : 19.5033);

$url = sprintf(
  'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=temperature_2m,relative_humidity_2m,weather_code&timezone=auto',
  $lat,
  $lng
);

$ctx = stream_context_create([
  'http' => [
    'timeout' => 5,
    'user_agent' => 'CivicAI/1.0',
  ],
]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
  echo json_encode(['ok' => false, 'error' => 'Weather service unavailable'], JSON_UNESCAPED_UNICODE);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['current'])) {
  echo json_encode(['ok' => false, 'error' => 'Invalid response'], JSON_UNESCAPED_UNICODE);
  exit;
}

$cur = $data['current'];
$code = (int)($cur['weather_code'] ?? 0);
$knownCodes = [0, 1, 2, 3, 45, 48, 51, 53, 55, 61, 63, 65, 71, 73, 75, 80, 81, 82, 95, 96];
$descKey = in_array($code, $knownCodes, true) ? ('weather.wmo_' . $code) : 'weather.wmo_unknown';
$description = t($descKey);
if ($description === $descKey) {
  $description = t('weather.wmo_unknown');
}

echo json_encode([
  'ok' => true,
  'data' => [
    'temp' => isset($cur['temperature_2m']) ? round((float)$cur['temperature_2m'], 1) : null,
    'feels_like' => isset($cur['temperature_2m']) ? round((float)$cur['temperature_2m'], 1) : null,
    'humidity' => isset($cur['relative_humidity_2m']) ? (int)$cur['relative_humidity_2m'] : null,
    'description' => $description,
    'weather_code' => $code,
    'updated' => date('c'),
  ],
], JSON_UNESCAPED_UNICODE);
