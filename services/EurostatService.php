<?php
/**
 * Eurostat (EU socio-economic context) – basic country-level indicators.
 * Milestone 6.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class EurostatService
{
    private const BASE = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data';

    public function isActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('eurostat_enabled');
    }

    /**
     * @return array{ok:bool,geo:?string,country:?string,year:?int,population:?int,unemployment_rate:?float,cached:bool,error:?string,notes:array<int,string>}
     */
    public function fetchCountryContext(?int $authorityId, PDO $pdo): array
    {
        $out = [
            'ok' => false,
            'geo' => null,
            'country' => null,
            'year' => null,
            'population' => null,
            'unemployment_rate' => null,
            'cached' => false,
            'error' => null,
            'notes' => [],
        ];
        if (!$this->isActive()) {
            $out['error'] = 'eurostat_disabled';
            return $out;
        }
        if ($authorityId === null || $authorityId <= 0) {
            $out['error'] = 'authority_required';
            return $out;
        }

        $country = null;
        try {
            $st = $pdo->prepare('SELECT country FROM authorities WHERE id = ? LIMIT 1');
            $st->execute([$authorityId]);
            $country = $st->fetchColumn();
        } catch (Throwable $e) {}
        $country = is_string($country) ? trim($country) : '';
        if ($country === '') {
            $out['error'] = 'authority_country_required';
            return $out;
        }
        $geo = self::countryToEurostatGeo($country);
        if ($geo === null) {
            $out['error'] = 'unsupported_country';
            $out['country'] = $country;
            return $out;
        }
        $out['geo'] = $geo;
        $out['country'] = $country;

        $cacheKey = 'ctx_' . md5(json_encode(['geo' => $geo, 'v' => 1]));
        $hit = ExternalDataCache::getValid('eurostat', $cacheKey);
        if ($hit && !empty($hit['payload']['ok'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        $year = (int)gmdate('Y') - 2; // avoid missing latest-year gaps
        $yearsTried = [];
        $pop = null;
        $unemp = null;
        for ($k = 0; $k < 4; $k++) {
            $y = $year + $k; // try upward in case data exists
            $yearsTried[] = $y;
            $p = $this->fetchPopulation($geo, $y);
            $u = $this->fetchUnemploymentRate($geo, $y);
            if ($p !== null || $u !== null) {
                $year = $y;
                $pop = $p;
                $unemp = $u;
                break;
            }
        }
        if ($pop === null && $unemp === null) {
            $out['error'] = 'no_data';
            $out['notes'][] = 'years_tried:' . implode(',', $yearsTried);
            ExternalDataCache::logProvider('eurostat', 'country_context', 'error', $out['error']);
            return $out;
        }

        $out['ok'] = true;
        $out['year'] = $year;
        $out['population'] = $pop !== null ? (int)$pop : null;
        $out['unemployment_rate'] = $unemp !== null ? round((float)$unemp, 1) : null;
        $out['notes'][] = 'eurostat_api_country_geo:' . $geo;

        ExternalDataCache::set('eurostat', $cacheKey, $out, 1440, 'ok', null);
        ExternalDataCache::logProvider('eurostat', 'country_context', 'ok', 'geo=' . $geo . ';y=' . $year);
        return $out;
    }

    private function fetchPopulation(string $geo, int $year): ?int
    {
        $url = self::BASE . '/demo_pjan?' . http_build_query([
            'geo' => $geo,
            'sex' => 'T',
            'age' => 'TOTAL',
            'time' => (string)$year,
        ], '', '&', PHP_QUERY_RFC3986);
        $resp = ExternalHttpClient::get($url);
        if (!$resp['ok']) {
            return null;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j) || empty($j['value']) || !is_array($j['value'])) {
            return null;
        }
        $v = reset($j['value']);
        return is_numeric($v) ? (int)$v : null;
    }

    private function fetchUnemploymentRate(string $geo, int $year): ?float
    {
        $url = self::BASE . '/une_rt_a?' . http_build_query([
            'geo' => $geo,
            'sex' => 'T',
            'age' => 'Y15-74',
            'unit' => 'PC_ACT',
            'time' => (string)$year,
        ], '', '&', PHP_QUERY_RFC3986);
        $resp = ExternalHttpClient::get($url);
        if (!$resp['ok']) {
            return null;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j) || empty($j['value']) || !is_array($j['value'])) {
            return null;
        }
        $v = reset($j['value']);
        return is_numeric($v) ? (float)$v : null;
    }

    private static function countryToEurostatGeo(string $country): ?string
    {
        $c = trim($country);
        if ($c === '') return null;
        if (preg_match('/^[A-Za-z]{2}$/', $c)) {
            return strtoupper($c);
        }
        $n = mb_strtolower($c);
        $map = [
            'hungary' => 'HU', 'magyarország' => 'HU',
            'germany' => 'DE', 'deutschland' => 'DE',
            'austria' => 'AT', 'österreich' => 'AT', 'osterreich' => 'AT',
            'slovakia' => 'SK', 'slovak republic' => 'SK', 'slovensko' => 'SK',
            'slovenia' => 'SI', 'slovenija' => 'SI',
            'romania' => 'RO', 'românia' => 'RO',
            'croatia' => 'HR', 'hrvatska' => 'HR',
            'serbia' => 'RS', 'srbija' => 'RS',
            'italy' => 'IT', 'italia' => 'IT',
            'france' => 'FR',
            'spain' => 'ES', 'españa' => 'ES', 'espana' => 'ES',
            'poland' => 'PL', 'polska' => 'PL',
            'czechia' => 'CZ', 'czech republic' => 'CZ', 'česko' => 'CZ', 'cesko' => 'CZ',
            'netherlands' => 'NL', 'holland' => 'NL',
            'belgium' => 'BE', 'belgië' => 'BE', 'belgie' => 'BE',
            'sweden' => 'SE', 'denmark' => 'DK', 'finland' => 'FI',
            'portugal' => 'PT', 'greece' => 'EL', 'hellas' => 'EL',
            'ireland' => 'IE',
        ];
        return $map[$n] ?? null;
    }
}

