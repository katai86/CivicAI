<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';
require_once __DIR__ . '/../ExternalHttpClient.php';
require_once __DIR__ . '/../ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleTrait.php';

/** OpenChargeMap – EV töltőpontok (ingyenes olvasás). */
class OpenChargeMapDataService
{
    use IntelligenceModuleTrait;

    protected function moduleKey(): string { return 'climate_ocm'; }
    protected function sourceKey(): string { return 'ocm'; }

    public function isActive(): bool { return $this->isModuleEnabled(); }

    /** @return array{ok:bool,charger_count:int,points:array,source:string,notes:array,cached:bool} */
    public function fetchContext(?int $authorityId): array
    {
        $out = ['ok' => false, 'charger_count' => 0, 'points' => [], 'source' => 'ocm', 'notes' => [], 'cached' => false];
        if (!$this->isActive()) {
            $out['notes'][] = 'module_disabled';
            return $out;
        }
        $bbox = self::authorityBbox($authorityId);
        $c = $bbox ? self::bboxCenter($bbox) : ['lat' => 47.16, 'lng' => 19.50];
        $cacheKey = 'ocm_' . md5(json_encode($c));
        $cached = $this->cacheGet($cacheKey, ['charger_count', 'points']);
        if ($cached) {
            return $cached;
        }
        $apiKey = trim((string)(get_module_setting('climate_ocm', 'api_key') ?? ''));
        if ($apiKey === '') {
            $out['notes'][] = 'ocm_key_missing';
            // Ne hívjuk a 403-as API-t, és ne spameljük a provider logot
            return $this->noLiveDataResponse($out, ['charger_count', 'points'], 'api_key_missing');
        }
        if ($this->liteFetchGuard()) {
            return $this->noLiveDataResponse($out, ['charger_count', 'points'], 'lite_fetch_skipped');
        }

        $url = 'https://api.openchargemap.io/v3/poi/?output=json&latitude=' . $c['lat'] . '&longitude=' . $c['lng']
            . '&distance=25&distanceunit=KM&maxresults=80'
            . '&key=' . rawurlencode($apiKey);
        $resp = ExternalHttpClient::get($url, 25);
        if ($resp['ok'] && $resp['body'] !== '') {
            $j = json_decode($resp['body'], true);
            if (is_array($j)) {
                $points = [];
                foreach (array_slice($j, 0, 80) as $p) {
                    if (!is_array($p)) continue;
                    $addr = $p['AddressInfo'] ?? [];
                    $conns = $p['Connections'] ?? [];
                    $kw = null;
                    if (is_array($conns) && isset($conns[0]['PowerKW'])) {
                        $kw = (float)$conns[0]['PowerKW'];
                    }
                    $points[] = [
                        'title' => (string)($addr['Title'] ?? 'Charger'),
                        'lat' => isset($addr['Latitude']) ? (float)$addr['Latitude'] : null,
                        'lng' => isset($addr['Longitude']) ? (float)$addr['Longitude'] : null,
                        'power_kw' => $kw,
                    ];
                }
                $out = ['ok' => true, 'charger_count' => count($points), 'points' => $points, 'source' => 'ocm_live', 'notes' => [], 'cached' => false];
                $this->cacheSet($cacheKey, $out);
                return $out;
            }
        }
        $err = $resp['error'] ?? 'ocm_unreachable';
        // 403 = tipikusan érvénytelen/hiányzó kulcs – config, ne „fetch error” spam
        if (stripos((string)$err, '403') !== false) {
            $out['notes'][] = 'ocm_key_missing';
            try {
                set_module_setting($this->moduleKey(), 'last_error', 'http_403');
            } catch (Throwable $e) {
            }
            return $this->noLiveDataResponse($out, ['charger_count', 'points'], 'api_key_missing', 'http_403');
        }
        $this->recordError($err);
        return $this->noLiveDataResponse($out, ['charger_count', 'points'], 'ocm_unreachable', $err);
    }

    /** @return array{type:string,features:array} */
    public function mapGeoJson(?int $authorityId): array
    {
        $ctx = $this->fetchContext($authorityId);
        $features = [];
        foreach ($ctx['points'] ?? [] as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float)$p['lng'], (float)$p['lat']]],
                'properties' => ['label' => $p['title'] ?? 'EV', 'power_kw' => $p['power_kw'] ?? null, 'layer' => 'ocm'],
            ];
        }
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
