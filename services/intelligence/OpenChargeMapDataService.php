<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
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
        $cached = $this->cacheGet($cacheKey);
        if ($cached) return $cached;

        $apiKey = trim((string)(get_module_setting('climate_ocm', 'api_key') ?? ''));
        $url = 'https://api.openchargemap.io/v3/poi/?output=json&latitude=' . $c['lat'] . '&longitude=' . $c['lng']
            . '&distance=25&distanceunit=KM&maxresults=80';
        if ($apiKey !== '') {
            $url .= '&key=' . rawurlencode($apiKey);
        }
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
        $this->recordError($resp['error'] ?? 'ocm_unreachable');
        $mock = ['ok' => true, 'charger_count' => 6, 'points' => [], 'source' => 'ocm_reference', 'notes' => ['using_reference'], 'cached' => false];
        $this->cacheSet($cacheKey, $mock, 'reference');
        return $mock;
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
