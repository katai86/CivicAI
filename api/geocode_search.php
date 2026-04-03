<?php
/**
 * GET q, provider (nominatim|tomtom), limit – címkeresés (Nominatim / TomTom), kulcs szerveren marad.
 * Csak ha a geocode modul be van kapcsolva. TomTom: API kulcs szerveren (nyilvános térkép is használhatja).
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

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q === '' || mb_strlen($q) > 200) {
    json_response(['ok' => false, 'error' => t('geocode.api_bad_query')], 400);
}

$provider = strtolower(trim((string)($_GET['provider'] ?? 'nominatim')));
if (!in_array($provider, ['nominatim', 'tomtom'], true)) {
    $provider = 'nominatim';
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
$limit = min(10, max(1, $limit));

$countryRaw = trim((string)(get_module_setting('geocode', 'country_codes') ?: 'hu'));
$countryNominatim = strtolower(preg_replace('/[^a-z,]/', '', $countryRaw));
$countryTomtom = strtoupper(preg_replace('/[^a-zA-Z,]/', '', str_replace(';', ',', $countryRaw)));

if ($provider === 'tomtom') {
    $apiKey = trim((string)(get_module_setting('geocode', 'tomtom_api_key') ?? ''));
    if ($apiKey === '') {
        json_response(['ok' => false, 'error' => t('geocode.api_no_tomtom_key')], 400);
    }
    $seg = rawurlencode($q);
    $url = 'https://api.tomtom.com/search/2/search/' . $seg . '.json?key=' . rawurlencode($apiKey) . '&limit=' . $limit;
    if ($countryTomtom !== '') {
        $url .= '&countrySet=' . rawurlencode($countryTomtom);
    }
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
        $results = [];
        foreach ($json['results'] ?? [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $pos = $r['position'] ?? null;
            if (!is_array($pos)) {
                continue;
            }
            $lat = isset($pos['lat']) ? (float)$pos['lat'] : null;
            $lng = isset($pos['lon']) ? (float)$pos['lon'] : null;
            if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
                continue;
            }
            $addr = is_array($r['address'] ?? null) ? $r['address'] : [];
            $parts = function_exists('civic_tomtom_address_parts') ? civic_tomtom_address_parts($addr) : [
                'postal_code' => '',
                'city' => '',
                'street' => '',
                'house' => '',
                'display_name' => '',
            ];
            $label = $parts['display_name'] !== '' ? $parts['display_name'] : '';
            if ($label === '') {
                $label = isset($r['poi']['name']) ? (string)$r['poi']['name'] : '';
            }
            if ($label === '') {
                $label = $q;
            }
            $results[] = [
                'lat' => (string)$lat,
                'lon' => (string)$lng,
                'display_name' => $label,
                'postal_code' => $parts['postal_code'],
                'city' => $parts['city'],
                'street' => $parts['street'],
                'house' => $parts['house'],
            ];
        }
        json_response(['ok' => true, 'provider' => 'tomtom', 'results' => $results]);
    } catch (Throwable $e) {
        if (function_exists('log_error')) {
            log_error('geocode_search tomtom: ' . $e->getMessage());
        }
        json_response(['ok' => false, 'error' => t('geocode.api_upstream')], 502);
    }
}

$list = nominatim_geocode_forward_list($q, $limit, $countryNominatim);
json_response(['ok' => true, 'provider' => 'nominatim', 'results' => $list]);
