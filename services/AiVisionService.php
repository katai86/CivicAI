<?php
/**
 * AI Vision – közterületi képelemzés (felhő multimodális: Mistral Pixtral / OpenAI).
 * A UI „modell” választói (BLIP / YOLO / SAM2 / Depth) elemzési fókuszok, nem self-host súlyok.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalDataCache.php';
require_once __DIR__ . '/AiRouter.php';
require_once __DIR__ . '/AiPromptBuilder.php';
require_once __DIR__ . '/AiResultParser.php';

class AiVisionService
{
    private const MODES = ['ai_blip', 'ai_sam2', 'ai_yolo', 'ai_depth', 'ai_sam'];

    /** @return list<array{id:string,name:string,enabled:bool}> */
    public static function models(): array
    {
        $defs = [
            ['id' => 'ai_blip', 'name' => 'Teljes civic elemzés'],
            ['id' => 'ai_yolo', 'name' => 'Objektumfókusz'],
            ['id' => 'ai_sam2', 'name' => 'Jelenet / felületek'],
            ['id' => 'ai_depth', 'name' => 'Térbeli / mélység'],
        ];
        $router = new AiRouter();
        $aiOn = $router->isEnabled();
        $out = [];
        foreach ($defs as $d) {
            $modEnabled = get_module_setting($d['id'], 'enabled');
            // Ha a modul nincs explicit kikapcsolva és van AI provider → elérhető
            $enabled = $aiOn && ($modEnabled === null || $modEnabled === '' || $modEnabled === '1');
            $out[] = array_merge($d, ['enabled' => $enabled]);
        }
        return $out;
    }

    /**
     * @return array{
     *   ok:bool,model:string,provider_model:?string,segments:array,objects:array,
     *   description:?string,short_title:?string,suggested_category:?string,
     *   suggested_subcategory:?string,urgency_level:?string,hazard_level:?string,
     *   confidence_score:?float,depth_notes:?string,notes:array,error?:string
     * }
     */
    public function analyzeFile(string $modelId, string $imagePath, string $mimeType = 'image/jpeg', ?string $filename = null, ?int $entityId = null, string $entityType = 'vision'): array
    {
        $modelId = $this->normalizeMode($modelId);
        $empty = [
            'ok' => false,
            'model' => $modelId,
            'provider_model' => null,
            'segments' => [],
            'objects' => [],
            'description' => null,
            'short_title' => null,
            'suggested_category' => null,
            'suggested_subcategory' => null,
            'urgency_level' => null,
            'hazard_level' => null,
            'confidence_score' => null,
            'depth_notes' => null,
            'notes' => [],
        ];

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            $empty['notes'] = ['image_missing'];
            $empty['error'] = 'Image file missing';
            return $empty;
        }

        $router = new AiRouter();
        if (!$router->isEnabled()) {
            $empty['notes'] = ['ai_disabled'];
            $empty['error'] = function_exists('t') ? t('intel.ai_vision_need_provider') : 'AI provider not configured';
            return $empty;
        }

        $hash = @md5_file($imagePath) ?: md5($imagePath . (string)@filesize($imagePath));
        $cacheKey = 'vision_live_' . $modelId . '_' . $hash;
        $hit = ExternalDataCache::getValid('ai_vision', $cacheKey);
        if ($hit && !empty($hit['payload']) && !empty($hit['payload']['ok'])) {
            $cached = $hit['payload'];
            $cached['notes'] = array_values(array_unique(array_merge($cached['notes'] ?? [], ['cache_hit'])));
            return $cached;
        }

        $outputLang = function_exists('current_lang') ? current_lang() : 'hu';
        $prompt = AiPromptBuilder::civicImageAnalysis($modelId, $outputLang);
        $system = AiPromptBuilder::civicImageSystemPrompt();

        $resp = $router->callWithImage(
            'image_classification',
            $prompt,
            $imagePath,
            $mimeType !== '' ? $mimeType : 'image/jpeg',
            $system,
            ['timeout' => 25, 'max_tokens' => 700, 'temperature' => 0.15]
        );

        if (empty($resp['ok'])) {
            $empty['notes'] = ['vision_failed'];
            $empty['error'] = (string)($resp['error'] ?? 'Vision API failed');
            return $empty;
        }

        $norm = AiResultParser::normalizeCivicImageAnalysis(is_array($resp['data'] ?? null) ? $resp['data'] : null);
        $providerModel = isset($resp['model']) ? (string)$resp['model'] : null;

        $out = [
            'ok' => true,
            'model' => $modelId,
            'provider_model' => $providerModel,
            'segments' => $norm['segments'],
            'objects' => $norm['objects'],
            'description' => $norm['description'],
            'short_title' => $norm['short_title'],
            'suggested_category' => $norm['suggested_category'],
            'suggested_subcategory' => $norm['suggested_subcategory'],
            'urgency_level' => $norm['urgency_level'],
            'hazard_level' => $norm['hazard_level'],
            'confidence_score' => $norm['confidence_score'],
            'depth_notes' => $norm['depth_notes'],
            'notes' => ['live_vision'],
            'filename' => $filename,
        ];

        if (function_exists('ai_store_result')) {
            ai_store_result(
                $entityType,
                $entityId,
                'image_classification',
                $providerModel ?: $modelId,
                $hash,
                $norm,
                $norm['confidence_score']
            );
        }

        try {
            ExternalDataCache::set('ai_vision', $cacheKey, $out, 120, 'ok', 'live');
            set_module_setting($modelId === 'ai_sam' ? 'ai_sam2' : $modelId, 'last_sync_at', gmdate('c'));
        } catch (Throwable $e) {
            // ignore cache errors
        }

        return $out;
    }

    /**
     * City Brain WOW – utca + zöld + fa teljes elemzés egy képen.
     * @return array<string,mixed>
     */
    public function analyzeCitybrain(string $imagePath, string $mimeType = 'image/jpeg', ?string $filename = null): array
    {
        $empty = [
            'ok' => false,
            'model' => 'citybrain',
            'provider_model' => null,
            'notes' => [],
        ];

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            $empty['error'] = 'Image file missing';
            return $empty;
        }

        $router = new AiRouter();
        if (!$router->isEnabled()) {
            $empty['error'] = function_exists('t') ? t('intel.ai_vision_need_provider') : 'AI provider not configured';
            return $empty;
        }

        $hash = @md5_file($imagePath) ?: md5($imagePath . (string)@filesize($imagePath));
        $cacheKey = 'citybrain_' . $hash;
        $hit = ExternalDataCache::getValid('ai_vision', $cacheKey);
        if ($hit && !empty($hit['payload']) && !empty($hit['payload']['ok'])) {
            $cached = $hit['payload'];
            $cached['notes'] = array_values(array_unique(array_merge($cached['notes'] ?? [], ['cache_hit'])));
            return $cached;
        }

        $outputLang = function_exists('current_lang') ? current_lang() : 'hu';
        $prompt = AiPromptBuilder::citybrainVisionAnalysis($outputLang);
        $system = AiPromptBuilder::citybrainVisionSystemPrompt();

        $resp = $router->callWithImage(
            'image_classification',
            $prompt,
            $imagePath,
            $mimeType !== '' ? $mimeType : 'image/jpeg',
            $system,
            ['timeout' => 30, 'max_tokens' => 900, 'temperature' => 0.12]
        );

        if (empty($resp['ok'])) {
            $empty['notes'] = ['vision_failed'];
            $empty['error'] = (string)($resp['error'] ?? 'Vision API failed');
            return $empty;
        }

        $norm = AiResultParser::normalizeCitybrainVision(is_array($resp['data'] ?? null) ? $resp['data'] : null);
        $providerModel = isset($resp['model']) ? (string)$resp['model'] : null;

        $out = array_merge($norm, [
            'ok' => true,
            'model' => 'citybrain',
            'provider_model' => $providerModel,
            'notes' => ['citybrain_vision'],
            'filename' => $filename,
        ]);

        if (function_exists('ai_store_result')) {
            ai_store_result('citybrain', null, 'image_classification', $providerModel ?: 'citybrain', $hash, $norm, $norm['confidence_score'] ?? null);
        }

        try {
            ExternalDataCache::set('ai_vision', $cacheKey, $out, 120, 'ok', 'citybrain');
        } catch (Throwable $e) {
        }

        return $out;
    }

    /**
     * @deprecated Hash-only mock path – prefer analyzeFile(). Kept for GET/compat callers.
     * @return array{ok:bool,model:string,segments:array,objects:array,description:?string,notes:array}
     */
    public function analyze(string $modelId, string $imageHash, ?string $filename = null): array
    {
        return [
            'ok' => false,
            'model' => $this->normalizeMode($modelId),
            'segments' => [],
            'objects' => [],
            'description' => null,
            'notes' => ['image_required'],
            'error' => 'Upload an image file for live vision analysis',
        ];
    }

    private function normalizeMode(string $modelId): string
    {
        $modelId = trim($modelId);
        if ($modelId === 'ai_sam') {
            return 'ai_sam2';
        }
        if (!in_array($modelId, self::MODES, true)) {
            return 'ai_blip';
        }
        return $modelId;
    }
}
