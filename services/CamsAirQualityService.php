<?php
/**
 * CAMS / ADS – Air quality (Europe) via ECMWF WMS public token.
 * We use WMS GetFeatureInfo to retrieve numeric values at a point (authority bbox center).
 *
 * Milestone 4.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class CamsAirQualityService
{
    private const BASE_WMS = 'https://eccharts.ecmwf.int/wms/';

    private const LAYERS = [
        'pm25' => 'composition_europe_pm2p5_analysis_surface',
        'pm10' => 'composition_europe_pm10_analysis_surface',
        'no2' => 'composition_europe_no2_analysis_surface',
        'o3' => 'composition_europe_o3_analysis_surface',
    ];

    public function isActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('cams_enabled');
    }

    /**
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return array{
     *   ok:bool,
     *   cached:bool,
     *   lat:float,
     *   lng:float,
     *   pm25:?float,pm10:?float,no2:?float,o3:?float,
     *   unit:string,
     *   air_quality_index:?float,
     *   level:?string,
     *   error:?string,
     *   notes:array<int,string>
     * }
     */
    public function fetchForBBox(array $bbox): array
    {
        $out = [
            'ok' => false,
            'cached' => false,
            'lat' => 0.0,
            'lng' => 0.0,
            'pm25' => null,
            'pm10' => null,
            'no2' => null,
            'o3' => null,
            'unit' => 'µg/m3',
            'air_quality_index' => null,
            'level' => null,
            'error' => null,
            'notes' => [],
        ];
        if (!$this->isActive()) {
            $out['error'] = 'cams_disabled';
            return $out;
        }

        $minLat = (float)$bbox['min_lat'];
        $maxLat = (float)$bbox['max_lat'];
        $minLng = (float)$bbox['min_lng'];
        $maxLng = (float)$bbox['max_lng'];
        if ($maxLat <= $minLat || $maxLng <= $minLng) {
            $out['error'] = 'invalid_bbox';
            return $out;
        }
        $lat = ($minLat + $maxLat) / 2.0;
        $lng = ($minLng + $maxLng) / 2.0;
        $out['lat'] = round($lat, 5);
        $out['lng'] = round($lng, 5);

        $cacheKey = 'cams_aq_' . md5(json_encode([
            'lat' => round($lat, 3),
            'lng' => round($lng, 3),
        ]));
        $hit = ExternalDataCache::getValid('cams', $cacheKey);
        if ($hit && !empty($hit['payload']) && is_array($hit['payload']) && !empty($hit['payload']['ok'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        $vals = [];
        $errors = [];
        foreach (self::LAYERS as $k => $layer) {
            $r = $this->fetchLayerValueAtPoint($layer, $lat, $lng);
            if ($r['ok'] && $r['value'] !== null) {
                $vals[$k] = $r['value'];
            } else {
                $errors[] = $k . ':' . ($r['error'] ?? 'unknown');
            }
        }

        $out['pm25'] = isset($vals['pm25']) ? round((float)$vals['pm25'], 2) : null;
        $out['pm10'] = isset($vals['pm10']) ? round((float)$vals['pm10'], 2) : null;
        $out['no2'] = isset($vals['no2']) ? round((float)$vals['no2'], 2) : null;
        $out['o3'] = isset($vals['o3']) ? round((float)$vals['o3'], 2) : null;

        if (count($vals) === 0) {
            $out['error'] = 'no_values';
            $out['notes'][] = 'cams_wms_featureinfo_failed';
            if (!empty($errors)) {
                $out['notes'][] = 'detail:' . implode(',', array_slice($errors, 0, 8));
            }
            ExternalDataCache::logProvider('cams', 'wms_featureinfo', 'error', $out['error']);
            return $out;
        }

        $idx = $this->computeIndex($out['pm25'], $out['pm10'], $out['no2'], $out['o3']);
        $out['air_quality_index'] = $idx !== null ? round($idx, 2) : null;
        $out['level'] = $out['air_quality_index'] !== null ? $this->indexToLevel($out['air_quality_index']) : null;

        $out['ok'] = true;
        $out['notes'][] = 'cams_wms_public_token';
        ExternalDataCache::set('cams', $cacheKey, $out, 60, 'ok', null);
        ExternalDataCache::logProvider('cams', 'wms_featureinfo', 'ok', 'keys=' . implode(',', array_keys($vals)));
        return $out;
    }

    /**
     * @return array{ok:bool,value:?float,error:?string}
     */
    private function fetchLayerValueAtPoint(string $layer, float $lat, float $lng): array
    {
        $delta = 0.1;
        $minLat = $lat - $delta;
        $maxLat = $lat + $delta;
        $minLng = $lng - $delta;
        $maxLng = $lng + $delta;

        // WMS 1.3.0 axis order for EPSG:4326 is lat,lon. Empirically this works with eccharts.
        $params = [
            'token' => 'public',
            'SERVICE' => 'WMS',
            'VERSION' => '1.3.0',
            'REQUEST' => 'GetFeatureInfo',
            'LAYERS' => $layer,
            'QUERY_LAYERS' => $layer,
            'CRS' => 'EPSG:4326',
            'BBOX' => implode(',', [
                sprintf('%.6f', $minLat),
                sprintf('%.6f', $minLng),
                sprintf('%.6f', $maxLat),
                sprintf('%.6f', $maxLng),
            ]),
            'WIDTH' => '200',
            'HEIGHT' => '200',
            'I' => '100',
            'J' => '100',
            'INFO_FORMAT' => 'text/plain',
        ];
        $url = self::BASE_WMS . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $resp = ExternalHttpClient::get($url);
        if (!$resp['ok']) {
            return ['ok' => false, 'value' => null, 'error' => $resp['error'] ?? ('http_' . ($resp['status'] ?? 0))];
        }
        $body = (string)$resp['body'];
        if (!preg_match('/^Value:\\s*([0-9]+(?:\\.[0-9]+)?)/mi', $body, $m)) {
            return ['ok' => false, 'value' => null, 'error' => 'value_not_found'];
        }
        return ['ok' => true, 'value' => (float)$m[1], 'error' => null];
    }

    private function computeIndex(?float $pm25, ?float $pm10, ?float $no2, ?float $o3): ?float
    {
        $parts = [];
        if ($pm25 !== null) $parts[] = min(1.0, max(0.0, $pm25 / 25.0)); // ~WHO-like daily guidance
        if ($pm10 !== null) $parts[] = min(1.0, max(0.0, $pm10 / 50.0));
        if ($no2 !== null) $parts[] = min(1.0, max(0.0, $no2 / 200.0)); // hourly-ish
        if ($o3 !== null) $parts[] = min(1.0, max(0.0, $o3 / 120.0)); // 8h guideline proxy
        if (count($parts) === 0) return null;
        // Conservative: emphasize the worst component.
        $max = max($parts);
        $avg = array_sum($parts) / count($parts);
        return min(1.0, 0.7 * $max + 0.3 * $avg);
    }

    private function indexToLevel(float $idx): string
    {
        if ($idx < 0.33) return 'good';
        if ($idx < 0.66) return 'moderate';
        return 'poor';
    }
}

