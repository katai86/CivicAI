<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiProviderInterface.php';

/**
 * Placeholder Gemini provider – jelenleg opcionális / kikapcsolható.
 * A konkrét HTTP hívás implementációja később finomhangolható.
 */
class GeminiProvider implements AiProviderInterface
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey !== null ? $apiKey : (defined('GEMINI_API_KEY') ? (string)GEMINI_API_KEY : '');
    }

    public function complete(string $model, string $prompt, array $options = []): array
    {
        if ($this->apiKey === '' || !AI_ENABLED || (defined('AI_PROVIDER') && AI_PROVIDER !== 'gemini')) {
            return ['ok' => false, 'error' => 'Gemini disabled'];
        }

        // Minimális, JSON választ váró generatív hívás – később részletezhető.
        // Itt inkább egy biztonságos stubot adunk vissza.
        return ['ok' => false, 'error' => 'Gemini provider not fully implemented'];
    }
}

