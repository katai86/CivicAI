<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/MistralProvider.php';
require_once __DIR__ . '/GeminiProvider.php';

/**
 * AiRouter – provider választás, retry, cost-control (alap).
 */
class AiRouter
{
    private ?AiProviderInterface $provider = null;

    public function __construct()
    {
        $moduleMistral = function_exists('get_module_setting') && get_module_setting('mistral', 'enabled') === '1' && ((string)(get_module_setting('mistral', 'api_key') ?? '')) !== '';
        $envEnabled = defined('AI_ENABLED') && AI_ENABLED;
        if (!$envEnabled && !$moduleMistral) {
            $this->provider = null;
            return;
        }
        $prov = defined('AI_PROVIDER') ? (string)AI_PROVIDER : 'mistral';
        if ($prov === 'gemini') {
        $this->provider = new GeminiProvider();
        } else {
            $this->provider = new MistralProvider(function_exists('mistral_api_key') ? mistral_api_key() : null);
        }
    }

    public function isEnabled(): bool
    {
        return $this->provider !== null;
    }

    /**
     * Egyszerű rate limit: ai_results alapján számol, nap/ task_type szerint.
     */
    private function withinLimit(string $taskType): bool
    {
        if ($taskType === 'report_classification') {
            $max = AI_MAX_REPORTS_PER_DAY ?: 0;
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
        if (in_array($taskType, ['admin_summary','gov_summary'], true)) {
            $max = AI_SUMMARY_LIMIT ?: 0;
            if ($max <= 0) return false;
            try {
                $stmt = db()->prepare("SELECT COUNT(*) FROM ai_results WHERE task_type IN ('admin_summary','gov_summary') AND created_at >= CURDATE()");
                $stmt->execute();
                $cnt = (int)$stmt->fetchColumn();
                return $cnt < $max;
            } catch (Throwable $e) {
                return false;
            }
        }
        if ($taskType === 'image_classification') {
            $max = AI_IMAGE_ANALYSIS_LIMIT ?: 0;
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

        $model = $meta['model'] ?? (string)(defined('AI_TEXT_MODEL') ? AI_TEXT_MODEL : '');
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
}

