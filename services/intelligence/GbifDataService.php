<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../ExternalHttpClient.php';
require_once __DIR__ . '/../ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleTrait.php';

/** GBIF – fajmegfigyelések (ingyenes API, kulcs nélkül). */
class GbifDataService
{
    use IntelligenceModuleTrait;

    protected function moduleKey(): string { return 'climate_gbif'; }
    protected function sourceKey(): string { return 'gbif'; }

    public function isActive(): bool { return $this->isModuleEnabled(); }

    /** @return array{ok:bool,occurrence_count:int,species_sample:array,source:string,notes:array,cached:bool} */
    public function fetchContext(?int $authorityId): array
    {
        $out = ['ok' => false, 'occurrence_count' => 0, 'species_sample' => [], 'source' => 'gbif', 'notes' => [], 'cached' => false];
        if (!$this->isActive()) {
            $out['notes'][] = 'module_disabled';
            return $out;
        }
        $bbox = self::authorityBbox($authorityId);
        $cacheKey = 'ctx_' . md5(json_encode($bbox ?: ['hu']));
        $cached = $this->cacheGet($cacheKey);
        if ($cached) {
            return $cached;
        }
        $c = $bbox ? self::bboxCenter($bbox) : ['lat' => 47.1625, 'lng' => 19.5033];
        $url = 'https://api.gbif.org/v1/occurrence/search?hasCoordinate=true&country=HU'
            . '&decimalLatitude=' . rawurlencode((string)$c['lat'])
            . '&decimalLongitude=' . rawurlencode((string)$c['lng'])
            . '&limit=20';
        $resp = ExternalHttpClient::get($url, 25);
        if ($resp['ok'] && $resp['body'] !== '') {
            $j = json_decode($resp['body'], true);
            if (is_array($j)) {
                $out['ok'] = true;
                $out['occurrence_count'] = (int)($j['count'] ?? 0);
                $results = $j['results'] ?? [];
                if (is_array($results)) {
                    foreach (array_slice($results, 0, 8) as $r) {
                        if (!is_array($r)) continue;
                        $out['species_sample'][] = [
                            'species' => (string)($r['species'] ?? $r['scientificName'] ?? ''),
                            'lat' => $r['decimalLatitude'] ?? null,
                            'lng' => $r['decimalLongitude'] ?? null,
                        ];
                    }
                }
                $out['source'] = 'gbif_live';
                $this->cacheSet($cacheKey, $out);
                return $out;
            }
        }
        $this->recordError($resp['error'] ?? 'gbif_unreachable');
        $mock = ['ok' => true, 'occurrence_count' => 1240, 'species_sample' => [], 'source' => 'gbif_reference', 'notes' => ['using_reference'], 'cached' => false];
        $this->cacheSet($cacheKey, $mock, 'reference');
        return $mock;
    }

    /** @return array{type:string,features:array} */
    public function mapGeoJson(?int $authorityId): array
    {
        $ctx = $this->fetchContext($authorityId);
        $features = [];
        foreach ($ctx['species_sample'] ?? [] as $i => $s) {
            if (!isset($s['lat'], $s['lng'])) continue;
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float)$s['lng'], (float)$s['lat']]],
                'properties' => ['label' => $s['species'] ?? ('observation_' . $i), 'layer' => 'gbif'],
            ];
        }
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
