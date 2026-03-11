<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/MistralProvider.php';
require_once __DIR__ . '/OpenAIProvider.php';
require_once __DIR__ . '/GeminiProvider.php';

/**
 * AiRouter – provider választás (Mistral / OpenAI / Gemini), retry, cost-control.
 * default_ai_provider (module_settings mistral) = mistral | openai; ugyanazok a limitek mindkettőre.
 */
class AiRouter
{
    private ?AiProviderInterface $provider = null;

    public function __construct()
    {
        $mistralOk = function_exists('get_module_setting') && get_module_setting('mistral', 'enabled') === '1' && ((string)(get_module_setting('mistral', 'api_key') ?? '')) !== '';
        $openaiOk = function_exists('get_module_setting') && get_module_setting('openai', 'enabled') === '1' && ((string)(get_module_setting('openai', 'api_key') ?? '')) !== '';
        $envEnabled = defined('AI_ENABLED') && AI_ENABLED;
        $default = (function_exists('get_module_setting') ? (get_module_setting('mistral', 'default_ai_provider') ?: '') : '') ?: (defined('AI_PROVIDER') ? (string)AI_PROVIDER : 'mistral');

        if ($default === 'gemini' && (defined('GEMINI_API_KEY') && (string)GEMINI_API_KEY !== '')) {
            $this->provider = new GeminiProvider();
            return;
        }
        if (($default === 'openai' || !$mistralOk) && $openaiOk) {
            $this->provider = new OpenAIProvider(null, null);
            return;
        }
        if ($mistralOk || ($envEnabled && defined('MISTRAL_API_KEY') && (string)MISTRAL_API_KEY !== '')) {
            $this->provider = new MistralProvider(function_exists('mistral_api_key') ? mistral_api_key() : null);
            return;
        }
        if ($openaiOk) {
            $this->provider = new OpenAIProvider(null, null);
        }
    }

    public function isEnabled(): bool
    {
        return $this->provider !== null;
    }

