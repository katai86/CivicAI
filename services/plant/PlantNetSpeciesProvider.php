<?php
/**
 * M17 – PlantNet API species identification.
 */
require_once __DIR__ . '/PlantProviderInterface.php';
require_once __DIR__ . '/../ExternalHttpClient.php';

final class PlantNetSpeciesProvider implements PlantSpeciesProvider
{
    private const API_BASE = 'https://my-api.plantnet.org/v2/identify';
    private const DEFAULT_PROJECT = 'all';

    public function isAvailable(): bool
    {
        return plant_tree_enabled() && plantnet_api_key() !== '';
    }

    public function providerMeta(): array
    {
        return ['provider' => 'plantnet', 'model' => 'plantnet-v2', 'version' => '2'];
    }

    /** @param array<string,mixed> $context */
    public function identify(string $imagePath, array $context = []): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'failure_state' => 'MODEL_UNAVAILABLE', 'candidates' => [], 'error' => 'plantnet_not_configured'];
        }
        $key = plantnet_api_key();
        $project = (string)($context['project'] ?? get_module_setting('plant_tree', 'plantnet_project') ?: self::DEFAULT_PROJECT);
        $url = self::API_BASE . '/' . rawurlencode($project) . '?api-key=' . rawurlencode($key);

        $mime = $context['mime'] ?? 'image/jpeg';
        if (!is_file($imagePath)) {
            return ['ok' => false, 'failure_state' => 'INSUFFICIENT_IMAGE', 'candidates' => [], 'error' => 'file_missing'];
        }
        $cfile = new CURLFile($imagePath, $mime, basename($imagePath));
        $post = ['images' => $cfile, 'organs' => $context['organ'] ?? 'auto'];

        $resp = ExternalHttpClient::postMultipart($url, $post, 45);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'candidates' => [], 'error' => $resp['error'] ?? 'plantnet_http_error'];
        }
        $data = json_decode($resp['body'] ?? '', true);
        if (!is_array($data)) {
            return ['ok' => false, 'failure_state' => 'PROCESSING_FAILED', 'candidates' => [], 'error' => 'invalid_json'];
        }
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        $candidates = [];
        foreach (array_slice($results, 0, 5) as $r) {
            $sp = is_array($r['species'] ?? null) ? $r['species'] : [];
            $candidates[] = [
                'scientific_name' => (string)($sp['scientificNameWithoutAuthor'] ?? $sp['scientificName'] ?? ''),
                'common_names' => is_array($sp['commonNames'] ?? null) ? $sp['commonNames'] : [],
                'family' => (string)($sp['family']['scientificNameWithoutAuthor'] ?? ''),
                'score' => isset($r['score']) ? round((float)$r['score'], 4) : 0.0,
                'gbif_id' => $sp['gbif']['id'] ?? null,
            ];
        }
        return ['ok' => true, 'failure_state' => null, 'candidates' => $candidates, 'raw' => $data, 'meta' => $this->providerMeta()];
    }
}
