<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleTrait.php';

/** NASA VIIRS éjszakai fény – referencia rács (előkészítés, ingyenes adat később). */
class ViirsDataService
{
    use IntelligenceModuleTrait;

    protected function moduleKey(): string { return 'climate_viirs'; }
    protected function sourceKey(): string { return 'viirs'; }

    public function isActive(): bool { return $this->isModuleEnabled(); }

    /** @return array{ok:bool,light_pollution_index:int,source:string,notes:array,cached:bool} */
    public function fetchContext(?int $authorityId): array
    {
        if (!$this->isActive()) {
            return ['ok' => false, 'light_pollution_index' => 0, 'source' => 'viirs', 'notes' => ['module_disabled'], 'cached' => false];
        }
        $bbox = self::authorityBbox($authorityId);
        $cacheKey = 'viirs_' . md5(json_encode($bbox ?: []));
        $cached = $this->cacheGet($cacheKey);
        if ($cached) return $cached;

        $center = $bbox ? self::bboxCenter($bbox) : ['lat' => 47.16, 'lng' => 19.50];
        $urban = ($center['lat'] > 46.9 && $center['lat'] < 47.6 && $center['lng'] > 18.9 && $center['lng'] < 19.3);
        $idx = $urban ? 72 : 38;
        $out = ['ok' => true, 'light_pollution_index' => $idx, 'source' => 'viirs_reference_grid', 'notes' => ['preview_reference'], 'cached' => false];
        $this->cacheSet($cacheKey, $out, 'reference');
        return $out;
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
        $idx = (int)($ctx['light_pollution_index'] ?? 40);
        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$c['lng'], $c['lat']]],
                'properties' => ['light_pollution_index' => $idx, 'layer' => 'viirs'],
            ]],
        ];
    }
}
