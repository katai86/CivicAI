<?php
/**
 * GET lat, lng – TomTom reverse geocoding (cím a koordinátákból). Kulcs szerveren marad.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

if (get_module_setting('geocode', 'enabled') !== '1') {
    json_response(['ok' => false, 'error' => t('geocode.api_module_off')], 403);
}

$apiKey = trim((string)(get_module_setting('geocode', 'tomtom_api_key') ?? ''));
if ($apiKey === '') {
    json_response(['ok' => false, 'error' => t('geocode.api_no_tomtom_key')], 400);
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
    json_response(['ok' => false, 'error' => t('geocode.api_bad_query')], 400);
}
if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
    json_response(['ok' => false, 'error' => t('geocode.api_bad_query')], 400);
}

$pos = rawurlencode((string)$lat . ',' . (string)$lng);
$url = 'https://api.tomtom.com/search/2/reverseGeocode/' . $pos . '.json?key=' . rawurlencode($apiKey);
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\n",
        'timeout' => 10,
    ],
];

try {
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) {
        json_response(['ok' => false, 'error' => t('geocode.api_upstream')], 502);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        json_response(['ok' => false, 'error' => t('geocode.api_upstream')], 502);
    }
    $addresses = $json['addresses'] ?? [];
    if (!is_array($addresses) || $addresses === []) {
        json_response(['ok' => true, 'results' => []]);
    }
    $first = $addresses[0];
    $addr = is_array($first['address'] ?? null) ? $first['address'] : [];
    $parts = civic_tomtom_address_parts($addr);
    $display = $parts['display_name'] !== '' ? $parts['display_name'] : (string)($addr['freeformAddress'] ?? '');
    json_response([
        'ok' => true,
        'postal_code' => $parts['postal_code'],
        'city' => $parts['city'],
        'street' => $parts['street'],
        'house' => $parts['house'],
        'display_name' => $display,
    ]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('geocode_reverse: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('geocode.api_upstream')], 502);
}
