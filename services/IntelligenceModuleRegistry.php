<?php
/**
 * CivicAI Intelligence Platform – modul meta és státusz (Milestone 1).
 * Külső adatforrások és AI modellek egységes leírója; bővíthető új modulokkal.
 */
require_once __DIR__ . '/../util.php';

final class IntelligenceModuleRegistry
{
    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'id' => 'global_forest_watch',
                'module_key' => 'climate_gfw',
                'name' => 'Global Forest Watch',
                'description' => 'Erdővesztés, zöldborítás – ingyenes GFW Data API (referencia / bbox).',
                'category' => 'climate',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => 'https://data-api.globalforestwatch.org/',
                'dataSourceType' => 'rest',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'preview',
            ],
            [
                'id' => 'hungaromet',
                'module_key' => 'climate_hungaromet',
                'name' => 'HungaroMet',
                'description' => 'Időjárás, aszály, hőség – előkészítés alatt (adapter + mock).',
                'category' => 'climate',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => '',
                'dataSourceType' => 'rest',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'planned',
            ],
            [
                'id' => 'eea',
                'module_key' => 'climate_eea',
                'name' => 'European Environment Agency',
                'description' => 'EU környezeti indikátorok – kapcsolódik az EU nyílt adatok modulhoz.',
                'category' => 'climate',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => 'https://www.eea.europa.eu/',
                'dataSourceType' => 'rest',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'linked_eu_open_data',
            ],
            [
                'id' => 'gbif',
                'module_key' => 'climate_gbif',
                'name' => 'GBIF',
                'description' => 'Biodiverzitás, fajmegfigyelések – előkészítés alatt.',
                'category' => 'climate',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => 'https://api.gbif.org/v1/',
                'dataSourceType' => 'rest',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'planned',
            ],
            [
                'id' => 'pvgis',
                'module_key' => 'climate_pvgis',
                'name' => 'PVGIS',
                'description' => 'Napenergia-potenciál – előkészítés alatt.',
                'category' => 'climate',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => 'https://re.jrc.ec.europa.eu/api/',
                'dataSourceType' => 'rest',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'planned',
            ],
            [
                'id' => 'nasa_viirs',
                'module_key' => 'climate_viirs',
                'name' => 'NASA VIIRS Night Lights',
                'description' => 'Éjszakai fények, fényszennyezés – előkészítés alatt.',
                'category' => 'climate',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => '',
                'dataSourceType' => 'raster',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'planned',
            ],
            [
                'id' => 'open_charge_map',
                'module_key' => 'climate_ocm',
                'name' => 'OpenChargeMap',
                'description' => 'EV töltőpontok – előkészítés alatt.',
                'category' => 'mobility',
                'enabled_setting' => 'enabled',
                'apiKeyRequired' => false,
                'endpoint' => 'https://api.openchargemap.io/v3/',
                'dataSourceType' => 'rest',
                'mapLayerAvailable' => true,
                'dashboardWidgetAvailable' => true,
                'reportAvailable' => true,
                'status' => 'planned',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function listWithStatus(): array
    {
        $out = [];
        foreach (self::definitions() as $def) {
            $key = (string)$def['module_key'];
            $enabled = get_module_setting($key, (string)$def['enabled_setting']) === '1';
            $out[] = array_merge($def, [
                'enabled' => $enabled,
                'apiKey' => null,
                'refreshInterval' => (int)(get_module_setting($key, 'refresh_interval_minutes') ?: 360),
                'lastSync' => get_module_setting($key, 'last_sync_at'),
                'errorMessage' => get_module_setting($key, 'last_error'),
                'runtimeStatus' => self::runtimeStatus($def, $enabled),
            ]);
        }
        return $out;
    }

    /** @param array<string,mixed> $def */
    private static function runtimeStatus(array $def, bool $enabled): string
    {
        if (!$enabled) {
            return 'inactive';
        }
        $st = (string)($def['status'] ?? '');
        if ($st === 'preview' || $st === 'planned' || $st === 'linked_eu_open_data') {
            return $st === 'linked_eu_open_data' && function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
                ? 'active' : ($st === 'preview' ? 'preview' : 'config_required');
        }
        return 'active';
    }
}
