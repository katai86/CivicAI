<?php
/**
 * CivicAI Intelligence Platform – modul meta és státusz (Milestone 1–3).
 */
require_once __DIR__ . '/../util.php';

final class IntelligenceModuleRegistry
{
    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return array_merge(self::dataSourceModules(), self::aiVisionModules());
    }

    /** @return list<array<string,mixed>> */
    private static function dataSourceModules(): array
    {
        return [
            self::def('global_forest_watch', 'climate_gfw', 'Global Forest Watch', 'Erdővesztés, zöldborítás – ingyenes GFW Data API.', 'climate', 'preview', 'https://data-api.globalforestwatch.org/', false),
            self::def('hungaromet', 'climate_hungaromet', 'HungaroMet', 'Időjárás, aszály, hőség – Open-Meteo proxy.', 'climate', 'preview', '', false),
            self::def('eea', 'climate_eea', 'European Environment Agency', 'EU környezeti indikátorok – EU nyílt adatok modullal összekapcsolva.', 'climate', 'linked_eu_open_data', 'https://www.eea.europa.eu/', false),
            self::def('gbif', 'climate_gbif', 'GBIF', 'Biodiverzitás, fajmegfigyelések.', 'climate', 'preview', 'https://api.gbif.org/v1/', false),
            self::def('pvgis', 'climate_pvgis', 'PVGIS', 'Napenergia-potenciál becslés.', 'climate', 'preview', 'https://re.jrc.ec.europa.eu/api/', false),
            self::def('nasa_viirs', 'climate_viirs', 'NASA VIIRS Night Lights', 'Éjszakai fények, fényszennyezés.', 'climate', 'preview', '', false),
            self::def('open_charge_map', 'climate_ocm', 'OpenChargeMap', 'EV töltőpontok térképezése.', 'mobility', 'preview', 'https://api.openchargemap.io/v3/', true),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function aiVisionModules(): array
    {
        return [
            self::def('sam2', 'ai_sam2', 'Meta SAM 2', 'Képszegmentálás – fa, víz, épület, burkolt felület.', 'ai_vision', 'planned', '', false),
            self::def('segment_anything', 'ai_sam', 'Segment Anything', 'Univerzális képszegmentálás.', 'ai_vision', 'planned', '', false),
            self::def('yolo', 'ai_yolo', 'YOLO objektumfelismerés', 'Kátyú, hulladék, kidőlt fa, közterületi problémák.', 'ai_vision', 'planned', '', false),
            self::def('depth_anything', 'ai_depth', 'Depth Anything', 'Terep és lombkorona mélység becslés.', 'ai_vision', 'planned', '', false),
            self::def('blip', 'ai_blip', 'BLIP képleírás', 'Automatikus képleírás jelentésekhez.', 'ai_vision', 'planned', '', false),
        ];
    }

    /** @return array<string,mixed> */
    private static function def(string $id, string $moduleKey, string $name, string $desc, string $category, string $status, string $endpoint, bool $apiKeyRequired): array
    {
        return [
            'id' => $id,
            'module_key' => $moduleKey,
            'name' => $name,
            'description' => $desc,
            'category' => $category,
            'enabled_setting' => 'enabled',
            'apiKeyRequired' => $apiKeyRequired,
            'endpoint' => $endpoint,
            'dataSourceType' => $category === 'ai_vision' ? 'model' : 'rest',
            'mapLayerAvailable' => $category !== 'ai_vision',
            'dashboardWidgetAvailable' => true,
            'reportAvailable' => true,
            'status' => $status,
        ];
    }

    /** @return array<string,array<string,mixed>> Admin → Beépülő modulok bővítéshez */
    public static function adminModuleDefinitions(): array
    {
        $out = [];
        foreach (self::definitions() as $def) {
            $key = (string)$def['module_key'];
            if ($key === 'climate_gfw') {
                continue;
            }
            $settings = [
                ['key' => 'enabled', 'label' => t('admin.enabled'), 'type' => 'checkbox'],
                ['key' => 'dashboard_widget', 'label' => t('intel.lbl_dashboard_widget'), 'type' => 'select', 'options' => ['0' => t('hu_open_data.opt_off'), '1' => t('hu_open_data.opt_on')]],
                ['key' => 'map_layer', 'label' => t('intel.lbl_map_layer'), 'type' => 'select', 'options' => ['0' => t('hu_open_data.opt_off'), '1' => t('hu_open_data.opt_on')]],
                ['key' => 'report_enabled', 'label' => t('intel.lbl_report'), 'type' => 'select', 'options' => ['0' => t('hu_open_data.opt_off'), '1' => t('hu_open_data.opt_on')]],
                ['key' => 'cache_ttl_minutes', 'label' => t('hu_open_data.lbl_cache_ttl'), 'type' => 'number', 'placeholder' => '360'],
            ];
            if (!empty($def['apiKeyRequired'])) {
                $settings[] = ['key' => 'api_key', 'label' => t('intel.lbl_api_key'), 'type' => 'password', 'mask' => true];
            }
            $out[$key] = [
                'name' => (string)$def['name'],
                'description' => (string)$def['description'],
                'settings' => $settings,
            ];
        }
        return $out;
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
                'dashboard_widget' => self::boolSetting($key, 'dashboard_widget', true),
                'map_layer' => self::boolSetting($key, 'map_layer', true),
                'report_enabled' => self::boolSetting($key, 'report_enabled', true),
                'refreshInterval' => (int)(get_module_setting($key, 'refresh_interval_minutes') ?: 360),
                'lastSync' => get_module_setting($key, 'last_sync_at'),
                'errorMessage' => get_module_setting($key, 'last_error'),
                'runtimeStatus' => self::runtimeStatus($def, $enabled),
            ]);
        }
        return $out;
    }

    private static function boolSetting(string $moduleKey, string $settingKey, bool $defaultWhenEnabled): bool
    {
        $v = get_module_setting($moduleKey, $settingKey);
        if ($v === null || $v === '') {
            return $defaultWhenEnabled;
        }
        return $v === '1' || $v === 1;
    }

    /** @param array<string,mixed> $def */
    private static function runtimeStatus(array $def, bool $enabled): string
    {
        if (!$enabled) {
            return 'inactive';
        }
        $st = (string)($def['status'] ?? '');
        if ($st === 'linked_eu_open_data' && function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()) {
            return 'active';
        }
        if ($st === 'preview') {
            return 'preview';
        }
        if ($st === 'planned') {
            return 'config_required';
        }
        return 'active';
    }

    /** @return array<string,list<array<string,mixed>>> */
    public static function listByCategory(): array
    {
        $grouped = [];
        foreach (self::listWithStatus() as $m) {
            $cat = (string)($m['category'] ?? 'other');
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $m;
        }
        return $grouped;
    }
}
