<?php
/**
 * Copernicus Data Space (CDSE) + Sentinel Hub:
 * - OAuth2 client credentials
 * - STAC katalógus (jelenlét)
 * - Statistical API → valódi Sentinel-2 L2A NDVI mean (Processing Units)
 * Overlay / AI kontextus erre épül; helyi fa-proxy csak fallback, ha nincs token vagy API hiba.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class CopernicusDataService
{
    private const TOKEN_URL = 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token';
    private const STAC_SEARCH = 'https://stac.dataspace.copernicus.eu/v1/search';
    /** CDSE Sentinel Hub Statistical API (Processing Units; külön service a Usage-ban) */
    private const STATS_URL = 'https://sh.dataspace.copernicus.eu/statistics/v1';
    /** CDSE Sentinel Hub Process API – a Usage „Processing API” sorába ez megy */
    private const PROCESS_URL = 'https://sh.dataspace.copernicus.eu/api/v1/process';

    /** NDVI evalscript – Statistical API (dataMask kötelező; víz/felhő kizárás) */
    private const NDVI_EVALSCRIPT = <<<'JS'
//VERSION=3
function setup() {
  return {
    input: [{
      bands: ["B04", "B08", "SCL", "dataMask"]
    }],
    output: [
      { id: "data", bands: 1 },
      { id: "dataMask", bands: 1 }
    ]
  };
}
function evaluatePixel(samples) {
  let ndvi = (samples.B08 - samples.B04) / (samples.B08 + samples.B04);
  var validNDVIMask = 1;
  if (samples.B08 + samples.B04 == 0) {
    validNDVIMask = 0;
  }
  var clearMask = 1;
  // SCL: 6=water, 8/9/10=cloud, 11=snow
  if (samples.SCL == 6 || samples.SCL == 8 || samples.SCL == 9 || samples.SCL == 10 || samples.SCL == 11) {
    clearMask = 0;
  }
  return {
    data: [ndvi],
    dataMask: [samples.dataMask * validNDVIMask * clearMask]
  };
}
JS;

    /** Process API: egyszerű NDVI szürkeárnyalatos PNG (Usage → Processing API) */
    private const NDVI_PROCESS_EVALSCRIPT = <<<'JS'
