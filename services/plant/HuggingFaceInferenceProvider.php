<?php
/**
 * M17 alt – HuggingFace Inference API for species / vision models.
 */
require_once __DIR__ . '/PlantProviderInterface.php';
require_once __DIR__ . '/../ExternalHttpClient.php';

final class HuggingFaceInferenceProvider implements PlantSpeciesProvider
{
    public function isAvailable(): bool
    {
        return plant_tree_enabled() && huggingface_api_key() !== '';
    }

    public function providerMeta(): array
    {
        $model = (string)(get_module_setting('plant_tree', 'huggingface_model') ?: 'google/vit-base-patch16-224');
        return ['provider' => 'huggingface', 'model' => $model, 'version' => 'inference-api'];
    }

    /** @param array<string,mixed> $context */
    public function identify(string $imagePath, array $context = []): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'failure_state' => 'MODEL_UNAVAILABLE', 'candidates' => [], 'error' => 'huggingface_not_configured'];
        }
        $model = (string)($context['model'] ?? get_module_setting('plant_tree', 'huggingface_model') ?: 'google/vit-base-patch16-224');
        $url = 'https://api-inference.huggingface.co/models/' . $model;
        $bytes = @file_get_contents($imagePath);
        if ($bytes === false) {
            return ['ok' => false, 'failure_state' => 'INSUFFICIENT_IMAGE', 'candidates' => [], 'error' => 'file_read_failed'];
        }
        $resp = ExternalHttpClient::postRaw($url, $bytes, [
            'Authorization: Bearer ' . huggingface_api_key(),
            'Content-Type: application/octet-stream',
        ], 60);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'candidates' => [], 'error' => $resp['error'] ?? 'hf_http_error'];
        }
        $data = json_decode($resp['body'] ?? '', true);
        $candidates = [];
        if (is_array($data)) {
            foreach (array_slice($data, 0, 5) as $r) {
                if (!is_array($r)) continue;
                $label = (string)($r['label'] ?? '');
                $score = isset($r['score']) ? round((float)$r['score'], 4) : 0.0;
                $candidates[] = [
                    'scientific_name' => $label,
                    'common_names' => [],
                    'family' => '',
                    'score' => $score,
                    'gbif_id' => null,
                ];
            }
        }
        return ['ok' => true, 'failure_state' => null, 'candidates' => $candidates, 'raw' => $data, 'meta' => $this->providerMeta()];
    }
}
