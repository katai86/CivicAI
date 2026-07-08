<?php
/**
 * Magyar nyílt adatok – KSH STADAT / Nemzeti Közadatportál (kozadatportal.hu).
 * Összesítő statisztikák (nem egyedi fa pontok). Admin: module_key hu_open_data.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class HuOpenDataService
{
    private const CKAN_API = 'https://kozadatportal.hu/api/3/action/';

    private const KSH_CSV = [
        'green_areas' => 'https://www.ksh.hu/stadat_files/kor/hu/kor0011.csv',
        'forestry' => 'https://www.ksh.hu/stadat_files/kor/hu/kor0004.csv',
        'weather_national' => 'https://www.ksh.hu/stadat_files/kor/hu/kor0037.csv',
    ];

    private const KOZADAT_DATASET_URLS = [
        'green_areas' => 'https://kozadatportal.hu/dataset/082f71bb-e166-4dd9-9f1d-0e83789a6ecb',
        'forestry' => 'https://kozadatportal.hu/dataset/e84a1e6b-2947-4845-8e39-63feb915f646',
        'weather_national' => 'https://kozadatportal.hu/dataset/?tags=k%C3%B6rnyezet',
    ];

    public function isModuleActive(): bool
    {
        return function_exists('hu_open_data_module_enabled') && hu_open_data_module_enabled();
    }

    /**
     * @return array{ok:bool,green:?array,forestry:?array,weather_city:?array,weather_national:?array,links:array,notes:array,cached:bool,error:?string}
     */
    /**
     * @param bool $lite Dashboard: csak zöldterület (1 CSV), gyorsabb – nem hív CKAN városi időjárást.
     */
    public function fetchContext(?int $authorityId, PDO $pdo, bool $lite = false): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($lite ? 45 : 90);
        }

        $out = [
            'ok' => false,
            'green' => null,
            'forestry' => null,
            'weather_city' => null,
            'weather_national' => null,
            'links' => [
                'kozadatportal' => 'https://kozadatportal.hu/',
                'datasets' => self::KOZADAT_DATASET_URLS,
            ],
            'notes' => [],
            'cached' => false,
            'reference_snapshot' => false,
            'error' => null,
        ];

        if (!$this->isModuleActive()) {
            $out['error'] = 'hu_module_disabled';
            return $out;
        }

        $city = '';
        $county = '';
        if ($authorityId !== null && $authorityId > 0) {
            try {
                $st = $pdo->prepare('SELECT city, name FROM authorities WHERE id = ? LIMIT 1');
                $st->execute([$authorityId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $city = trim((string)($row['city'] ?? ''));
                    if ($city === '' && !empty($row['name'])) {
                        $city = trim((string)$row['name']);
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $cacheKey = 'ctx_' . md5(json_encode([
            'aid' => $authorityId,
            'city' => mb_strtolower($city),
            'lite' => $lite,
            'g' => hu_open_data_feature_enabled('ksh_green_areas_enabled'),
            'f' => $lite ? 0 : hu_open_data_feature_enabled('ksh_forestry_enabled'),
            'w' => $lite ? 0 : hu_open_data_feature_enabled('ksh_weather_enabled'),
            'v' => 3,
        ]));
        $hit = ExternalDataCache::getValid('hu_ksh', $cacheKey);
        if ($hit && !empty($hit['payload']) && is_array($hit['payload'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        $any = false;

        if (hu_open_data_feature_enabled('ksh_green_areas_enabled')) {
            $green = $this->loadGreenAreas();
            if ($green !== null) {
                $out['green'] = $green;
                $any = true;
            } else {
                $out['notes'][] = 'ksh_green_unavailable';
            }
        }

        if (!$lite && hu_open_data_feature_enabled('ksh_forestry_enabled')) {
            $forestry = $this->loadForestry();
            if ($forestry !== null) {
                $out['forestry'] = $forestry;
                $any = true;
            } else {
                $out['notes'][] = 'ksh_forestry_unavailable';
            }
        }

        if (!$lite && hu_open_data_feature_enabled('ksh_weather_enabled')) {
            $nat = $this->loadNationalWeather();
            if ($nat !== null) {
                $out['weather_national'] = $nat;
                $any = true;
            }
            $cityW = $this->loadCityWeather($city);
            if ($cityW !== null) {
                $out['weather_city'] = $cityW;
                $any = true;
            } elseif ($city !== '') {
                $out['notes'][] = 'ksh_city_weather_not_found';
            }
        }

        if (function_exists('hu_open_data_snapshot_fallback_enabled') && hu_open_data_snapshot_fallback_enabled()) {
            if ($this->applyReferenceSnapshotToContext($out, $lite)) {
                $any = true;
            }
        }

        $out['ok'] = $any;
        if (!$any) {
            $out['error'] = 'no_data_loaded';
        }

        if ($any) {
            ExternalDataCache::set('hu_ksh', $cacheKey, $out, hu_open_data_cache_ttl_minutes(), 'ok', null);
        }

        return $out;
    }

    /** @param array<string, mixed> $out */
    private function applyReferenceSnapshotToContext(array &$out, bool $lite): bool
    {
        $ref = $this->loadReferenceSnapshot();
        if ($ref === null) {
            return false;
        }
        $any = false;
        if (hu_open_data_feature_enabled('ksh_green_areas_enabled') && empty($out['green']) && !empty($ref['green']) && is_array($ref['green'])) {
            $g = $ref['green'];
            $out['green'] = [
                'value_ha' => (float)($g['value_ha'] ?? 0),
                'year' => (int)($g['year'] ?? 0),
                'scope' => (string)($g['scope'] ?? 'national'),
                'label' => (string)($g['label'] ?? 'Önkormányzati zöldterület (KSH referencia)'),
                'reference' => true,
            ];
            $any = true;
        }
        if (!$lite && hu_open_data_feature_enabled('ksh_forestry_enabled') && empty($out['forestry']) && !empty($ref['forestry']) && is_array($ref['forestry'])) {
            $f = $ref['forestry'];
            $out['forestry'] = [
                'total_ha' => (float)($f['total_ha'] ?? 0),
                'year' => (int)($f['year'] ?? 0),
                'species_groups' => null,
                'reference' => true,
            ];
            $any = true;
        }
        if (!$lite && hu_open_data_feature_enabled('ksh_weather_enabled') && empty($out['weather_national']) && !empty($ref['weather_national']) && is_array($ref['weather_national'])) {
            $w = $ref['weather_national'];
            $out['weather_national'] = [
                'temp_mean_c' => isset($w['temp_mean_c']) ? (float)$w['temp_mean_c'] : null,
                'precip_mm' => isset($w['precip_mm']) ? (float)$w['precip_mm'] : null,
                'year' => (int)($w['year'] ?? 0),
                'label' => (string)($w['label'] ?? 'Magyarország – KSH időjárás (referencia)'),
                'reference' => true,
            ];
            $any = true;
        }
        if (!$any) {
            return false;
        }
        $out['reference_snapshot'] = true;
        $out['notes'] = array_values(array_filter($out['notes'], static function ($n) {
            return !in_array($n, ['ksh_green_unavailable', 'ksh_forestry_unavailable'], true);
        }));
        $out['notes'][] = 'ksh_using_reference_snapshot';
        $out['error'] = null;
        return true;
    }

    /** @return ?array<string, mixed> */
    private function loadReferenceSnapshot(): ?array
    {
        $path = dirname(__DIR__) . '/data/ksh_reference_snapshot.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /**
     * Green Intelligence kiegészítés (összesítő KSH mutatók).
     *
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    public function augmentGreenMetrics(array $metrics, ?int $authorityId, PDO $pdo): array
    {
        if (!$this->isModuleActive() || !hu_open_data_feature_enabled('ksh_green_areas_enabled')) {
            return [];
        }
        $ctx = $this->fetchContext($authorityId, $pdo);
        if (!$ctx['ok']) {
            return ['hu_notes' => $ctx['notes'] ?? []];
        }
        $extra = [];
        if (!empty($ctx['green'])) {
            $g = $ctx['green'];
            $extra['ksh_municipal_green_ha'] = $g['value_ha'] ?? null;
            $extra['ksh_municipal_green_year'] = $g['year'] ?? null;
            $extra['ksh_municipal_green_scope'] = $g['scope'] ?? 'national';
        }
        if (!empty($ctx['forestry']) && hu_open_data_feature_enabled('ksh_forestry_enabled')) {
            $f = $ctx['forestry'];
            $extra['ksh_forest_stock_ha'] = $f['total_ha'] ?? null;
            $extra['ksh_forest_stock_year'] = $f['year'] ?? null;
        }
        $sources = $metrics['data_sources'] ?? [];
        if (!is_array($sources)) {
            $sources = [];
        }
        if (!empty($ctx['green'])) {
            $sources[] = 'ksh_kor0011_municipal_green';
        }
        if (!empty($ctx['forestry'])) {
            $sources[] = 'ksh_kor0004_forestry';
        }
        if (!empty($ctx['weather_national']) || !empty($ctx['weather_city'])) {
            $sources[] = 'ksh_weather_stadat';
        }
        $extra['data_sources'] = array_values(array_unique($sources));
        return $extra;
    }

    /** @return ?array{value_ha:float,year:int,scope:string,label:string} */
    private function loadGreenAreas(): ?array
    {
        $rows = $this->fetchCsvRows(self::KSH_CSV['green_areas']);
        if ($rows === null) {
            return null;
        }
        $parsed = $this->extractLatestNationalTotal($rows, ['összesen', 'osszesen', 'magyarország', 'magyarorszag']);
        if ($parsed === null) {
            $parsed = $this->extractLatestNumericSeries($rows);
        }
        if ($parsed === null) {
            return null;
        }
        return [
            'value_ha' => (float)$parsed['value'],
            'year' => (int)$parsed['year'],
            'scope' => 'national',
            'label' => 'Önkormányzati tulajdonú zöldterületek (KSH)',
        ];
    }

    /** @return ?array{total_ha:float,year:int,species_groups:int} */
    private function loadForestry(): ?array
    {
        $rows = $this->fetchCsvRows(self::KSH_CSV['forestry']);
        if ($rows === null) {
            return null;
        }
        $parsed = $this->extractLatestNationalTotal($rows, ['összesen', 'osszesen']);
        if ($parsed === null) {
            $parsed = $this->extractLatestNumericSeries($rows);
        }
        if ($parsed === null) {
            return null;
        }
        return [
            'total_ha' => (float)$parsed['value'],
            'year' => (int)$parsed['year'],
            'species_groups' => null,
        ];
    }

    /** @return ?array{temp_mean_c:?float,precip_mm:?float,year:int,label:string} */
    private function loadNationalWeather(): ?array
    {
        $rows = $this->fetchCsvRows(self::KSH_CSV['weather_national']);
        if ($rows === null) {
            return null;
        }
        return $this->extractWeatherRow($rows, ['magyarország', 'magyarorszag']);
    }

    /** @return ?array{temp_mean_c:?float,precip_mm:?float,year:int,city:string,label:string} */
    private function loadCityWeather(string $city): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }
        $key = $this->normalizeCityKey($city);
        $url = $this->resolveCityWeatherCsvUrl($city);
        if ($url === null || $url === '') {
            return null;
        }
        $rows = $this->fetchCsvRows($url);
        if ($rows === null) {
            return null;
        }
        $w = $this->extractWeatherRow($rows, [$key, $city]);
        if ($w === null) {
            $w = $this->extractLatestNumericSeries($rows);
            if ($w === null) {
                return null;
            }
            return [
                'temp_mean_c' => null,
                'precip_mm' => (float)$w['value'],
                'year' => (int)$w['year'],
                'city' => $city,
                'label' => $city . ' – KSH időjárás',
            ];
        }
        $w['city'] = $city;
        $w['label'] = $city . ' – KSH időjárás';
        return $w;
    }

    private function resolveCityWeatherCsvUrl(string $city): ?string
    {
        $q = $city . ' időjárási adatai';
        $url = self::CKAN_API . 'package_search?q=' . rawurlencode($q) . '&rows=1';
        $resp = ExternalHttpClient::get($url, min(12, $this->httpTimeoutSeconds()));
        if (!$resp['ok'] || $resp['body'] === '') {
            return null;
        }
        $j = json_decode($resp['body'], true);
        $results = $j['result']['results'] ?? [];
        if (!is_array($results) || count($results) === 0) {
            return null;
        }
        $resources = $results[0]['resources'] ?? [];
        if (!is_array($resources)) {
            return null;
        }
        foreach ($resources as $res) {
            $u = trim((string)($res['url'] ?? ''));
            if ($u !== '' && stripos($u, '.csv') !== false) {
                return $u;
            }
        }
        return null;
    }

    /** @return ?array<int, array<int, string>> */
    private function httpTimeoutSeconds(): int
    {
        $t = hu_open_data_request_timeout_seconds();
        return max(5, min(20, $t));
    }

    private function fetchCsvRows(string $csvUrl): ?array
    {
        $resp = $this->isKshUrl($csvUrl)
            ? $this->fetchKshHttp($csvUrl, $this->httpTimeoutSeconds())
            : ExternalHttpClient::get($csvUrl, $this->httpTimeoutSeconds());
        if (!$resp['ok'] || $resp['body'] === '' || stripos($resp['body'], 'rejected') !== false) {
            ExternalDataCache::logProvider('hu_ksh', 'csv_fetch', 'error', ($resp['error'] ?? 'http_error') . ' ' . $csvUrl);
            return null;
        }
        $body = $resp['body'];
        if (!mb_check_encoding($body, 'UTF-8')) {
            $converted = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $body);
            if (is_string($converted) && $converted !== '') {
                $body = $converted;
            }
        }
        $lines = preg_split('/\r\n|\r|\n/', $body);
        if (!is_array($lines)) {
            return null;
        }
        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line, ';');
        }
        return $rows === [] ? null : $rows;
    }

    private function isKshUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        return $host === 'ksh.hu' || substr($host, -7) === '.ksh.hu';
    }

    /**
     * KSH STADAT CSV – böngészőszerű fejlécek (egyes WAF-ek elutasítják az alapértelmezett PHP UA-t).
     *
     * @return array{ok:bool,status:int,body:string,error:?string,url:string}
     */
    private function fetchKshHttp(string $url, int $timeoutSeconds): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'empty_url', 'url' => ''];
        }
        if (!function_exists('curl_init')) {
            return ExternalHttpClient::get($url, $timeoutSeconds);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init_failed', 'url' => $url];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(12, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/csv,text/plain,*/*',
                'Accept-Language: hu-HU,hu;q=0.9,en;q=0.8',
            ],
            CURLOPT_REFERER => 'https://www.ksh.hu/',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'curl_exec_failed', 'url' => $url];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'body' => (string)$body, 'error' => $ok ? null : ('http_' . $status), 'url' => $url];
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param string[] $labelNeedles
     * @return ?array{value:float,year:int}
     */
    private function extractLatestNationalTotal(array $rows, array $labelNeedles): ?array
    {
        $yearCols = $this->detectYearColumns($rows);
        if ($yearCols === null) {
            return null;
        }
        [$headerIdx, $years] = $yearCols;
        foreach ($rows as $i => $row) {
            if ($i <= $headerIdx) {
                continue;
            }
            $label = mb_strtolower(implode(' ', array_map('strval', $row)));
            $match = false;
            foreach ($labelNeedles as $needle) {
                if ($needle !== '' && mb_strpos($label, mb_strtolower($needle)) !== false) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
            $best = $this->latestValueInRow($row, $years);
            if ($best !== null) {
                return $best;
            }
        }
        return null;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return ?array{value:float,year:int}
     */
    private function extractLatestNumericSeries(array $rows): ?array
    {
        $yearCols = $this->detectYearColumns($rows);
        if ($yearCols === null) {
            return null;
        }
        [, $years] = $yearCols;
        $bestYear = 0;
        $bestVal = null;
        foreach ($rows as $row) {
            $v = $this->latestValueInRow($row, $years);
            if ($v !== null && $v['year'] >= $bestYear) {
                $bestYear = $v['year'];
                $bestVal = $v['value'];
            }
        }
        if ($bestVal === null) {
            return null;
        }
        return ['value' => $bestVal, 'year' => $bestYear];
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param string[] $labelNeedles
     * @return ?array{temp_mean_c:?float,precip_mm:?float,year:int,label:string}
     */
    private function extractWeatherRow(array $rows, array $labelNeedles): ?array
    {
        $yearCols = $this->detectYearColumns($rows);
        if ($yearCols === null) {
            return null;
        }
        [$headerIdx, $years] = $yearCols;
        $tempRow = null;
        $precipRow = null;
        foreach ($rows as $i => $row) {
            if ($i <= $headerIdx) {
                continue;
            }
            $label = mb_strtolower(implode(' ', array_map('strval', $row)));
            $ok = false;
            foreach ($labelNeedles as $needle) {
                if ($needle !== '' && mb_strpos($label, mb_strtolower($needle)) !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            if (mb_strpos($label, 'hőmérséklet') !== false || mb_strpos($label, 'homerséklet') !== false || mb_strpos($label, 'temperature') !== false) {
                $tempRow = $row;
            }
            if (mb_strpos($label, 'csapadék') !== false || mb_strpos($label, 'csapadek') !== false || mb_strpos($label, 'precipitation') !== false) {
                $precipRow = $row;
            }
        }
        if ($tempRow === null && $precipRow === null) {
            return null;
        }
        $refRow = $tempRow ?? $precipRow;
        $latest = $this->latestValueInRow($refRow, $years);
        $year = $latest['year'] ?? (int)gmdate('Y');
        $temp = null;
        $precip = null;
        if ($tempRow !== null) {
            $t = $this->latestValueInRow($tempRow, $years);
            $temp = $t !== null ? (float)$t['value'] : null;
            if ($t !== null) {
                $year = $t['year'];
            }
        }
        if ($precipRow !== null) {
            $p = $this->latestValueInRow($precipRow, $years);
            $precip = $p !== null ? (float)$p['value'] : null;
        }
        return [
            'temp_mean_c' => $temp,
            'precip_mm' => $precip,
            'year' => $year,
            'label' => 'KSH időjárás',
        ];
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return ?array{0:int,1:array<int,int>}
     */
    private function detectYearColumns(array $rows): ?array
    {
        foreach ($rows as $idx => $row) {
            $years = [];
            foreach ($row as $colIdx => $cell) {
                $cell = trim((string)$cell);
                if (preg_match('/^(19|20)\d{2}$/', $cell)) {
                    $years[(int)$cell] = $colIdx;
                }
            }
            if (count($years) >= 2) {
                ksort($years);
                return [$idx, $years];
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $row
     * @param array<int, int> $years year => column index
     * @return ?array{value:float,year:int}
     */
    private function latestValueInRow(array $row, array $years): ?array
    {
        $bestYear = 0;
        $bestVal = null;
        foreach ($years as $year => $colIdx) {
            $raw = isset($row[$colIdx]) ? trim((string)$row[$colIdx]) : '';
            $raw = str_replace([' ', "\xC2\xA0"], '', $raw);
            $raw = str_replace(',', '.', $raw);
            if ($raw === '' || !is_numeric($raw)) {
                continue;
            }
            $val = (float)$raw;
            if ($year >= $bestYear) {
                $bestYear = $year;
                $bestVal = $val;
            }
        }
        if ($bestVal === null) {
            return null;
        }
        return ['value' => $bestVal, 'year' => $bestYear];
    }

    private function normalizeCityKey(string $city): string
    {
        $c = mb_strtolower(trim($city));
        $c = str_replace([' ', '-'], '', $c);
        return strtr($c, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);
    }
}
