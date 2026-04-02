<?php
/**
 * CDS / ERA5-Land kontextus – napi összesítők a hatóság bbox közepén.
 * Adat: Open-Meteo Historical (ERA5), JSON, kulcs nélkül; nem helyettesíti a CDS API-t (NetCDF letöltés).
 * Milestone 5.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class CdsEra5ClimateService
{
    private const ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';
    private const WINDOW_DAYS = 30;

    public function isActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('cds_enabled');
    }

    /**
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return array{
     *   ok:bool,cached:bool,
     *   lat:float,lng:float,
     *   period_start:?string,period_end:?string,
     *   temp_mean_c:?float,temp_min_c:?float,temp_max_c:?float,
     *   precip_sum_mm:?float,
     *   warm_days:int,frost_days:int,
     *   dryness_index:?float,
     *   error:?string,notes:array<int,string>
     * }
     */
    public function fetchForBBox(array $bbox): array
    {
        $empty = [
            'ok' => false,
            'cached' => false,
            'lat' => 0.0,
            'lng' => 0.0,
            'period_start' => null,
            'period_end' => null,
            'temp_mean_c' => null,
            'temp_min_c' => null,
            'temp_max_c' => null,
            'precip_sum_mm' => null,
            'warm_days' => 0,
            'frost_days' => 0,
            'dryness_index' => null,
            'error' => null,
            'notes' => [],
        ];
        if (!$this->isActive()) {
            $empty['error'] = 'cds_disabled';
            return $empty;
        }
        $minLat = (float)$bbox['min_lat'];
        $maxLat = (float)$bbox['max_lat'];
        $minLng = (float)$bbox['min_lng'];
        $maxLng = (float)$bbox['max_lng'];
        if ($maxLat <= $minLat || $maxLng <= $minLng) {
            $empty['error'] = 'invalid_bbox';
            return $empty;
        }
        $lat = ($minLat + $maxLat) / 2.0;
        $lng = ($minLng + $maxLng) / 2.0;
        $empty['lat'] = round($lat, 5);
        $empty['lng'] = round($lng, 5);

        $end = new DateTimeImmutable('yesterday', new DateTimeZone('UTC'));
        $start = $end->sub(new DateInterval('P' . (self::WINDOW_DAYS - 1) . 'D'));
        $startStr = $start->format('Y-m-d');
        $endStr = $end->format('Y-m-d');

        $cacheKey = 'era5_om_' . md5(json_encode([
            'lat' => round($lat, 3),
            'lng' => round($lng, 3),
            'end' => $endStr,
        ]));
        $hit = ExternalDataCache::getValid('cds', $cacheKey);
        if ($hit && !empty($hit['payload']['ok'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        $params = [
            'latitude' => sprintf('%.5f', $lat),
            'longitude' => sprintf('%.5f', $lng),
            'start_date' => $startStr,
            'end_date' => $endStr,
            'daily' => 'temperature_2m_mean,precipitation_sum',
            'timezone' => 'GMT',
        ];
        $url = self::ARCHIVE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $resp = ExternalHttpClient::get($url);
        if (!$resp['ok']) {
            $err = $resp['error'] ?? ('http_' . ($resp['status'] ?? 0));
            ExternalDataCache::logProvider('cds', 'open_meteo_archive', 'error', $err);
            $empty['error'] = $err;
            $empty['notes'][] = 'open_meteo_request_failed';
            return $empty;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j) || empty($j['daily']) || !is_array($j['daily'])) {
            ExternalDataCache::logProvider('cds', 'open_meteo_archive', 'error', 'invalid_json');
            $empty['error'] = 'invalid_json';
            return $empty;
        }
        $daily = $j['daily'];
        $times = isset($daily['time']) && is_array($daily['time']) ? $daily['time'] : [];
        $temps = isset($daily['temperature_2m_mean']) && is_array($daily['temperature_2m_mean']) ? $daily['temperature_2m_mean'] : [];
        $precips = isset($daily['precipitation_sum']) && is_array($daily['precipitation_sum']) ? $daily['precipitation_sum'] : [];
        $n = min(count($times), count($temps), count($precips));
        if ($n < 1) {
            ExternalDataCache::logProvider('cds', 'open_meteo_archive', 'error', 'no_daily_rows');
            $empty['error'] = 'no_daily_rows';
            return $empty;
        }

        $tvals = [];
        $pSum = 0.0;
        $warm = 0;
        $frost = 0;
        for ($i = 0; $i < $n; $i++) {
            $tv = $temps[$i];
            if ($tv === null || $tv === '') {
                continue;
            }
            $t = (float)$tv;
            $tvals[] = $t;
            if ($t >= 25.0) {
                $warm++;
            }
            if ($t < 0.0) {
                $frost++;
            }
            $pv = $precips[$i];
            if ($pv !== null && $pv !== '') {
                $pSum += (float)$pv;
            }
        }
        if (count($tvals) === 0) {
            $empty['error'] = 'no_temperature_values';
            return $empty;
        }

        $tMean = array_sum($tvals) / count($tvals);
        $tMin = min($tvals);
        $tMax = max($tvals);
        // Egyszerű szárazság-proxy: kevés csap + meleg (0–1)
        $expectedRain = 1.2 * self::WINDOW_DAYS;
        $dry = min(1.0, max(0.0, 1.0 - ($pSum / max(5.0, $expectedRain))));
        $heatBoost = min(1.0, max(0.0, ($tMean - 10.0) / 20.0));
        $dryness = round(min(1.0, 0.55 * $dry + 0.45 * $heatBoost), 2);

        $out = [
            'ok' => true,
            'cached' => false,
            'lat' => round($lat, 5),
            'lng' => round($lng, 5),
            'period_start' => $startStr,
            'period_end' => $endStr,
            'temp_mean_c' => round($tMean, 1),
            'temp_min_c' => round($tMin, 1),
            'temp_max_c' => round($tMax, 1),
            'precip_sum_mm' => round($pSum, 1),
            'warm_days' => $warm,
            'frost_days' => $frost,
            'dryness_index' => $dryness,
            'error' => null,
            'notes' => [
                'era5_via_open_meteo_archive',
                'not_official_cds_download',
            ],
        ];
        ExternalDataCache::set('cds', $cacheKey, $out, 360, 'ok', null);
        ExternalDataCache::logProvider('cds', 'open_meteo_archive', 'ok', 'days=' . $n);
        return $out;
    }
}
