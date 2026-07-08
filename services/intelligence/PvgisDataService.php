<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../ExternalHttpClient.php';
require_once __DIR__ . '/../ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleTrait.php';

/** PVGIS – napenergia potenciál (ingyenes JRC API). */
class PvgisDataService
{
    use IntelligenceModuleTrait;

    protected function moduleKey(): string { return 'climate_pvgis'; }
    protected function sourceKey(): string { return 'pvgis'; }

    public function isActive(): bool { return $this->isModuleEnabled(); }

    /** @return array{ok:bool,annual_kwh:?float,co2_kg:?float,irradiation:?float,source:string,notes:array,cached:bool} */
    public function fetchContext(?int $authorityId): array
    {
        $out = ['ok' => false, 'annual_kwh' => null, 'co2_kg' => null, 'irradiation' => null, 'source' => 'pvgis', 'notes' => [], 'cached' => false];
        if (!$this->isActive()) {
            $out['notes'][] = 'module_disabled';
            return $out;
        }
        $bbox = self::authorityBbox($authorityId);
        $c = $bbox ? self::bboxCenter($bbox) : ['lat' => 47.16, 'lng' => 19.50];
        $cacheKey = 'pv_' . md5(json_encode($c));
        $cached = $this->cacheGet($cacheKey);
        if ($cached) return $cached;

        $url = 'https://re.jrc.ec.europa.eu/api/v5_2/PVcalc?lat=' . rawurlencode((string)$c['lat'])
            . '&lon=' . rawurlencode((string)$c['lng']) . '&peakpower=1&loss=14&outputformat=json';
        $resp = ExternalHttpClient::get($url, 30);
        if ($resp['ok'] && $resp['body'] !== '') {
            $j = json_decode($resp['body'], true);
            $totals = $j['outputs']['totals']['fixed'] ?? $j['outputs']['totals'] ?? null;
            if (is_array($totals)) {
                $annual = isset($totals['E_y']) ? (float)$totals['E_y'] : null;
                $out = [
                    'ok' => true,
                    'annual_kwh' => $annual,
                    'co2_kg' => $annual !== null ? round($annual * 0.35, 0) : null,
                    'irradiation' => isset($totals['H(i)_y']) ? (float)$totals['H(i)_y'] : null,
                    'source' => 'pvgis_live',
                    'notes' => [],
                    'cached' => false,
                ];
                $this->cacheSet($cacheKey, $out);
                return $out;
            }
        }
        $this->recordError($resp['error'] ?? 'pvgis_unreachable');
        $mock = ['ok' => true, 'annual_kwh' => 1150.0, 'co2_kg' => 400.0, 'irradiation' => 1350.0, 'source' => 'pvgis_reference', 'notes' => ['using_reference'], 'cached' => false];
        $this->cacheSet($cacheKey, $mock, 'reference');
        return $mock;
    }

    /** @return array{type:string,features:array} */
    public function mapGeoJson(?int $authorityId): array
    {
        $ctx = $this->fetchContext($authorityId);
        $bbox = self::authorityBbox($authorityId);
        if (!$bbox) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }
        $c = self::bboxCenter($bbox);
        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$c['lng'], $c['lat']]],
                'properties' => [
                    'layer' => 'pvgis',
                    'annual_kwh' => $ctx['annual_kwh'] ?? null,
                    'irradiation' => $ctx['irradiation'] ?? null,
                    'label' => 'PVGIS ~' . ($ctx['annual_kwh'] ?? '—') . ' kWh/kWp',
                ],
            ]],
        ];
    }
}
