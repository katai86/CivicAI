<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../ExternalHttpClient.php';
require_once __DIR__ . '/../ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleTrait.php';

/** HungaroMet helyettesítő: Open-Meteo (ingyenes) + referencia aszályindex. */
class HungaroMetDataService
{
    use IntelligenceModuleTrait;

    protected function moduleKey(): string { return 'climate_hungaromet'; }
    protected function sourceKey(): string { return 'hungaromet'; }

    public function isActive(): bool { return $this->isModuleEnabled(); }

    /** @return array{ok:bool,temp_c:?float,precip_mm:?float,drought_index:int,heat_risk:int,source:string,notes:array,cached:bool} */
    public function fetchContext(?int $authorityId): array
    {
        $out = ['ok' => false, 'temp_c' => null, 'precip_mm' => null, 'drought_index' => 0, 'heat_risk' => 0, 'source' => 'hungaromet', 'notes' => [], 'cached' => false];
        if (!$this->isActive()) {
            $out['notes'][] = 'module_disabled';
            return $out;
        }
        $bbox = self::authorityBbox($authorityId);
        $c = $bbox ? self::bboxCenter($bbox) : ['lat' => 47.16, 'lng' => 19.50];
        $cacheKey = 'wx_' . md5(json_encode($c));
        $cached = $this->cacheGet($cacheKey);
        if ($cached) return $cached;

        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $c['lat'] . '&longitude=' . $c['lng']
            . '&current=temperature_2m,precipitation&daily=temperature_2m_max,precipitation_sum&forecast_days=7&timezone=auto';
        $resp = ExternalHttpClient::get($url, 20);
        if ($resp['ok'] && $resp['body'] !== '') {
            $j = json_decode($resp['body'], true);
            if (is_array($j)) {
                $cur = $j['current'] ?? [];
                $daily = $j['daily'] ?? [];
                $precipSum = 0.0;
                if (!empty($daily['precipitation_sum']) && is_array($daily['precipitation_sum'])) {
                    $precipSum = (float)array_sum($daily['precipitation_sum']);
                }
                $temp = isset($cur['temperature_2m']) ? (float)$cur['temperature_2m'] : null;
                $out = [
                    'ok' => true,
                    'temp_c' => $temp,
                    'precip_mm' => $precipSum > 0 ? round($precipSum, 1) : (isset($cur['precipitation']) ? (float)$cur['precipitation'] : null),
                    'drought_index' => $precipSum < 5 ? 65 : ($precipSum < 15 ? 35 : 15),
                    'heat_risk' => ($temp !== null && $temp >= 32) ? 80 : (($temp !== null && $temp >= 28) ? 50 : 20),
                    'source' => 'open_meteo_proxy',
                    'notes' => ['hungaromet_via_open_meteo'],
                    'cached' => false,
                ];
                $this->cacheSet($cacheKey, $out);
                return $out;
            }
        }
        $this->recordError($resp['error'] ?? 'weather_unreachable');
        $mock = ['ok' => true, 'temp_c' => 24.0, 'precip_mm' => 12.0, 'drought_index' => 40, 'heat_risk' => 25, 'source' => 'reference', 'notes' => ['using_reference'], 'cached' => false];
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
                    'layer' => 'weather',
                    'temp_c' => $ctx['temp_c'] ?? null,
                    'precip_mm' => $ctx['precip_mm'] ?? null,
                    'drought_index' => $ctx['drought_index'] ?? null,
                    'label' => ($ctx['temp_c'] ?? '—') . ' °C',
                ],
            ]],
        ];
    }
}
