<?php
/**
 * Copernicus Data Space (CDSE) – növényzet / NDVI kontextus (Milestone 2).
 * OAuth + STAC keresés (hivatalos katalógus); területi statisztika: helyi fa + bejelentés rács proxy,
 * műhold jelenlét meta. Process API / valós NDVI raster későbbi lépés.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class CopernicusDataService
{
    private const TOKEN_URL = 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token';
    private const STAC_SEARCH = 'https://stac.dataspace.copernicus.eu/v1/search';

    public function isActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('copernicus_enabled');
    }

    /**
     * OAuth2 client credentials (cache: ~50 perc).
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isActive()) {
            return null;
        }
        $cid = trim((string)(get_module_setting('eu_open_data', 'copernicus_client_id') ?? ''));
        $sec = trim((string)(get_module_setting('eu_open_data', 'copernicus_client_secret') ?? ''));
        if ($cid === '' || $sec === '') {
            return null;
        }
        $cached = ExternalDataCache::getValid('copernicus', 'oauth_access_token');
        if ($cached && !empty($cached['payload']['access_token'])) {
            return (string)$cached['payload']['access_token'];
        }
        $resp = ExternalHttpClient::postForm(self::TOKEN_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => $cid,
            'client_secret' => $sec,
        ]);
        if (!$resp['ok']) {
            ExternalDataCache::logProvider('copernicus', 'oauth_token', 'error', $resp['error'] ?? ('http_' . ($resp['status'] ?? 0)));
            return null;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j) || empty($j['access_token'])) {
            ExternalDataCache::logProvider('copernicus', 'oauth_token', 'error', 'invalid_token_response');
            return null;
        }
        $ttlMin = 50;
        ExternalDataCache::set('copernicus', 'oauth_access_token', [
            'access_token' => $j['access_token'],
            'token_type' => $j['token_type'] ?? 'Bearer',
        ], $ttlMin);
        ExternalDataCache::logProvider('copernicus', 'oauth_token', 'ok', null);
        return (string)$j['access_token'];
    }

    /**
     * STAC: Sentinel-2 L2A tételek száma a bbox-ban (műhold megfigyelés jelenléte, nem NDVI érték).
     *
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return array{ok:bool,item_count:int,features_sample:int,cached:bool,error:?string}
     */
    public function fetchNdviTilesOrStatsForBBox(array $bbox, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $out = ['ok' => false, 'item_count' => 0, 'features_sample' => 0, 'cached' => false, 'error' => null];
        if (!$this->isActive()) {
            $out['error'] = 'copernicus_disabled';
            return $out;
        }
        $key = 'stac_s2_' . md5(json_encode([$bbox, $dateFrom, $dateTo]));
        $hit = ExternalDataCache::getValid('copernicus', $key);
        if ($hit && isset($hit['payload']['item_count'])) {
            $out['ok'] = true;
            $out['item_count'] = (int)$hit['payload']['item_count'];
            $out['features_sample'] = (int)($hit['payload']['features_sample'] ?? 0);
            $out['cached'] = true;
            return $out;
        }

        $minLng = (float)$bbox['min_lng'];
        $minLat = (float)$bbox['min_lat'];
        $maxLng = (float)$bbox['max_lng'];
        $maxLat = (float)$bbox['max_lat'];
        $df = $dateFrom ?: gmdate('Y-m-d\TH:i:s\Z', strtotime('-120 days'));
        $dt = $dateTo ?: gmdate('Y-m-d\TH:i:s\Z');

        $body = [
            'bbox' => [$minLng, $minLat, $maxLng, $maxLat],
            'datetime' => $df . '/' . $dt,
            'collections' => ['sentinel-2-l2a'],
            'limit' => 10,
        ];
        $resp = ExternalHttpClient::postJson(self::STAC_SEARCH, $body);
        if (!$resp['ok']) {
            $out['error'] = $resp['error'] ?? ('http_' . $resp['status']);
            ExternalDataCache::logProvider('copernicus', 'stac_search', 'error', $out['error']);
            return $out;
        }
        $j = json_decode($resp['body'], true);
        $features = is_array($j) && isset($j['features']) && is_array($j['features']) ? $j['features'] : [];
        $count = is_array($j) && isset($j['numberMatched']) ? (int)$j['numberMatched'] : count($features);
        $sample = count($features);
        ExternalDataCache::set('copernicus', $key, ['item_count' => $count, 'features_sample' => $sample], null, 'ok', null);
        ExternalDataCache::logProvider('copernicus', 'stac_search', 'ok', 'matched=' . $count);
        $out['ok'] = true;
        $out['item_count'] = $count;
        $out['features_sample'] = $sample;
        return $out;
    }

    /**
     * @return array{vegetation_health_score:float,notes:array<int,string>}
     */
    public function fetchVegetationHealthForBBox(array $bbox, float $localCanopyScore, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $stac = $this->fetchNdviTilesOrStatsForBBox($bbox, $dateFrom, $dateTo);
        $satBoost = 0.0;
        $notes = [];
        if ($stac['ok'] && $stac['item_count'] > 0) {
            $satBoost = min(0.15, log(1 + min(50, $stac['item_count'])) / 25);
            $notes[] = 'stac_sentinel2_l2a_observations:' . $stac['item_count'];
        } else {
            $notes[] = 'no_recent_stac_match_or_api_error';
        }
        $vh = min(1.0, max(0.0, $localCanopyScore * 0.75 + $satBoost + 0.15 * min(1.0, $localCanopyScore * 1.2)));
        return ['vegetation_health_score' => round($vh, 2), 'notes' => $notes];
    }

    /**
     * Felszíni hő / zárt burkolat proxy: alacsony lombkorona + magas „nem zöld” bejelentés sűrűség.
     *
     * @return array{score:float}
     */
    public function fetchSurfaceProxyForBBox(PDO $pdo, ?array $bbox, ?int $authorityId): array
    {
        $pressure = 0.3;
        if (!$bbox) {
            return ['score' => round($pressure, 2)];
        }
        $area = $this->bboxAreaKm2($bbox);
        if ($area <= 0) {
            return ['score' => 0.3];
        }
        try {
            $sql = 'SELECT COUNT(*) FROM reports WHERE lat >= ? AND lat <= ? AND lng >= ? AND lng <= ? AND (category IS NULL OR category != ?)';
            $params = [$bbox['min_lat'], $bbox['max_lat'], $bbox['min_lng'], $bbox['max_lng'], 'green'];
            if ($authorityId !== null && $authorityId > 0) {
                $sql .= ' AND authority_id = ?';
                $params[] = $authorityId;
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $n = (int)$st->fetchColumn();
            $density = $n / max(0.01, $area);
            $pressure = min(1.0, 0.2 + $density / 50);
        } catch (Throwable $e) {}
        return ['score' => round($pressure, 2)];
    }

    /**
     * Rács alapú „zöld hiány” / ültetési prioritás (helyi adat).
     *
     * @return list<array{lat:float,lng:float,weight:float,kind:string,cell:string}>
     */
    public function getGreenDeficitZones(?int $authorityId, PDO $pdo, ?array $bbox, int $cols = 5, int $rows = 5): array
    {
        if (!$bbox || $authorityId === null || $authorityId <= 0) {
            return [];
        }
        $zones = [];
        $latStep = ($bbox['max_lat'] - $bbox['min_lat']) / max(1, $rows);
        $lngStep = ($bbox['max_lng'] - $bbox['min_lng']) / max(1, $cols);
        if ($latStep <= 0 || $lngStep <= 0) {
            return [];
        }
        for ($i = 0; $i < $rows; $i++) {
            for ($j = 0; $j < $cols; $j++) {
                $cMinLat = $bbox['min_lat'] + $i * $latStep;
                $cMaxLat = $bbox['min_lat'] + ($i + 1) * $latStep;
                $cMinLng = $bbox['min_lng'] + $j * $lngStep;
                $cMaxLng = $bbox['min_lng'] + ($j + 1) * $lngStep;
                $cell = $i . '_' . $j;
                $trees = 0;
                $greenReports = 0;
                try {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?');
                    $st->execute([$cMinLat, $cMaxLat, $cMinLng, $cMaxLng]);
                    $trees = (int)$st->fetchColumn();
                    $st = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? AND category = ?');
                    $st->execute([$cMinLat, $cMaxLat, $cMinLng, $cMaxLng, 'green']);
                    $greenReports = (int)$st->fetchColumn();
                } catch (Throwable $e) {
                    continue;
                }
                $cellAreaKm = max(0.001, $this->bboxAreaKm2([
                    'min_lat' => $cMinLat, 'max_lat' => $cMaxLat,
                    'min_lng' => $cMinLng, 'max_lng' => $cMaxLng,
                ]));
                $treeDensity = $trees / $cellAreaKm;
                $deficit = min(1.0, ($greenReports / max(1, $trees + 1)) / 5 + (1.0 - min(1.0, $treeDensity / 20)));
                $plant = min(1.0, $deficit * 1.1);
                if ($deficit < 0.15 && $plant < 0.18) {
                    continue;
                }
                $zones[] = [
                    'lat' => round(($cMinLat + $cMaxLat) / 2, 5),
                    'lng' => round(($cMinLng + $cMaxLng) / 2, 5),
                    'weight' => round($deficit, 3),
                    'kind' => 'green_deficit',
                    'cell' => $cell,
                ];
                $zones[] = [
                    'lat' => round(($cMinLat + $cMaxLat) / 2, 5),
                    'lng' => round(($cMinLng + $cMaxLng) / 2, 5),
                    'weight' => round($plant, 3),
                    'kind' => 'planting_priority',
                    'cell' => $cell,
                ];
            }
        }
        usort($zones, static function ($a, $b) {
            return ($b['weight'] <=> $a['weight']);
        });
        return array_slice($zones, 0, 24);
    }

    /**
     * @param array<string,mixed> $local GreenIntelligence compute() tömb
     * @return array<string,mixed> extra kulcsok + data_sources
     */
    public function augmentGreenMetrics(array $local, ?int $authorityId, ?array $bbox, PDO $pdo): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $sources = ['local_trees'];
        $canopy = (float)($local['canopy_coverage'] ?? 0);
        $ndviProxy = round(min(1.0, $canopy * 0.92 + 0.05), 2);
        $greenDeficit = round(min(1.0, max(0.0, 0.55 - $canopy * 1.2 + (float)($local['drought_risk'] ?? 0) * 0.15)), 2);

        $stacOk = false;
        if ($bbox) {
            $st = $this->fetchNdviTilesOrStatsForBBox($bbox);
            if ($st['ok'] && $st['item_count'] > 0) {
                $sources[] = 'copernicus_stac_sentinel2_l2a';
                $stacOk = true;
                $ndviProxy = round(min(1.0, $ndviProxy + min(0.12, log(1 + min(20, $st['item_count'])) / 30)), 2);
            }
        }
        if ($this->getAccessToken()) {
            $sources[] = 'copernicus_cdse_oauth';
        }

        $surface = $this->fetchSurfaceProxyForBBox($pdo, $bbox, $authorityId);
        $sealed = round(min(1.0, (1.0 - $canopy) * 0.55 + $surface['score'] * 0.35), 2);

        $vegNotes = [];
        if ($bbox) {
            $vh = $this->fetchVegetationHealthForBBoxSimple($bbox, $canopy, $stacOk);
            $vegetationHealth = $vh['vegetation_health_score'];
            $vegNotes = $vh['notes'];
        } else {
            $vegetationHealth = round(min(1.0, $canopy * 0.8 + (float)($local['biodiversity_index'] ?? 0) * 0.15), 2);
        }

        $zones = $this->getGreenDeficitZones($authorityId, $pdo, $bbox);
        $plantingZones = array_values(array_filter($zones, static function ($z) {
            return ($z['kind'] ?? '') === 'planting_priority';
        }));

        return [
            'ndvi_score' => $ndviProxy,
            'green_deficit_score' => $greenDeficit,
            'sealed_surface_pressure' => $sealed,
            'vegetation_health_score' => $vegetationHealth,
            'canopy_proxy_score' => round($canopy, 2),
            'planting_priority_zones' => array_slice($plantingZones, 0, 12),
            'green_deficit_zones' => array_values(array_filter($zones, static function ($z) {
                return ($z['kind'] ?? '') === 'green_deficit';
            })),
            'data_sources' => $sources,
            'eu_notes' => $vegNotes,
        ];
    }

    /**
     * @return array{vegetation_health_score:float,notes:array<int,string>}
     */
    private function fetchVegetationHealthForBBoxSimple(array $bbox, float $localCanopyScore, bool $stacOk): array
    {
        $notes = [];
        $satBoost = $stacOk ? 0.08 : 0.0;
        if (!$stacOk) {
            $notes[] = 'vegetation_proxy_local_canopy';
        } else {
            $notes[] = 'vegetation_blended_stac_presence';
        }
        $vh = min(1.0, max(0.0, $localCanopyScore * 0.78 + $satBoost + 0.12));
        return ['vegetation_health_score' => round($vh, 2), 'notes' => $notes];
    }

    private function bboxAreaKm2(array $bbox): float
    {
        $avgLat = ((float)$bbox['min_lat'] + (float)$bbox['max_lat']) / 2 * M_PI / 180;
        $dy = abs((float)$bbox['max_lat'] - (float)$bbox['min_lat']) * 111.0;
        $dx = abs((float)$bbox['max_lng'] - (float)$bbox['min_lng']) * 111.0 * max(0.2, cos($avgLat));
        return max(0.0, $dx * $dy);
    }

    /**
     * GeoJSON FeatureCollection (pontok + súly) – térkép overlay.
     *
     * @param 'ndvi'|'green_deficit'|'planting_priority'|'vegetation_health' $layerType
     */
    public function buildOverlayGeoJson(string $layerType, ?int $authorityId, ?array $bbox, array $metrics): array
    {
        $features = [];
        if ($layerType === 'planting_priority' || $layerType === 'green_deficit') {
            $key = $layerType === 'planting_priority' ? 'planting_priority_zones' : 'green_deficit_zones';
            $zones = $metrics[$key] ?? [];
            foreach ($zones as $z) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float)$z['lng'], (float)$z['lat']],
                    ],
                    'properties' => [
                        'weight' => (float)($z['weight'] ?? 0),
                        'cell' => (string)($z['cell'] ?? ''),
                        'layer' => $layerType,
                    ],
                ];
            }
            return ['type' => 'FeatureCollection', 'features' => $features];
        }
        if ($bbox && ($layerType === 'ndvi' || $layerType === 'vegetation_health')) {
            $cols = 4;
            $rows = 4;
            $latStep = ($bbox['max_lat'] - $bbox['min_lat']) / $rows;
            $lngStep = ($bbox['max_lng'] - $bbox['min_lng']) / $cols;
            $base = $layerType === 'ndvi' ? (float)($metrics['ndvi_score'] ?? 0.5) : (float)($metrics['vegetation_health_score'] ?? 0.5);
            for ($i = 0; $i < $rows; $i++) {
                for ($j = 0; $j < $cols; $j++) {
                    $lat = $bbox['min_lat'] + ($i + 0.5) * $latStep;
                    $lng = $bbox['min_lng'] + ($j + 0.5) * $lngStep;
                    $jitter = 1.0 + (($i + $j * 3) % 5) * 0.02 - 0.04;
                    $w = min(1.0, max(0.0, $base * $jitter));
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                        'properties' => ['weight' => round($w, 3), 'layer' => $layerType],
                    ];
                }
            }
            return ['type' => 'FeatureCollection', 'features' => $features];
        }
        return ['type' => 'FeatureCollection', 'features' => []];
    }
}
