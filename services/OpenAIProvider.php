<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiProviderInterface.php';

/**
 * OpenAI (ChatGPT) provider – ugyanaz a limitek (get_ai_limit) mint Mistral.
 */
class OpenAIProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = (string)($apiKey ?? (function_exists('openai_api_key') ? openai_api_key() : ''));
        if ($this->apiKey === '' && defined('OPENAI_API_KEY')) {
            $this->apiKey = (string) OPENAI_API_KEY;
        }
        $this->model = (string)($model ?? (function_exists('get_module_setting') ? (get_module_setting('openai', 'model') ?: '') : ''));
        if ($this->model === '' && defined('OPENAI_MODEL')) {
            $this->model = (string) OPENAI_MODEL;
        }
        if ($this->model === '') {
            $this->model = 'gpt-4o-mini';
        }
    }

    public function complete(string $model, string $prompt, array $options = []): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => 'OpenAI API key missing'];
        }

        $useModel = $model !== '' ? $model : $this->model;
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 6;
        $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.2;

        $userContent = $prompt;
        if (!empty($options['image_base64']) && is_string($options['image_base64'])) {
            $mime = isset($options['image_mime']) ? (string)$options['image_mime'] : 'image/jpeg';
            $userContent = [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $options['image_base64']]],
            ];
        }

        $payload = [
            'model' => $useModel,
            'temperature' => $temperature,
            'max_tokens' => $options['max_tokens'] ?? 512,
            'messages' => [
                ['role' => 'system', 'content' => $options['system'] ?? 'You are a helpful assistant for a civic issue reporting platform. Return strictly JSON.'],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['ok' => false, 'error' => $err ?: 'OpenAI request failed'];
        }

        $json = json_decode($res, true);
        if (!is_array($json) || $code >= 400) {
            $msg = 'OpenAI API hiba.';
            if (is_array($json)) {
                $errObj = $json['error'] ?? null;
                $detail = is_array($errObj) ? ($errObj['message'] ?? $errObj['code'] ?? null) : (is_string($errObj) ? $errObj : null);
                if (is_string($detail)) {
                    $msg = $code === 401 ? 'OpenAI API kulcs érvénytelen vagy hiányzik.' : $detail;
                }
            } elseif ($code === 401) {
                $msg = 'OpenAI API kulcs érvénytelen vagy hiányzik.';
            } elseif ($code >= 500) {
                $msg = 'OpenAI szolgáltatás átmenetileg nem elérhető.';
            }
            return ['ok' => false, 'error' => $msg];
        }

        $content = null;
        if (!empty($json['choices'][0]['message']['content'])) {
            $content = $json['choices'][0]['message']['content'];
        }

        return [
            'ok' => true,
            'model' => (string)($json['model'] ?? $useModel),
            'raw' => $json,
            'content' => $content,
        ];
    }
}