    /**
     * Egyszerű rate limit: ai_results alapján számol, nap/ task_type szerint.
     * Limitek: get_ai_limit() – admin Beépülő modulok (mistral) vagy env.
     */
    private function withinLimit(string $taskType): bool
    {
        if ($taskType === 'report_classification') {
            $max = function_exists('get_ai_limit') ? get_ai_limit('reports_per_day') : (defined('AI_MAX_REPORTS_PER_DAY') ? (int) AI_MAX_REPORTS_PER_DAY : 0);
            if ($max <= 0) return false;
            try {
                $stmt = db()->prepare("SELECT COUNT(*) FROM ai_results WHERE task_type = 'report_classification' AND created_at >= CURDATE()");
                $stmt->execute();
                $cnt = (int)$stmt->fetchColumn();
                return $cnt < $max;
            } catch (Throwable $e) {
                return false;
            }
        }
        if (in_array($taskType, ['admin_summary','gov_summary','gov_esg'], true)) {
            $max = function_exists('get_ai_limit') ? get_ai_limit('summary') : (defined('AI_SUMMARY_LIMIT') ? (int) AI_SUMMARY_LIMIT : 0);
            if ($max <= 0) return false;
            try {
                $stmt = db()->prepare("SELECT COUNT(*) FROM ai_results WHERE task_type IN ('admin_summary','gov_summary','gov_esg') AND created_at >= CURDATE()");
                $stmt->execute();
                $cnt = (int)$stmt->fetchColumn();
                return $cnt < $max;
            } catch (Throwable $e) {
                return false;
            }
        }
        if ($taskType === 'image_classification') {
            $max = function_exists('get_ai_limit') ? get_ai_limit('image_analysis') : (defined('AI_IMAGE_ANALYSIS_LIMIT') ? (int) AI_IMAGE_ANALYSIS_LIMIT : 0);
            if ($max <= 0) return false;
            try {
                $stmt = db()->prepare("SELECT COUNT(*) FROM ai_results WHERE task_type = 'image_classification' AND created_at >= CURDATE()");
                $stmt->execute();
                $cnt = (int)$stmt->fetchColumn();
                return $cnt < $max;
            } catch (Throwable $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * Általános szöveges AI-hívás, JSON választ várunk.
     *
     * @return array ['ok'=>bool,'data'=>array|null,'raw'=>mixed|null]
     */
    public function callJson(string $taskType, string $prompt, array $meta = []): array
    {
        if (!$this->isEnabled() || !$this->withinLimit($taskType)) {
            return ['ok' => false, 'error' => 'AI disabled or limit reached'];
        }

        if ($this->provider instanceof \OpenAIProvider) {
            $model = (string)($meta['model'] ?? '');
            if ($model === '' && function_exists('get_module_setting')) {
                $model = (string)(get_module_setting('openai', 'model') ?: '');
            }
            if ($model === '' && defined('OPENAI_MODEL')) {
                $model = (string) OPENAI_MODEL;
            }
            if ($model === '') {
                $model = 'gpt-4o-mini';
            }
        } else {
            $model = $meta['model'] ?? (string)(defined('AI_TEXT_MODEL') ? AI_TEXT_MODEL : '');
        }
        if ($model === '') {
            return ['ok' => false, 'error' => 'AI model missing'];
        }

        $options = [
            'timeout' => $meta['timeout'] ?? 6,
            'temperature' => $meta['temperature'] ?? 0.15,
            'max_tokens' => $meta['max_tokens'] ?? 512,
            'system' => $meta['system'] ?? null,
        ];

        $attempts = 0;
        $last = null;
        while ($attempts < 2) {
            $attempts++;
            $resp = $this->provider ? $this->provider->complete($model, $prompt, $options) : ['ok' => false, 'error' => 'No provider'];
            if (!empty($resp['ok'])) {
                $content = (string)($resp['content'] ?? '');
                $parsed = json_decode($content, true);
                if (!is_array($parsed)) {
                    $parsed = null;
                }
                return [
                    'ok' => true,
                    'data' => $parsed,
                    'raw' => $resp['raw'] ?? null,
                    'model' => $resp['model'] ?? $model,
                ];
            }
            $last = $resp;
        }
        return ['ok' => false, 'error' => $last['error'] ?? 'AI failed'];
    }

    /**
     * Kép alapú elemzés (pl. fa egészség) – image_classification limit.
     * Támogatott: OpenAI (vision) és Mistral (Pixtral / mistral-small stb. vision modellek).
     *
     * @param string $imagePath teljes fájlút a feltöltött képhez
     * @param string $mimeType  image/jpeg, image/png, image/webp
     * @return array ['ok'=>bool, 'data'=>array|null, 'error'=>string]
     */
    /**
     * @param string|null $systemOverride Optional system message (e.g. for tree species/size analysis instead of health).
     */
    public function callWithImage(string $taskType, string $prompt, string $imagePath, string $mimeType = 'image/jpeg', ?string $systemOverride = null): array
    {
        if (!$this->isEnabled() || !$this->withinLimit('image_classification')) {
            return ['ok' => false, 'error' => 'AI disabled or image analysis limit reached'];
        }
        if (!($this->provider instanceof \OpenAIProvider) && !($this->provider instanceof \MistralProvider)) {
            return ['ok' => false, 'error' => 'Tree health analysis requires OpenAI or Mistral (vision).'];
        }
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return ['ok' => false, 'error' => 'Image file not found or not readable'];
        }
        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) {
            return ['ok' => false, 'error' => 'Failed to read image'];
        }
        $base64 = base64_encode($imageData);

        if ($this->provider instanceof \OpenAIProvider) {
            $model = (string)(function_exists('get_module_setting') ? (get_module_setting('openai', 'model') ?: '') : '');
            if ($model === '' && defined('OPENAI_MODEL')) {
                $model = (string) OPENAI_MODEL;
            }
            if ($model === '') {
                $model = 'gpt-4o-mini';
            }
        } else {
            $model = (string)(defined('AI_VISION_MODEL') ? AI_VISION_MODEL : (defined('AI_TEXT_MODEL') ? AI_TEXT_MODEL : ''));
            if ($model === '') {
                $model = 'mistral-small-latest';
            }
        }

        $system = $systemOverride ?? 'You are a tree health analyst. Reply with a JSON object only. Use keys: status (exactly one of: healthy, dry, disease_suspected), confidence (0-1), suggestion (short string).';
        $options = [
            'timeout' => 15,
            'temperature' => 0.2,
            'max_tokens' => 256,
            'system' => $system,
            'image_base64' => $base64,
            'image_mime' => $mimeType,
        ];

        $resp = $this->provider->complete($model, $prompt, $options);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'Vision API failed'];
        }
        $content = (string)($resp['content'] ?? '');
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            $parsed = null;
        }
        return [
            'ok' => true,
            'data' => $parsed,
            'raw' => $resp['raw'] ?? null,
            'model' => $resp['model'] ?? $model,
        ];
    }
}

