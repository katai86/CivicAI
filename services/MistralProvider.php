<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiProviderInterface.php';

class MistralProvider implements AiProviderInterface
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = (string)($apiKey ?? (defined('MISTRAL_API_KEY') ? (string)MISTRAL_API_KEY : ''));
    }

    public function complete(string $model, string $prompt, array $options = []): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => t('api.ai_disabled')];
        }

        $endpoint = 'https://api.mistral.ai/v1/chat/completions';
        $timeout = isset($options['timeout']) ? max(10, (int)$options['timeout']) : 30;
        $connectTimeout = min(15, (int)($options['connect_timeout'] ?? 15));
        $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.2;

        $userContent = $prompt;
        if (!empty($options['image_base64']) && is_string($options['image_base64'])) {
            $mime = isset($options['image_mime']) ? (string)$options['image_mime'] : 'image/jpeg';
            $userContent = [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => 'data:' . $mime . ';base64,' . $options['image_base64']],
            ];
        }

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => (int)($options['max_tokens'] ?? 512),
            'messages' => [
                ['role' => 'system', 'content' => $options['system'] ?? 'You are a helpful assistant for a civic issue reporting platform. Return strictly JSON.'],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];
        if (!empty($options['response_format']) && $options['response_format'] === 'json_object') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            $msg = $err ?: t('api.ai_failed');
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                $msg = t('common.error_try_later');
            } elseif ($errno === CURLE_COULDNT_CONNECT) {
                $msg = t('common.error_server');
            }
            return ['ok' => false, 'error' => $msg];
        }

        $json = json_decode($res, true);
        if (!is_array($json) || $code >= 400) {
            $msg = t('api.ai_failed');
            if (is_array($json)) {
                $detail = $json['detail'] ?? $json['message'] ?? $json['error'] ?? null;
                if (is_string($detail)) {
                    $msg = $code === 401 ? t('api.ai_disabled') : $detail;
                }
            } elseif ($code === 401) {
                $msg = t('api.ai_disabled');
            } elseif ($code >= 500) {
                $msg = t('common.error_try_later');
            }
            return ['ok' => false, 'error' => $msg];
        }

        $content = null;
        if (!empty($json['choices'][0]['message']['content'])) {
            $content = $json['choices'][0]['message']['content'];
        }

        return [
            'ok' => true,
            'model' => (string)($json['model'] ?? $model),
            'raw' => $json,
            'content' => $content,
        ];
    }
}

