<?php
/**
 * Global Forest Watch – ingyenes Data API adapter (Milestone 4 előkészítés).
 * https://data-api.globalforestwatch.org/
 */
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class GlobalForestWatchService
{
    private const API_BASE = 'https://data-api.globalforestwatch.org';

    public function isActive(): bool
    {
        return function_exists('climate_gfw_module_enabled') && climate_gfw_module_enabled();
    }

    /**
     * Országos / bbox kontextus – ha az API nem elérhető, referencia mock.
     *
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}|null $bbox
     * @return array{ok:bool,tree_cover_percent:?float,tree_cover_loss_ha:?float,year:?int,source:string,notes:array,cached:bool}
     */
    public function fetchContext(?array $bbox): array
    {
        $out = [
            'ok' => false,
            'tree_cover_percent' => null,
            'tree_cover_loss_ha' => null,
            'year' => null,
            'source' => 'gfw',
            'notes' => [],
            'cached' => false,
        ];
        if (!$this->isActive()) {
            $out['notes'][] = 'gfw_module_disabled';
            return $out;
        }

        $cacheKey = 'ctx_' . md5(json_encode($bbox ?: []));
        $hit = ExternalDataCache::getValid('gfw', $cacheKey);
        if ($hit && !empty($hit['payload']) && is_array($hit['payload'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        if ($bbox) {
            $live = $this->fetchTreeCoverForBbox($bbox);
            if ($live['ok']) {
                ExternalDataCache::set('gfw', $cacheKey, $live, $this->cacheTtlMinutes(), 'ok', null);
                return $live;
            }
            $out['notes'] = array_merge($out['notes'], $live['notes'] ?? []);
        }

        $mock = [
            'ok' => true,
            'tree_cover_percent' => 22.5,
            'tree_cover_loss_ha' => null,
            'year' => 2023,
            'source' => 'gfw_reference',
            'notes' => ['gfw_using_reference'],
            'cached' => false,
        ];
        ExternalDataCache::set('gfw', $cacheKey, $mock, $this->cacheTtlMinutes(), 'ok', 'reference');
        return $mock;
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    private function fetchTreeCoverForBbox(array $bbox): array
    {
        $out = ['ok' => false, 'notes' => []];
        $minLng = (float)$bbox['min_lng'];
        $minLat = (float)$bbox['min_lat'];
        $maxLng = (float)$bbox['max_lng'];
        $maxLat = (float)$bbox['max_lat'];
        $url = self::API_BASE . '/dataset/umd_tree_cover_extent/latest/query/json?sql='
            . rawurlencode('SELECT SUM(area__ha) AS ha FROM data WHERE umd_tree_cover_extent__threshold = 30');
        $resp = ExternalHttpClient::get($url, 20);
        if (!$resp['ok'] || $resp['body'] === '') {
            $out['notes'][] = 'gfw_api_unreachable';
            return $out;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j)) {
            $out['notes'][] = 'gfw_invalid_response';
            return $out;
        }
        return [
            'ok' => true,
            'tree_cover_percent' => null,
            'tree_cover_loss_ha' => isset($j[0]['ha']) ? (float)$j[0]['ha'] : null,
            'year' => 2023,
            'source' => 'gfw_live',
            'notes' => [],
            'cached' => false,
        ];
    }

    private function cacheTtlMinutes(): int
    {
        $v = get_module_setting('climate_gfw', 'cache_ttl_minutes');
        if ($v !== null && $v !== '' && is_numeric($v)) {
            return max(60, min(10080, (int)$v));
        }
        return 1440;
    }
}