//VERSION=3
function setup() {
  return {
    input: ["B04", "B08", "dataMask"],
    output: { bands: 4 }
  };
}
function evaluatePixel(s) {
  let ndvi = (s.B08 - s.B04) / (s.B08 + s.B04);
  let v = Math.max(0, Math.min(1, (ndvi + 0.2) / 1.0));
  return [v, v, v, s.dataMask];
}
JS;

    public function isActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('copernicus_enabled');
    }

    public function hasCredentials(): bool
    {
        $cid = trim((string)(get_module_setting('eu_open_data', 'copernicus_client_id') ?? ''));
        $sec = trim((string)(get_module_setting('eu_open_data', 'copernicus_client_secret') ?? ''));
        return $cid !== '' && $sec !== '';
    }

    /**
     * OAuth2 client credentials (cache: ~45 perc).
     * Sentinel Hub OAuth kliens kell a CDSE dashboardból (Processing / Statistical API).
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isActive() || !$this->hasCredentials()) {
            return null;
        }
        $cid = trim((string)(get_module_setting('eu_open_data', 'copernicus_client_id') ?? ''));
        $sec = trim((string)(get_module_setting('eu_open_data', 'copernicus_client_secret') ?? ''));
        if (ExternalDataCache::isInErrorCooldown('copernicus', 'oauth_fail_' . md5($cid))) {
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
            $err = $resp['error'] ?? ('http_' . ($resp['status'] ?? 0));
            $snip = substr(preg_replace('/\s+/', ' ', (string)($resp['body'] ?? '')), 0, 120);
            ExternalDataCache::logProvider('copernicus', 'oauth_token', 'error', $err . ($snip !== '' ? (';' . $snip) : ''));
            if ((int)($resp['status'] ?? 0) === 401 || strpos((string)$err, '401') !== false) {
                ExternalDataCache::setErrorCooldown('copernicus', 'oauth_fail_' . md5($cid), 15, 'http_401');
            }
            return null;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j) || empty($j['access_token'])) {
            ExternalDataCache::logProvider('copernicus', 'oauth_token', 'error', 'invalid_token_response');
            return null;
        }
        ExternalDataCache::set('copernicus', 'oauth_access_token', [
            'access_token' => $j['access_token'],
            'token_type' => $j['token_type'] ?? 'Bearer',
        ], 45);
        ExternalDataCache::logProvider('copernicus', 'oauth_token', 'ok', null);
        return (string)$j['access_token'];
    }

    /**
     * STAC: Sentinel-2 L2A tételek száma (katalógus, nem NDVI érték).
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

        $df = $dateFrom ?: gmdate('Y-m-d\TH:i:s\Z', strtotime('-120 days'));
        $dt = $dateTo ?: gmdate('Y-m-d\TH:i:s\Z');
        $body = [
            'bbox' => [(float)$bbox['min_lng'], (float)$bbox['min_lat'], (float)$bbox['max_lng'], (float)$bbox['max_lat']],
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
     * Valódi Sentinel-2 NDVI mean a bbox-ra (Statistical API → Processing Units).
     *
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return array{ok:bool,mean:?float,min:?float,max:?float,sample_count:int,cached:bool,error:?string,source:string}
     */
    public function fetchNdviMeanForBBox(array $bbox, int $lookbackDays = 90): array
    {
        $out = [
            'ok' => false,
            'mean' => null,
            'min' => null,
            'max' => null,
            'sample_count' => 0,
            'cached' => false,
            'error' => null,
            'source' => 'sentinelhub_statistics',
        ];
        if (!$this->isActive()) {
            $out['error'] = 'copernicus_disabled';
            return $out;
        }
        $token = $this->getAccessToken();
        if ($token === null) {
            $out['error'] = $this->hasCredentials() ? 'oauth_unavailable' : 'oauth_credentials_missing';
            return $out;
        }

        $lookbackDays = max(14, min(180, $lookbackDays));
        $cacheKey = 'ndvi_mean_' . md5(json_encode([$bbox, $lookbackDays]));
        $hit = ExternalDataCache::getValid('copernicus', $cacheKey);
        if ($hit && isset($hit['payload']['mean'])) {
            // Ha a Statistical már cache-ből jön, Process API ping-et akkor is lefuttatjuk (Usage Processing sor).
            $fromCached = (string)($hit['payload']['period_from'] ?? gmdate('Y-m-d\T00:00:00\Z', strtotime('-90 days')));
            $toCached = (string)($hit['payload']['period_to'] ?? gmdate('Y-m-d\T23:59:59\Z'));
            $this->pingProcessApiNdvi($bbox, $token, $fromCached, $toCached);
            return array_merge($out, $hit['payload'], ['ok' => true, 'cached' => true]);
        }

        $from = gmdate('Y-m-d\T00:00:00\Z', strtotime('-' . $lookbackDays . ' days'));
        $to = gmdate('Y-m-d\T23:59:59\Z');
        $width = 64;
        $height = 64;
        $request = [
            'input' => [
                'bounds' => [
                    'bbox' => [
                        (float)$bbox['min_lng'],
                        (float)$bbox['min_lat'],
                        (float)$bbox['max_lng'],
                        (float)$bbox['max_lat'],
                    ],
                    'properties' => [
                        'crs' => 'http://www.opengis.net/def/crs/EPSG/0/4326',
                    ],
                ],
                'data' => [[
                    'type' => 'sentinel-2-l2a',
                    'dataFilter' => [
                        'mosaickingOrder' => 'leastCC',
                        'maxCloudCoverage' => 40,
                    ],
                ]],
            ],
            'aggregation' => [
                'timeRange' => ['from' => $from, 'to' => $to],
                'aggregationInterval' => ['of' => 'P' . max(30, (int)round($lookbackDays / 2)) . 'D'],
                'evalscript' => self::NDVI_EVALSCRIPT,
                'width' => $width,
                'height' => $height,
            ],
        ];

        $resp = ExternalHttpClient::postJson(
            self::STATS_URL,
            $request,
            max(45, ExternalHttpClient::defaultTimeoutSeconds()),
            ['Authorization: Bearer ' . $token]
        );
        if (!$resp['ok']) {
            $out['error'] = $resp['error'] ?? ('http_' . $resp['status']);
            $snip = substr(preg_replace('/\s+/', ' ', (string)($resp['body'] ?? '')), 0, 160);
            ExternalDataCache::logProvider('copernicus', 'statistics_ndvi', 'error', $out['error'] . ($snip !== '' ? (';' . $snip) : ''));
            return $out;
        }

        $parsed = $this->parseNdviStatsResponse((string)$resp['body']);
        if ($parsed['mean'] === null) {
            $out['error'] = 'no_ndvi_stats_in_response';
            ExternalDataCache::logProvider('copernicus', 'statistics_ndvi', 'error', $out['error']);
            return $out;
        }

        $pu = ExternalHttpClient::processingUnitsSpent($resp);
        $payload = [
            'mean' => $parsed['mean'],
            'min' => $parsed['min'],
            'max' => $parsed['max'],
            'sample_count' => $parsed['sample_count'],
            'source' => 'sentinelhub_statistics',
            'period_from' => $from,
            'period_to' => $to,
            'pu_spent' => $pu,
        ];
        ExternalDataCache::set('copernicus', $cacheKey, $payload, 360, 'ok', null);
        $puMsg = $pu !== null ? (';pu=' . $pu) : ';pu=n/a';
        ExternalDataCache::logProvider(
            'copernicus',
            'statistics_ndvi',
            'ok',
            'mean=' . $parsed['mean'] . ';samples=' . $parsed['sample_count'] . $puMsg
        );

        // Process API: megjelenik a SH Usage „Processing API” sorában (Statistical külön service).
        $this->pingProcessApiNdvi($bbox, $token, $from, $to);

        return array_merge($out, $payload, ['ok' => true, 'cached' => false]);
    }

    /**
     * Kis Process API NDVI PNG – Usage dashboard Processing API + PU.
     * Cache-elve, hogy ne spameljen; a válaszbody-t nem tároljuk.
     */
    private function pingProcessApiNdvi(array $bbox, string $token, string $from, string $to): void
    {
        $key = 'process_ndvi_ping_' . md5(json_encode($bbox));
        if (ExternalDataCache::getValid('copernicus', $key)) {
            return;
        }
        $request = [
            'input' => [
                'bounds' => [
                    'bbox' => [
                        (float)$bbox['min_lng'],
                        (float)$bbox['min_lat'],
                        (float)$bbox['max_lng'],
                        (float)$bbox['max_lat'],
                    ],
                    'properties' => [
                        'crs' => 'http://www.opengis.net/def/crs/EPSG/0/4326',
                    ],
                ],
                'data' => [[
                    'type' => 'sentinel-2-l2a',
                    'dataFilter' => [
                        'timeRange' => ['from' => $from, 'to' => $to],
                        'mosaickingOrder' => 'leastCC',
                        'maxCloudCoverage' => 40,
                    ],
                ]],
            ],
            'output' => [
                'width' => 64,
                'height' => 64,
                'responses' => [[
                    'identifier' => 'default',
                    'format' => ['type' => 'image/png'],
                ]],
            ],
            'evalscript' => self::NDVI_PROCESS_EVALSCRIPT,
        ];
        $resp = ExternalHttpClient::postJson(
            self::PROCESS_URL,
            $request,
            max(45, ExternalHttpClient::defaultTimeoutSeconds()),
            [
                'Authorization: Bearer ' . $token,
                'Accept: image/png',
            ]
        );
        $pu = ExternalHttpClient::processingUnitsSpent($resp);
        if (!$resp['ok']) {
            $snip = substr(preg_replace('/\s+/', ' ', (string)($resp['body'] ?? '')), 0, 120);
            ExternalDataCache::logProvider(
                'copernicus',
                'process_ndvi',
                'error',
                ($resp['error'] ?? 'fail') . ($snip !== '' ? (';' . $snip) : '')
            );
            // Rövid cooldown, ne ismételje folyamatosan hibásan
            ExternalDataCache::set('copernicus', $key, ['ok' => false], 30, 'error', $resp['error'] ?? 'fail');
            return;
        }
        $bytes = strlen((string)$resp['body']);
        ExternalDataCache::set('copernicus', $key, ['ok' => true, 'bytes' => $bytes, 'pu_spent' => $pu], 360, 'ok', null);
        ExternalDataCache::logProvider(
            'copernicus',
            'process_ndvi',
            'ok',
            'bytes=' . $bytes . ($pu !== null ? (';pu=' . $pu) : ';pu=n/a')
        );
    }

    /**
     * Rács: cellánként Statistical NDVI → zöldhiány / ültetési prioritás zónák.
     * Alapból 2×2 (max 4 API hívás); eredmény 6 órára cache-elve.
     *
     * @return list<array{lat:float,lng:float,weight:float,kind:string,cell:string,ndvi:?float}>
     */
    public function fetchNdviGridZones(array $bbox, int $cols = 2, int $rows = 2): array
    {
        $cols = max(2, min(3, $cols));
        $rows = max(2, min(3, $rows));
        $gridKey = 'ndvi_grid_zones_' . md5(json_encode([$bbox, $cols, $rows]));
        $hit = ExternalDataCache::getValid('copernicus', $gridKey);
        if ($hit && isset($hit['payload']['zones']) && is_array($hit['payload']['zones'])) {
            return $hit['payload']['zones'];
        }
        $zones = [];
        $latStep = ((float)$bbox['max_lat'] - (float)$bbox['min_lat']) / $rows;
        $lngStep = ((float)$bbox['max_lng'] - (float)$bbox['min_lng']) / $cols;
        if ($latStep <= 0 || $lngStep <= 0) {
            return [];
        }
        $calls = 0;
        for ($i = 0; $i < $rows; $i++) {
            for ($j = 0; $j < $cols; $j++) {
                if ($calls >= 6) {
                    break 2;
                }
                $cellBbox = [
                    'min_lat' => (float)$bbox['min_lat'] + $i * $latStep,
                    'max_lat' => (float)$bbox['min_lat'] + ($i + 1) * $latStep,
                    'min_lng' => (float)$bbox['min_lng'] + $j * $lngStep,
                    'max_lng' => (float)$bbox['min_lng'] + ($j + 1) * $lngStep,
                ];
                $stat = $this->fetchNdviMeanForBBox($cellBbox, 90);
                $calls++;
                if (!$stat['ok'] || $stat['mean'] === null) {
                    continue;
                }
                $ndvi = (float)$stat['mean'];
                // NDVI tipikusan -1…1; zöldhiány = alacsony NDVI
                $deficit = max(0.0, min(1.0, (0.55 - $ndvi) / 0.85));
                $plant = max(0.0, min(1.0, $deficit * 1.15));
                $lat = round(($cellBbox['min_lat'] + $cellBbox['max_lat']) / 2, 5);
                $lng = round(($cellBbox['min_lng'] + $cellBbox['max_lng']) / 2, 5);
                $cell = $i . '_' . $j;
                $zones[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'weight' => round($deficit, 3),
                    'kind' => 'green_deficit',
                    'cell' => $cell,
                    'ndvi' => round($ndvi, 3),
                ];
                $zones[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'weight' => round($plant, 3),
                    'kind' => 'planting_priority',
                    'cell' => $cell,
                    'ndvi' => round($ndvi, 3),
                ];
            }
        }
        usort($zones, static function ($a, $b) {
            return ($b['weight'] <=> $a['weight']);
        });
        $zones = array_slice($zones, 0, 24);
        if ($zones !== []) {
            ExternalDataCache::set('copernicus', $gridKey, ['zones' => $zones], 360, 'ok', null);
        }
        return $zones;
    }

    /**
     * @return array{mean:?float,min:?float,max:?float,sample_count:int}
     */
    private function parseNdviStatsResponse(string $body): array
    {
        $empty = ['mean' => null, 'min' => null, 'max' => null, 'sample_count' => 0];
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return $empty;
        }
        $data = $j['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return $empty;
        }
        $means = [];
        $mins = [];
        $maxs = [];
        $samples = 0;
        foreach ($data as $interval) {
            if (!is_array($interval)) {
                continue;
            }
            $outputs = $interval['outputs'] ?? [];
            if (!is_array($outputs)) {
                continue;
            }
            foreach ($outputs as $outName => $outVal) {
                if (!is_array($outVal)) {
                    continue;
                }
                if (is_string($outName) && strtolower($outName) === 'datamask') {
                    continue;
                }
                $bands = $outVal['bands'] ?? [];
                if (!is_array($bands)) {
                    continue;
                }
                foreach ($bands as $band) {
                    if (!is_array($band)) {
                        continue;
                    }
                    $stats = $band['stats'] ?? null;
                    if (!is_array($stats) || !isset($stats['mean'])) {
                        continue;
                    }
                    // Skip intervals with no valid samples
                    $sc = (int)($stats['sampleCount'] ?? 0);
                    $nd = (int)($stats['noDataCount'] ?? 0);
                    if ($sc > 0 && $nd >= $sc) {
                        continue;
                    }
                    $means[] = (float)$stats['mean'];
                    if (isset($stats['min'])) {
                        $mins[] = (float)$stats['min'];
                    }
                    if (isset($stats['max'])) {
                        $maxs[] = (float)$stats['max'];
                    }
                    $samples += max(0, $sc - $nd);
                }
            }
        }
        if ($means === []) {
            return $empty;
        }
        return [
            'mean' => round(array_sum($means) / count($means), 4),
            'min' => $mins !== [] ? round(min($mins), 4) : null,
            'max' => $maxs !== [] ? round(max($maxs), 4) : null,
            'sample_count' => $samples,
        ];
    }

    /**
     * Felszíni nyomás proxy (helyi bejelentés) – kiegészítő, nem helyettesíti az NDVI-t.
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
        } catch (Throwable $e) {
        }
        return ['score' => round($pressure, 2)];
    }

    /**
     * Helyi rács fallback (csak ha nincs sat NDVI).
     *
     * @return list<array{lat:float,lng:float,weight:float,kind:string,cell:string,ndvi:?float}>
     */
    public function getGreenDeficitZonesLocal(?int $authorityId, PDO $pdo, ?array $bbox, int $cols = 5, int $rows = 5): array
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
                    $treeSql = 'SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?';
                    $treeParams = [$cMinLat, $cMaxLat, $cMinLng, $cMaxLng];
                    if ($authorityId > 0 && function_exists('db_table_has_column') && db_table_has_column($pdo, 'trees', 'authority_id')) {
                        $treeSql .= ' AND authority_id = ?';
                        $treeParams[] = $authorityId;
                    }
                    $st = $pdo->prepare($treeSql);
                    $st->execute($treeParams);
                    $trees = (int)$st->fetchColumn();
                    $repSql = 'SELECT COUNT(*) FROM reports WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? AND category = ?';
                    $repParams = [$cMinLat, $cMaxLat, $cMinLng, $cMaxLng, 'green'];
                    if ($authorityId > 0) {
                        $repSql .= ' AND authority_id = ?';
                        $repParams[] = $authorityId;
                    }
                    $st = $pdo->prepare($repSql);
                    $st->execute($repParams);
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
                    'ndvi' => null,
                ];
                $zones[] = [
                    'lat' => round(($cMinLat + $cMaxLat) / 2, 5),
                    'lng' => round(($cMinLng + $cMaxLng) / 2, 5),
                    'weight' => round($plant, 3),
                    'kind' => 'planting_priority',
                    'cell' => $cell,
                    'ndvi' => null,
                ];
            }
        }
        usort($zones, static function ($a, $b) {
            return ($b['weight'] <=> $a['weight']);
        });
        return array_slice($zones, 0, 24);
    }

    /** @deprecated use getGreenDeficitZonesLocal or fetchNdviGridZones */
    public function getGreenDeficitZones(?int $authorityId, PDO $pdo, ?array $bbox, int $cols = 5, int $rows = 5): array
    {
        return $this->getGreenDeficitZonesLocal($authorityId, $pdo, $bbox, $cols, $rows);
    }

    /**
     * @param array<string,mixed> $local GreenIntelligence compute() tömb
     * @return array<string,mixed>
     */
    public function augmentGreenMetrics(array $local, ?int $authorityId, ?array $bbox, PDO $pdo): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $notes = [];
        $sources = ['local_trees'];
        $canopy = (float)($local['canopy_coverage'] ?? 0);
        $satNdvi = null;
        $satOk = false;

        if ($bbox) {
            $st = $this->fetchNdviTilesOrStatsForBBox($bbox);
            if ($st['ok'] && $st['item_count'] > 0) {
                $sources[] = 'copernicus_stac_sentinel2_l2a';
                $notes[] = 'stac_observations:' . $st['item_count'];
            }
            $ndviStat = $this->fetchNdviMeanForBBox($bbox, 90);
            if ($ndviStat['ok'] && $ndviStat['mean'] !== null) {
                $satNdvi = max(-1.0, min(1.0, (float)$ndviStat['mean']));
                $satOk = true;
                $sources[] = 'sentinelhub_statistics_ndvi';
                $notes[] = 'ndvi_mean_sat:' . round($satNdvi, 3);
                if (!empty($ndviStat['cached'])) {
                    $notes[] = 'ndvi_cached_no_new_pu';
                } else {
                    $notes[] = 'ndvi_fresh_api_call';
                }
                if (isset($ndviStat['pu_spent']) && $ndviStat['pu_spent'] !== null) {
                    $notes[] = 'statistics_pu_spent:' . $ndviStat['pu_spent'];
                }
            } else {
                $notes[] = 'ndvi_sat_unavailable:' . (string)($ndviStat['error'] ?? 'unknown');
            }
        } else {
            $notes[] = 'no_authority_bbox';
        }

        if ($this->getAccessToken()) {
            $sources[] = 'copernicus_cdse_oauth';
        }
        // Process API ping cache → Usage „Processing API”
        if ($bbox) {
            $pingKey = 'process_ndvi_ping_' . md5(json_encode($bbox));
            $ping = ExternalDataCache::getValid('copernicus', $pingKey);
            if ($ping && !empty($ping['payload']['ok'])) {
                $sources[] = 'sentinelhub_process_api';
                if (isset($ping['payload']['pu_spent']) && $ping['payload']['pu_spent'] !== null) {
                    $notes[] = 'process_pu_spent:' . $ping['payload']['pu_spent'];
                } else {
                    $notes[] = 'process_ndvi_ok';
                }
            }
        }

        // NDVI score 0–1: sat raw NDVI mapped from ~[-0.2, 0.8] → [0,1]
        if ($satOk && $satNdvi !== null) {
            $ndviScore = round(max(0.0, min(1.0, ($satNdvi + 0.2) / 1.0)), 2);
            $greenDeficit = round(max(0.0, min(1.0, (0.55 - $satNdvi) / 0.85)), 2);
            $vegetationHealth = $ndviScore;
            $notes[] = 'metrics_from_sentinel2_ndvi';
        } else {
            $ndviScore = round(min(1.0, $canopy * 0.92 + 0.05), 2);
            $greenDeficit = round(min(1.0, max(0.0, 0.55 - $canopy * 1.2 + (float)($local['drought_risk'] ?? 0) * 0.15)), 2);
            $vegetationHealth = round(min(1.0, $canopy * 0.8 + (float)($local['biodiversity_index'] ?? 0) * 0.15), 2);
            $notes[] = 'metrics_fallback_local_canopy_proxy';
        }

        $surface = $this->fetchSurfaceProxyForBBox($pdo, $bbox, $authorityId);
        $sealed = round(min(1.0, (1.0 - ($satOk ? $ndviScore : $canopy)) * 0.55 + $surface['score'] * 0.35), 2);

        // Metrikákhoz helyi rács (gyors); sat NDVI rács overlay-n / igény szerint (PU + latency).
        $zones = $this->getGreenDeficitZonesLocal($authorityId, $pdo, $bbox);
        $zoneSource = 'local_grid';
        $notes[] = 'overlay_zones_local_for_metrics';
        if ($satOk) {
            $notes[] = 'sat_ndvi_grid_available_via_overlay';
        }

        $plantingZones = array_values(array_filter($zones, static function ($z) {
            return ($z['kind'] ?? '') === 'planting_priority';
        }));
        $deficitZones = array_values(array_filter($zones, static function ($z) {
            return ($z['kind'] ?? '') === 'green_deficit';
        }));

        return [
            'ndvi_score' => $ndviScore,
            'ndvi_raw' => $satNdvi,
            'green_deficit_score' => $greenDeficit,
            'sealed_surface_pressure' => $sealed,
            'vegetation_health_score' => $vegetationHealth,
            'canopy_proxy_score' => round($canopy, 2),
            'planting_priority_zones' => array_slice($plantingZones, 0, 12),
            'green_deficit_zones' => array_slice($deficitZones, 0, 12),
            'data_sources' => $sources,
            'satellite_ndvi_ok' => $satOk,
            'zone_source' => $zoneSource,
            'eu_notes' => $notes,
        ];
    }

    private function bboxAreaKm2(array $bbox): float
    {
        $avgLat = ((float)$bbox['min_lat'] + (float)$bbox['max_lat']) / 2 * M_PI / 180;
        $dy = abs((float)$bbox['max_lat'] - (float)$bbox['min_lat']) * 111.0;
        $dx = abs((float)$bbox['max_lng'] - (float)$bbox['min_lng']) * 111.0 * max(0.2, cos($avgLat));
        return max(0.0, $dx * $dy);
    }

    /**
     * @param 'ndvi'|'green_deficit'|'planting_priority'|'vegetation_health' $layerType
     */
    public function buildOverlayGeoJson(string $layerType, ?int $authorityId, ?array $bbox, array $metrics): array
    {
        $features = [];
        $satZones = [];
        if (!empty($metrics['satellite_ndvi_ok']) && $bbox) {
            $satZones = $this->fetchNdviGridZones($bbox, 2, 2);
        }
        if ($layerType === 'planting_priority' || $layerType === 'green_deficit') {
            $key = $layerType === 'planting_priority' ? 'planting_priority' : 'green_deficit';
            $zones = [];
            $zoneSource = (string)($metrics['zone_source'] ?? 'local_grid');
            if ($satZones !== []) {
                $zones = array_values(array_filter($satZones, static function ($z) use ($key) {
                    return ($z['kind'] ?? '') === $key;
                }));
                $zoneSource = 'sentinelhub_ndvi_grid';
            }
            if ($zones === []) {
                $metricsKey = $layerType === 'planting_priority' ? 'planting_priority_zones' : 'green_deficit_zones';
                $zones = $metrics[$metricsKey] ?? [];
            }
            foreach ($zones as $z) {
                $props = [
                    'weight' => (float)($z['weight'] ?? 0),
                    'cell' => (string)($z['cell'] ?? ''),
                    'layer' => $layerType,
                    'source' => $zoneSource,
                ];
                if (isset($z['ndvi']) && $z['ndvi'] !== null) {
                    $props['ndvi'] = (float)$z['ndvi'];
                }
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float)$z['lng'], (float)$z['lat']],
                    ],
                    'properties' => $props,
                ];
            }
            return ['type' => 'FeatureCollection', 'features' => $features];
        }
        if ($bbox && ($layerType === 'ndvi' || $layerType === 'vegetation_health')) {
            if ($satZones !== []) {
                $seen = [];
                foreach ($satZones as $z) {
                    if (!is_array($z) || ($z['kind'] ?? '') !== 'green_deficit') {
                        continue;
                    }
                    $cell = (string)($z['cell'] ?? '');
                    if ($cell !== '' && isset($seen[$cell])) {
                        continue;
                    }
                    $seen[$cell] = true;
                    $ndvi = isset($z['ndvi']) ? (float)$z['ndvi'] : null;
                    if ($ndvi === null) {
                        continue;
                    }
                    $w = max(0.0, min(1.0, ($ndvi + 0.2) / 1.0));
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [(float)$z['lng'], (float)$z['lat']]],
                        'properties' => [
                            'weight' => round($w, 3),
                            'layer' => $layerType,
                            'ndvi' => round($ndvi, 3),
                            'source' => 'sentinelhub_ndvi_grid',
                        ],
                    ];
                }
                if ($features !== []) {
                    return ['type' => 'FeatureCollection', 'features' => $features];
                }
            }
            $cols = 4;
            $rows = 4;
            $latStep = ($bbox['max_lat'] - $bbox['min_lat']) / $rows;
            $lngStep = ($bbox['max_lng'] - $bbox['min_lng']) / $cols;
            $base = $layerType === 'ndvi' ? (float)($metrics['ndvi_score'] ?? 0.5) : (float)($metrics['vegetation_health_score'] ?? 0.5);
            for ($i = 0; $i < $rows; $i++) {
                for ($j = 0; $j < $cols; $j++) {
                    $lat = $bbox['min_lat'] + ($i + 0.5) * $latStep;
                    $lng = $bbox['min_lng'] + ($j + 0.5) * $lngStep;
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                        'properties' => [
                            'weight' => round($base, 3),
                            'layer' => $layerType,
                            'source' => !empty($metrics['satellite_ndvi_ok']) ? 'sentinelhub_statistics' : 'local_proxy',
                        ],
                    ];
                }
            }
            return ['type' => 'FeatureCollection', 'features' => $features];
        }
        return ['type' => 'FeatureCollection', 'features' => []];
    }
}
