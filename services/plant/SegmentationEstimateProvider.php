<?php
/**
 * M21 – Segmentation estimates via cloud vision (ESTIMATED measurements).
 */
require_once __DIR__ . '/PlantProviderInterface.php';
require_once __DIR__ . '/../AiRouter.php';

final class SegmentationEstimateProvider implements PlantSegmentationProvider
{
    public function isAvailable(): bool
    {
        return function_exists('ai_configured') && ai_configured();
    }

    public function providerMeta(): array
    {
        return ['provider' => 'cloud_vision_estimate', 'model' => 'pixtral/gpt4o', 'version' => 'm21-est'];
    }

    /** @param array<string,mixed> $context */
    public function estimateMeasurements(string $imagePath, string $mime, array $context = []): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'failure_state' => 'MODEL_UNAVAILABLE', 'measurements' => [], 'error' => 'vision_not_configured'];
        }
        $prompt = 'Estimate tree/plant measurements from this photo. Return ONLY JSON: {"measurements":[{"key":"trunk_diameter_cm","value":number|null,"confidence":0-1,"method":"estimated"},{"key":"canopy_diameter_m","value":number|null,"confidence":0-1,"method":"estimated"},{"key":"height_m","value":number|null,"confidence":0-1,"method":"estimated"}],"segmentation_note":"text describing visible regions"}. Mark method as estimated. Null if not visible.';

        $router = new AiRouter();
        $resp = $router->callWithImage('image_classification', $prompt, $imagePath, $mime);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'measurements' => [], 'error' => $resp['error'] ?? 'vision_failed'];
        }
        $raw = trim((string)($resp['content'] ?? $resp['text'] ?? ''));
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'measurements' => [], 'error' => 'invalid_json'];
        }
        return [
            'ok' => true,
            'failure_state' => null,
            'measurements' => is_array($data['measurements'] ?? null) ? $data['measurements'] : [],
            'segmentation_note' => (string)($data['segmentation_note'] ?? ''),
            'measurement_type' => 'ESTIMATED',
            'meta' => $this->providerMeta(),
        ];
    }
}
