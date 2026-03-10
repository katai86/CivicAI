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
            return ['ok' => false, 'error' => 'Mistral API key missing'];
        }

        $endpoint = 'https://api.mistral.ai/v1/chat/completions';
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 6;
        $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.2;

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $options['max_tokens'] ?? 512,
            'messages' => [
                ['role' => 'system', 'content' => $options['system'] ?? 'You are a helpful assistant for a civic issue reporting platform. Return strictly JSON.'],
                ['role' => 'user', 'content' => $prompt],
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
            return ['ok' => false, 'error' => $err ?: 'Mistral request failed'];
        }

        $json = json_decode($res, true);
        if (!is_array($json) || $code >= 400) {
            $msg = 'Mistral API hiba.';
            if (is_array($json)) {
                $detail = $json['detail'] ?? $json['message'] ?? $json['error'] ?? null;
                if (is_string($detail)) {
                    $msg = $code === 401 ? 'Mistral API kulcs érvénytelen vagy hiányzik.' : $detail;
                }
            } elseif ($code === 401) {
                $msg = 'Mistral API kulcs érvénytelen vagy hiányzik.';
            } elseif ($code >= 500) {
                $msg = 'Mistral szolgáltatás átmenetileg nem elérhető.';
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

