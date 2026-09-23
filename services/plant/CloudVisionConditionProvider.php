<?php
/**
 * M20 – Cloud vision structured condition observations (Pixtral / GPT-4o).
 */
require_once __DIR__ . '/PlantProviderInterface.php';
require_once __DIR__ . '/../AiRouter.php';
require_once __DIR__ . '/../AiPromptBuilder.php';

final class CloudVisionConditionProvider implements PlantConditionProvider
{
    public function isAvailable(): bool
    {
        return function_exists('ai_configured') && ai_configured();
    }

    public function providerMeta(): array
    {
        return ['provider' => 'cloud_vision', 'model' => 'pixtral/gpt4o', 'version' => 'm20'];
    }

    /** @param array<string,mixed> $context */
    public function extractObservations(string $imagePath, string $mime, array $context = []): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'failure_state' => 'MODEL_UNAVAILABLE', 'observations' => [], 'error' => 'vision_not_configured'];
        }
        $lang = (string)($context['lang'] ?? (function_exists('current_lang') ? current_lang() : 'hu'));
        $langName = AiPromptBuilder::languageNameForCode($lang);
        $species = trim((string)($context['species'] ?? ''));
        $part = trim((string)($context['plant_part'] ?? 'whole_tree'));

        $prompt = <<<PROMPT
Analyze this plant/tree photo. Return ONLY valid JSON (no markdown):
{
  "observations": [
    {"signal": "leaf_color", "value": "green|yellow|brown|mixed", "severity": "none|mild|moderate|severe", "confidence": 0.0-1.0},
    {"signal": "dryness", "value": "none|mild|moderate|severe", "severity": "none|mild|moderate|severe", "confidence": 0.0-1.0},
    {"signal": "disease_signs", "value": "none|suspected|visible", "severity": "none|mild|moderate|severe", "confidence": 0.0-1.0},
    {"signal": "damage", "value": "none|minor|major", "severity": "none|mild|moderate|severe", "confidence": 0.0-1.0},
    {"signal": "canopy_density", "value": "sparse|normal|dense", "severity": "none|mild|moderate|severe", "confidence": 0.0-1.0}
  ],
  "overall_stress": "none|mild|moderate|severe",
  "guidance": "one short sentence in {$langName} for better photo if needed, or empty string"
}
Plant part: {$part}. Known species: {$species}. Do NOT invent species name. Do NOT assign health scores.
PROMPT;

        $router = new AiRouter();
        $resp = $router->callWithImage('image_classification', $prompt, $imagePath, $mime);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'observations' => [], 'error' => $resp['error'] ?? 'vision_failed'];
        }
        $parsed = $this->parseJson((string)($resp['content'] ?? $resp['text'] ?? ''));
        if ($parsed === null) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'observations' => [], 'error' => 'invalid_json'];
        }
        $obs = is_array($parsed['observations'] ?? null) ? $parsed['observations'] : [];
        return [
            'ok' => true,
            'failure_state' => null,
            'observations' => $obs,
            'overall_stress' => (string)($parsed['overall_stress'] ?? 'none'),
            'guidance' => (string)($parsed['guidance'] ?? ''),
            'meta' => $this->providerMeta(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
