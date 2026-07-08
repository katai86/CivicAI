<?php
/**
 * AI Vision – egységes képelemzés réteg (M5). Mock / előkészítés, később SAM2/YOLO/BLIP.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalDataCache.php';

class AiVisionService
{
    /** @return list<array{id:string,name:string,enabled:bool}> */
    public static function models(): array
    {
        $defs = [
            ['id' => 'ai_sam2', 'name' => 'Meta SAM 2'],
            ['id' => 'ai_yolo', 'name' => 'YOLO'],
            ['id' => 'ai_depth', 'name' => 'Depth Anything'],
            ['id' => 'ai_blip', 'name' => 'BLIP'],
        ];
        $out = [];
        foreach ($defs as $d) {
            $out[] = array_merge($d, ['enabled' => get_module_setting($d['id'], 'enabled') === '1']);
        }
        return $out;
    }

    /**
     * @param string $modelId ai_sam2|ai_yolo|ai_depth|ai_blip
     * @return array{ok:bool,model:string,segments:array,objects:array,description:?string,notes:array}
     */
    public function analyze(string $modelId, string $imageHash, ?string $filename = null): array
    {
        if (get_module_setting($modelId, 'enabled') !== '1') {
            return ['ok' => false, 'model' => $modelId, 'segments' => [], 'objects' => [], 'description' => null, 'notes' => ['model_disabled']];
        }
        $cacheKey = 'vision_' . $modelId . '_' . $imageHash;
        $hit = ExternalDataCache::getValid('ai_vision', $cacheKey);
        if ($hit && !empty($hit['payload'])) {
            return $hit['payload'];
        }

        $out = ['ok' => true, 'model' => $modelId, 'segments' => [], 'objects' => [], 'description' => null, 'notes' => ['preview_mock']];
        switch ($modelId) {
            case 'ai_sam2':
            case 'ai_sam':
                $out['segments'] = [
                    ['kind' => 'vegetation', 'coverage_pct' => 34],
                    ['kind' => 'pavement', 'coverage_pct' => 41],
                    ['kind' => 'building', 'coverage_pct' => 18],
                ];
                break;
            case 'ai_yolo':
                $out['objects'] = [
                    ['class' => 'tree', 'confidence' => 0.82],
                    ['class' => 'pothole', 'confidence' => 0.31],
                ];
                break;
            case 'ai_depth':
                $out['segments'] = [['kind' => 'canopy_depth_m', 'value' => 8.5]];
                break;
            case 'ai_blip':
                $out['description'] = function_exists('t')
                    ? t('intel.ai_blip_mock_desc')
                    : 'Mixed urban greenery with paved surfaces and partial tree canopy.';
                break;
            default:
                $out['ok'] = false;
                $out['notes'] = ['unknown_model'];
        }
        if ($out['ok']) {
            ExternalDataCache::set('ai_vision', $cacheKey, $out, 1440, 'ok', 'mock');
            set_module_setting($modelId, 'last_sync_at', gmdate('c'));
        }
        return $out;
    }
}
