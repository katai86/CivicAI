<?php
/**
 * OpenStreetMap Overpass → aggregated urban structure observations (real HTTP).
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../ExternalHttpClient.php';
require_once __DIR__ . '/CityObservationStore.php';

final class OsmOverpassIngester
{
    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /**
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return array{ok:bool,observations:list<array<string,mixed>>,error:?string,raw_counts:array<string,int>}
     */
    public function fetchAggregates(?int $authorityId, array $bbox, ?string $adminArea = null): array
    {
        $south = (float)$bbox['min_lat'];
        $west = (float)$bbox['min_lng'];
        $north = (float)$bbox['max_lat'];
        $east = (float)$bbox['max_lng'];
        if (!($south < $north && $west < $east)) {
            return ['ok' => false, 'observations' => [], 'error' => 'invalid_bbox', 'raw_counts' => []];
        }

        // Compact count queries – one round-trip
        $ql = <<<QL
[out:json][timeout:45];
(
  way["highway"]($south,$west,$north,$east);
  way["highway"="cycleway"]($south,$west,$north,$east);
  way["leisure"="park"]($south,$west,$north,$east);
  way["landuse"="grass"]($south,$west,$north,$east);
  way["landuse"="forest"]($south,$west,$north,$east);
  node["amenity"~"school|hospital|library|townhall|community_centre"]($south,$west,$north,$east);
  node["amenity"="charging_station"]($south,$west,$north,$east);
  way["building"]($south,$west,$north,$east);
);
out tags;
QL;

        $body = null;
        $error = null;
        foreach (self::ENDPOINTS as $endpoint) {
            $resp = $this->post($endpoint, $ql, 50);
            if ($resp['ok'] && $resp['body'] !== '') {
                $body = $resp['body'];
                break;
            }
            $error = $resp['error'] ?? ('http_' . ($resp['status'] ?? 0));
        }
        if ($body === null) {
            return ['ok' => false, 'observations' => [], 'error' => $error ?: 'overpass_unreachable', 'raw_counts' => []];
        }

        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['elements']) || !is_array($j['elements'])) {
            return ['ok' => false, 'observations' => [], 'error' => 'invalid_overpass_json', 'raw_counts' => []];
        }

        $counts = [
            'road_ways' => 0,
            'cycleways' => 0,
            'parks' => 0,
            'green_landuse' => 0,
            'public_amenities' => 0,
            'ev_chargers_osm' => 0,
            'buildings' => 0,
        ];
        foreach ($j['elements'] as $el) {
            if (!is_array($el)) {
                continue;
            }
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $type = (string)($el['type'] ?? '');
            if ($type === 'way' && isset($tags['highway'])) {
                $counts['road_ways']++;
                if (($tags['highway'] ?? '') === 'cycleway' || (($tags['cycleway'] ?? '') !== '' && ($tags['cycleway'] ?? '') !== 'no')) {
                    $counts['cycleways']++;
                }
            }
            if ($type === 'way' && ($tags['leisure'] ?? '') === 'park') {
                $counts['parks']++;
            }
            if ($type === 'way' && in_array(($tags['landuse'] ?? ''), ['grass', 'forest'], true)) {
                $counts['green_landuse']++;
            }
            if ($type === 'way' && isset($tags['building'])) {
                $counts['buildings']++;
            }
            if ($type === 'node' && isset($tags['amenity'])) {
                $a = (string)$tags['amenity'];
                if (in_array($a, ['school', 'hospital', 'library', 'townhall', 'community_centre'], true)) {
                    $counts['public_amenities']++;
                }
                if ($a === 'charging_station') {
                    $counts['ev_chargers_osm']++;
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        $centerLat = ($south + $north) / 2;
        $centerLng = ($west + $east) / 2;
        $areaKm2 = max(0.01, abs($north - $south) * 111.0 * abs($east - $west) * 111.0 * cos(deg2rad($centerLat)));

        $map = [
            'osm.road_ways' => [$counts['road_ways'], 'count'],
            'osm.cycleways' => [$counts['cycleways'], 'count'],
            'osm.parks' => [$counts['parks'], 'count'],
            'osm.green_polygons' => [$counts['green_landuse'], 'count'],
            'osm.public_amenities' => [$counts['public_amenities'], 'count'],
            'osm.ev_chargers' => [$counts['ev_chargers_osm'], 'count'],
            'osm.buildings' => [$counts['buildings'], 'count'],
            'osm.building_density_per_km2' => [round($counts['buildings'] / $areaKm2, 3), 'per_km2'],
            'osm.amenity_density_per_km2' => [round($counts['public_amenities'] / $areaKm2, 3), 'per_km2'],
            'osm.green_feature_density_per_km2' => [round(($counts['parks'] + $counts['green_landuse']) / $areaKm2, 3), 'per_km2'],
        ];

        $observations = [];
        foreach ($map as $ind => [$val, $unit]) {
            $observations[] = [
                'authority_id' => $authorityId,
                'source_key' => 'osm_overpass',
                'source_type' => 'urban_structure',
                'external_id' => $ind . '@' . date('Y-m-d'),
                'category' => 'urban_structure',
                'indicator_type' => $ind,
                'observed_at' => $now,
                'lat' => $centerLat,
                'lng' => $centerLng,
                'admin_area' => $adminArea,
                'value_num' => (float)$val,
                'unit' => $unit,
                'confidence' => 0.85,
                'quality' => 0.80,
                'metadata' => [
                    'bbox' => $bbox,
                    'area_km2' => round($areaKm2, 3),
                    'raw_counts' => $counts,
                    'measured' => true,
                ],
                'raw_ref' => 'overpass:' . date('c'),
            ];
        }

        return ['ok' => true, 'observations' => $observations, 'error' => null, 'raw_counts' => $counts];
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    private function post(string $url, string $ql, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init_failed'];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['data' => $ql]),
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => ExternalHttpClient::userAgent(),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err ?: 'curl_failed'];
            }
            $ok = $status >= 200 && $status < 300;
            return ['ok' => $ok, 'status' => $status, 'body' => (string)$body, 'error' => $ok ? null : ('http_' . $status)];
        }
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_required_for_overpass'];
    }
}
