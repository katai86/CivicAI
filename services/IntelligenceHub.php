<?php
/**
 * Intelligence Platform – összesítő hub (M4–M9).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalDataCache.php';
require_once __DIR__ . '/IntelligenceModuleRegistry.php';
require_once __DIR__ . '/GlobalForestWatchService.php';
require_once __DIR__ . '/ClimateIndexService.php';
require_once __DIR__ . '/intelligence/GbifDataService.php';
require_once __DIR__ . '/intelligence/HungaroMetDataService.php';
require_once __DIR__ . '/intelligence/PvgisDataService.php';
require_once __DIR__ . '/intelligence/OpenChargeMapDataService.php';
require_once __DIR__ . '/intelligence/ViirsDataService.php';

final class IntelligenceHub
{
    private static bool $liteFetchMode = false;

    public static function isLiteFetchMode(): bool
    {
        return self::$liteFetchMode;
    }

    /** @return array<string,mixed> */
    public function fetchFullContext(?int $authorityId, bool $lite = false): array
    {
        if ($lite) {
            self::$liteFetchMode = true;
        }
        try {
            return $this->fetchFullContextInner($authorityId, $lite);
        } finally {
            self::$liteFetchMode = false;
        }
    }

    /** @return array<string,mixed> */
    private function fetchFullContextInner(?int $authorityId, bool $lite): array
    {
        $bbox = null;
        if ($authorityId > 0) {
            try {
                $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
                $st->execute([$authorityId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['min_lat'] !== null) {
                    $bbox = [
                        'min_lat' => (float)$row['min_lat'],
                        'max_lat' => (float)$row['max_lat'],
                        'min_lng' => (float)$row['min_lng'],
                        'max_lng' => (float)$row['max_lng'],
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        $out = [
            'authority_id' => $authorityId,
            'bbox' => $bbox,
            'modules' => $this->safeCall(function () {
                return IntelligenceModuleRegistry::listWithStatus();
            }, []),
            'gbif' => $this->safeCall(function () use ($authorityId) {
                return (new GbifDataService())->fetchContext($authorityId);
            }, ['ok' => false]),
            'weather' => $this->safeCall(function () use ($authorityId) {
                return (new HungaroMetDataService())->fetchContext($authorityId);
            }, ['ok' => false]),
            'pvgis' => $this->safeCall(function () use ($authorityId) {
                return (new PvgisDataService())->fetchContext($authorityId);
            }, ['ok' => false]),
            'ocm' => $this->safeCall(function () use ($authorityId) {
                return (new OpenChargeMapDataService())->fetchContext($authorityId);
            }, ['ok' => false]),
            'viirs' => $this->safeCall(function () use ($authorityId) {
                return (new ViirsDataService())->fetchContext($authorityId);
            }, ['ok' => false]),
        ];

        if (!$lite) {
            $out['climate_index'] = $this->safeCall(function () use ($authorityId) {
                return (new ClimateIndexService())->compute($authorityId);
            }, ['score' => 0, 'category' => 'moderate', 'label' => '', 'recommendations' => []]);
            $out['gfw'] = $this->safeCall(function () use ($bbox) {
                return (new GlobalForestWatchService())->fetchContext($bbox);
            }, ['ok' => false]);
        }

        return $out;
    }

    /** @template T @param callable():T $fn @param T $fallback @return T */
    private function safeCall(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('IntelligenceHub: ' . $e->getMessage());
            }
            return $fallback;
        }
    }

    /** @return array{type:string,features:array,meta:array} */
    public function mapLayer(string $layerId, ?int $authorityId): array
    {
        $meta = ['layer' => $layerId, 'authority_id' => $authorityId, 'notes' => []];
        $geo = ['type' => 'FeatureCollection', 'features' => []];

        switch ($layerId) {
            case 'gbif':
                if ((new GbifDataService())->isActive()) {
                    $geo = (new GbifDataService())->mapGeoJson($authorityId);
                } else {
                    $meta['notes'][] = 'module_disabled';
                }
                break;
            case 'ocm':
            case 'ev_chargers':
                if ((new OpenChargeMapDataService())->isActive()) {
                    $geo = (new OpenChargeMapDataService())->mapGeoJson($authorityId);
                } else {
                    $meta['notes'][] = 'module_disabled';
                }
                break;
            case 'viirs':
            case 'night_lights':
                if ((new ViirsDataService())->isActive()) {
                    $geo = (new ViirsDataService())->mapGeoJson($authorityId);
                } else {
                    $meta['notes'][] = 'module_disabled';
                }
                break;
            case 'pvgis':
            case 'solar_potential':
                if ((new PvgisDataService())->isActive()) {
                    $geo = (new PvgisDataService())->mapGeoJson($authorityId);
                } else {
                    $meta['notes'][] = 'module_disabled';
                }
                break;
            case 'weather':
            case 'hungaromet':
                if ((new HungaroMetDataService())->isActive()) {
                    $geo = (new HungaroMetDataService())->mapGeoJson($authorityId);
                } else {
                    $meta['notes'][] = 'module_disabled';
                }
                break;
            case 'planting_priority':
            case 'green_deficit':
            case 'ndvi':
            case 'vegetation_health':
                require_once __DIR__ . '/CopernicusDataService.php';
                require_once __DIR__ . '/GreenIntelligence.php';
                $cop = new CopernicusDataService();
                $bbox = $this->resolveBbox($authorityId);
                if ($cop->isActive() && $bbox) {
                    $gi = (new GreenIntelligence())->compute($authorityId);
                    $geo = $cop->buildOverlayGeoJson($layerId, $authorityId, $bbox, $gi);
                } else {
                    $meta['notes'][] = 'copernicus_unavailable';
                }
                break;
            default:
                $meta['notes'][] = 'unknown_layer';
        }

        return ['type' => $geo['type'] ?? 'FeatureCollection', 'features' => $geo['features'] ?? [], 'meta' => $meta];
    }

    /** @return list<array{id:string,label:string,enabled:bool,category:string}> */
    public function availableMapLayers(): array
    {
        $layers = [
            ['id' => 'planting_priority', 'label' => 'planting_priority', 'module' => 'eu_open_data', 'category' => 'green'],
            ['id' => 'green_deficit', 'label' => 'green_deficit', 'module' => 'eu_open_data', 'category' => 'green'],
            ['id' => 'gbif', 'label' => 'gbif', 'module' => 'climate_gbif', 'category' => 'biodiversity'],
            ['id' => 'ocm', 'label' => 'ev_chargers', 'module' => 'climate_ocm', 'category' => 'mobility'],
            ['id' => 'viirs', 'label' => 'night_lights', 'module' => 'climate_viirs', 'category' => 'climate'],
            ['id' => 'pvgis', 'label' => 'solar_potential', 'module' => 'climate_pvgis', 'category' => 'climate'],
            ['id' => 'weather', 'label' => 'weather_station', 'module' => 'climate_hungaromet', 'category' => 'climate'],
        ];
        $labelKeys = [
            'planting_priority' => 'intel.layer_planting_priority',
            'green_deficit' => 'intel.layer_green_deficit',
            'gbif' => 'intel.layer_gbif',
            'ev_chargers' => 'intel.layer_ev_chargers',
            'night_lights' => 'intel.layer_night_lights',
            'solar_potential' => 'intel.layer_solar_potential',
            'weather_station' => 'intel.layer_weather_station',
        ];
        $out = [];
        foreach ($layers as $L) {
            $mod = (string)$L['module'];
            $enabled = false;
            if ($mod === 'eu_open_data') {
                $enabled = function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
                    && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('copernicus_enabled');
            } else {
                $enabled = get_module_setting($mod, 'enabled') === '1'
                    && (get_module_setting($mod, 'map_layer') === null || get_module_setting($mod, 'map_layer') === '' || get_module_setting($mod, 'map_layer') === '1');
            }
            $out[] = [
                'id' => $L['id'],
                'label' => $L['label'],
                'label_text' => function_exists('t') ? t($labelKeys[$L['label']] ?? $L['label']) : $L['label'],
                'category' => $L['category'],
                'enabled' => $enabled,
            ];
        }
        return $out;
    }

    /** @return ?array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} */
    private function resolveBbox(?int $authorityId): ?array
    {
        if ($authorityId === null || $authorityId <= 0) {
            return null;
        }
        try {
            $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
            $st->execute([$authorityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['min_lat'] === null) return null;
            return [
                'min_lat' => (float)$row['min_lat'],
                'max_lat' => (float)$row['max_lat'],
                'min_lng' => (float)$row['min_lng'],
                'max_lng' => (float)$row['max_lng'],
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array{ok:bool,module:string,data:array,notes:array} */
    public function testModule(string $moduleKey, ?int $authorityId): array
    {
        $svc = $this->serviceForModuleKey($moduleKey);
        if ($svc === null) {
            return ['ok' => false, 'module' => $moduleKey, 'data' => [], 'notes' => ['unknown_module']];
        }
        if (!$svc->isActive()) {
            return ['ok' => false, 'module' => $moduleKey, 'data' => [], 'notes' => ['module_disabled']];
        }
        try {
            $ctx = $svc->fetchContext($authorityId);
            ExternalDataCache::logProvider(
                $moduleKey,
                'test_connection',
                !empty($ctx['ok']) ? 'ok' : 'error',
                !empty($ctx['ok']) ? null : (($ctx['notes'][0] ?? null) ?: 'fetch_failed')
            );
            return [
                'ok' => !empty($ctx['ok']),
                'module' => $moduleKey,
                'data' => $ctx,
                'notes' => $ctx['notes'] ?? [],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'module' => $moduleKey, 'data' => [], 'notes' => ['exception']];
        }
    }

    private function serviceForModuleKey(string $moduleKey): ?object
    {
        $map = [
            'climate_gbif' => GbifDataService::class,
            'climate_hungaromet' => HungaroMetDataService::class,
            'climate_pvgis' => PvgisDataService::class,
            'climate_ocm' => OpenChargeMapDataService::class,
            'climate_viirs' => ViirsDataService::class,
        ];
        if (!isset($map[$moduleKey])) {
            return null;
        }
        $class = $map[$moduleKey];
        return new $class();
    }
}
