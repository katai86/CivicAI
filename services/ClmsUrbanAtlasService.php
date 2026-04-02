<?php
/**
 * CLMS – Urban Atlas 2018 (EEA Discomap ArcGIS REST).
 * Terület-súlyozott megoszlás a hatóság bbox és a „Land Use vector” réteg metszéséből (group statistics).
 * Milestone 3.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class ClmsUrbanAtlasService
{
    private const QUERY_URL = 'https://image.discomap.eea.europa.eu/arcgis/rest/services/UrbanAtlas/UA_UrbanAtlas_2018/MapServer/2/query';
    /** Ha a bbox nagyobb (km²), nem hívjuk az EEA-t (FU-skála, túl nagy lekérdezés). */
    private const MAX_BBOX_AREA_KM2 = 3500;

    public function isActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('clms_enabled');
    }

    /**
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return array{
     *   ok:bool,
     *   cached:bool,
     *   ua_reference_year:int,
     *   ua_built_share:?float,
     *   ua_green_urban_share:?float,
     *   ua_pervious_green_share:?float,
     *   ua_water_share:?float,
     *   ua_class_rows:int,
     *   error:?string,
     *   notes:array<int,string>
     * }
     */
    public function fetchSharesForBBox(array $bbox): array
    {
        $empty = [
            'ok' => false,
            'cached' => false,
            'ua_reference_year' => 2018,
            'ua_built_share' => null,
            'ua_green_urban_share' => null,
            'ua_pervious_green_share' => null,
            'ua_water_share' => null,
            'ua_class_rows' => 0,
            'error' => null,
            'notes' => [],
        ];
        if (!$this->isActive()) {
            $empty['error'] = 'clms_disabled';
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
        $areaKm2 = $this->bboxAreaKm2($bbox);
        if ($areaKm2 <= 0 || $areaKm2 > self::MAX_BBOX_AREA_KM2) {
            $empty['error'] = 'bbox_area_out_of_range';
            $empty['notes'][] = 'urban_atlas_fua_scale_max_' . self::MAX_BBOX_AREA_KM2 . 'km2';
            return $empty;
        }

        $keyBBox = [
            'min_lat' => round($minLat, 4),
            'max_lat' => round($maxLat, 4),
            'min_lng' => round($minLng, 4),
            'max_lng' => round($maxLng, 4),
        ];
        $cacheKey = 'ua2018_stats_' . md5(json_encode($keyBBox));
        $hit = ExternalDataCache::getValid('clms', $cacheKey);
        if ($hit && isset($hit['payload']['ok']) && $hit['payload']['ok']) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        $geom = json_encode([
            'xmin' => $minLng,
            'ymin' => $minLat,
            'xmax' => $maxLng,
            'ymax' => $maxLat,
            'spatialReference' => ['wkid' => 4326],
        ], JSON_UNESCAPED_SLASHES);
        $outStats = json_encode([
            [
                'statisticType' => 'sum',
                'onStatisticField' => 'Shape_Area',
                'outStatisticFieldName' => 'area_sum',
            ],
        ], JSON_UNESCAPED_SLASHES);

        $params = [
            'f' => 'json',
            'where' => '1=1',
            'geometry' => $geom,
            'geometryType' => 'esriGeometryEnvelope',
            'inSR' => '4326',
            'spatialRel' => 'esriSpatialRelIntersects',
            'outStatistics' => $outStats,
            'groupByFieldsForStatistics' => 'code_2018',
            'returnGeometry' => 'false',
        ];
        $url = self::QUERY_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $resp = ExternalHttpClient::get($url);
        if (!$resp['ok']) {
            $err = $resp['error'] ?? ('http_' . ($resp['status'] ?? 0));
            ExternalDataCache::logProvider('clms', 'urban_atlas_query', 'error', $err);
            $empty['error'] = $err;
            $empty['notes'][] = 'eea_urban_atlas_request_failed';
            return $empty;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j)) {
            ExternalDataCache::logProvider('clms', 'urban_atlas_query', 'error', 'invalid_json');
            $empty['error'] = 'invalid_json';
            return $empty;
        }
        if (!empty($j['error'])) {
            $msg = is_array($j['error']) ? (string)($j['error']['message'] ?? 'arcgis_error') : 'arcgis_error';
            ExternalDataCache::logProvider('clms', 'urban_atlas_query', 'error', mb_substr($msg, 0, 200));
            $empty['error'] = 'arcgis_error';
            $empty['notes'][] = $msg;
            return $empty;
        }
        $features = isset($j['features']) && is_array($j['features']) ? $j['features'] : [];
        $buckets = ['built' => 0.0, 'green_urban' => 0.0, 'pervious_green' => 0.0, 'water' => 0.0, 'other' => 0.0];
        foreach ($features as $f) {
            $attr = is_array($f) && isset($f['attributes']) && is_array($f['attributes']) ? $f['attributes'] : [];
            $code = isset($attr['code_2018']) ? (string)$attr['code_2018'] : '';
            $sum = isset($attr['area_sum']) ? (float)$attr['area_sum'] : 0.0;
            if ($sum <= 0 || $code === '') {
                continue;
            }
            $b = self::classifyUaCode($code);
            $buckets[$b] += $sum;
        }
        $total = array_sum($buckets);
        if ($total <= 0) {
            ExternalDataCache::logProvider('clms', 'urban_atlas_query', 'ok', 'no_intersect');
            $out = $empty;
            $out['ok'] = true;
            $out['notes'][] = 'no_urban_atlas_polygons_in_bbox';
            ExternalDataCache::set('clms', $cacheKey, $out, null, 'ok', null);
            return $out;
        }

        $built = $buckets['built'] / $total;
        $greenUrb = $buckets['green_urban'] / $total;
        $perv = ($buckets['pervious_green'] + $buckets['other']) / $total;
        $water = $buckets['water'] / $total;

        $out = [
            'ok' => true,
            'cached' => false,
            'ua_reference_year' => 2018,
            'ua_built_share' => round(min(1.0, max(0.0, $built)), 3),
            'ua_green_urban_share' => round(min(1.0, max(0.0, $greenUrb)), 3),
            'ua_pervious_green_share' => round(min(1.0, max(0.0, $perv)), 3),
            'ua_water_share' => round(min(1.0, max(0.0, $water)), 3),
            'ua_class_rows' => count($features),
            'error' => null,
            'notes' => ['eea_urban_atlas_2018_area_weighted'],
        ];
        ExternalDataCache::set('clms', $cacheKey, $out, null, 'ok', null);
        ExternalDataCache::logProvider('clms', 'urban_atlas_query', 'ok', 'classes=' . count($features));
        return $out;
    }

    /**
     * @param array<string,mixed> $metrics GreenIntelligence + Copernicus merge utáni tömb
     * @return array<string,mixed>
     */
    public function augmentMetrics(array $metrics, ?array $bbox): array
    {
        if (!$this->isActive() || !$bbox) {
            return [];
        }
        $ua = $this->fetchSharesForBBox($bbox);
        if (!$ua['ok']) {
            $patch = [];
            if (!empty($ua['notes'])) {
                $existing = $metrics['eu_notes'] ?? [];
                $existing = is_array($existing) ? $existing : [];
                $patch['eu_notes'] = array_merge($existing, $ua['notes']);
            }
            return $patch;
        }
        if ($ua['ua_built_share'] === null && (int)$ua['ua_class_rows'] === 0) {
            $patch = [];
            if (!empty($ua['notes'])) {
                $existing = $metrics['eu_notes'] ?? [];
                $existing = is_array($existing) ? $existing : [];
                $patch['eu_notes'] = array_merge($existing, $ua['notes']);
            }
            return $patch;
        }
        $sources = $metrics['data_sources'] ?? [];
        $sources = is_array($sources) ? $sources : [];
        if (!in_array('clms_urban_atlas_2018_eea', $sources, true)) {
            $sources[] = 'clms_urban_atlas_2018_eea';
        }
        $built = (float)($ua['ua_built_share'] ?? 0);
        $sealed = isset($metrics['sealed_surface_pressure']) ? (float)$metrics['sealed_surface_pressure'] : null;
        if ($sealed !== null && $built > 0) {
            $sealed = round(min(1.0, max(0.0, $sealed * 0.65 + $built * 0.35)), 2);
        }
        $patch = [
            'ua_reference_year' => (int)$ua['ua_reference_year'],
            'ua_built_share' => $ua['ua_built_share'],
            'ua_green_urban_share' => $ua['ua_green_urban_share'],
            'ua_pervious_green_share' => $ua['ua_pervious_green_share'],
            'ua_water_share' => $ua['ua_water_share'],
            'ua_class_rows' => (int)$ua['ua_class_rows'],
            'data_sources' => $sources,
        ];
        if ($sealed !== null) {
            $patch['sealed_surface_pressure'] = $sealed;
        }
        $existingNotes = $metrics['eu_notes'] ?? [];
        $existingNotes = is_array($existingNotes) ? $existingNotes : [];
        $patch['eu_notes'] = array_merge($existingNotes, $ua['notes']);
        return $patch;
    }

    private static function classifyUaCode(string $code): string
    {
        $n = (int)preg_replace('/\D/', '', $code);
        if ($n === 14100 || $n === 14200) {
            return 'green_urban';
        }
        if ($n === 50000) {
            return 'water';
        }
        if ($n === 40000) {
            return 'pervious_green';
        }
        if ($n >= 31000 && $n <= 33000) {
            return 'pervious_green';
        }
        if ($n >= 21000 && $n <= 25000) {
            return 'pervious_green';
        }
        if ($n >= 11100 && $n <= 12400) {
            return 'built';
        }
        if ($n >= 13100 && $n <= 13400) {
            return 'built';
        }
        return 'other';
    }

    private function bboxAreaKm2(array $bbox): float
    {
        $avgLat = ((float)$bbox['min_lat'] + (float)$bbox['max_lat']) / 2 * M_PI / 180;
        $dy = abs((float)$bbox['max_lat'] - (float)$bbox['min_lat']) * 111.0;
        $dx = abs((float)$bbox['max_lng'] - (float)$bbox['min_lng']) * 111.0 * max(0.2, cos($avgLat));
        return max(0.0, $dx * $dy);
    }
}
