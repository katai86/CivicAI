<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';
require_once __DIR__ . '/../ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleTrait.php';

/** NASA VIIRS éjszakai fény – élő ingest még nincs bekötve. */
class ViirsDataService
{
    use IntelligenceModuleTrait;

    protected function moduleKey(): string { return 'climate_viirs'; }
    protected function sourceKey(): string { return 'viirs'; }

    public function isActive(): bool { return $this->isModuleEnabled(); }

    /** @return array{ok:bool,light_pollution_index:?int,source:string,notes:array,cached:bool} */
    public function fetchContext(?int $authorityId): array
    {
        if (!$this->isActive()) {
            return ['ok' => false, 'light_pollution_index' => null, 'source' => 'viirs', 'notes' => ['module_disabled'], 'cached' => false, 'status' => 'disabled'];
        }
        $bbox = self::authorityBbox($authorityId);
        $cacheKey = 'viirs_' . md5(json_encode($bbox ?: []));
        $cached = $this->cacheGet($cacheKey, ['light_pollution_index']);
        if ($cached) {
            return $cached;
        }
        return $this->noLiveDataResponse(
            ['light_pollution_index' => null, 'source' => 'viirs', 'notes' => ['viirs_not_ingested']],
            ['light_pollution_index'],
            'viirs_not_ingested'
        );
    }

    /** @return array{type:string,features:array} */
    public function mapGeoJson(?int $authorityId): array
    {
        $ctx = $this->fetchContext($authorityId);
        if (empty($ctx['ok'])) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }
        $bbox = self::authorityBbox($authorityId);
        if (!$bbox) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }
        $c = self::bboxCenter($bbox);
        $idx = (int)($ctx['light_pollution_index'] ?? 0);
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
