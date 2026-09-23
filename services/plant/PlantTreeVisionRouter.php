<?php
/**
 * M15 – Plant & Tree vision pipeline router (cloud-only).
 */
require_once __DIR__ . '/PlantProviderInterface.php';
require_once __DIR__ . '/ImageQualityGate.php';
require_once __DIR__ . '/PlantNetSpeciesProvider.php';
require_once __DIR__ . '/HuggingFaceInferenceProvider.php';
require_once __DIR__ . '/CloudVisionConditionProvider.php';
require_once __DIR__ . '/SegmentationEstimateProvider.php';
require_once __DIR__ . '/TreeHealthEngine.php';
require_once __DIR__ . '/PublicRiskEngine.php';
require_once __DIR__ . '/PlantVisionFusionService.php';
require_once __DIR__ . '/TreeInspectionStore.php';
require_once __DIR__ . '/PlantTreeCiBridge.php';

final class PlantTreeVisionRouter
{
    private PlantNetSpeciesProvider $plantNet;
    private HuggingFaceInferenceProvider $huggingFace;
    private CloudVisionConditionProvider $condition;
    private SegmentationEstimateProvider $segmentation;

    public function __construct()
    {
        $this->plantNet = new PlantNetSpeciesProvider();
        $this->huggingFace = new HuggingFaceInferenceProvider();
        $this->condition = new CloudVisionConditionProvider();
        $this->segmentation = new SegmentationEstimateProvider();
    }

    /**
     * @param array<string,mixed> $context tree_id, authority_id, lat, lng, lang, species, plant_part, session_id
     * @return array<string,mixed>
     */
    public function analyze(string $imagePath, string $mime, array $context = []): array
    {
        $quality = ImageQualityGate::check($imagePath);
        if (!$quality['ok']) {
            return $this->fail($quality['failure_state'] ?? 'INSUFFICIENT_IMAGE', ['quality' => $quality]);
        }

        $speciesProvider = $this->resolveSpeciesProvider();
        $speciesResult = ['ok' => false, 'failure_state' => 'MODEL_UNAVAILABLE', 'candidates' => []];
        if ($speciesProvider !== null) {
            $speciesResult = $speciesProvider->identify($imagePath, $context + ['mime' => $mime]);
        }

        $conditionResult = $this->condition->extractObservations($imagePath, $mime, $context);
        $segmentResult = ['ok' => false, 'measurements' => []];
        if ($this->segmentation->isAvailable()) {
            $segmentResult = $this->segmentation->estimateMeasurements($imagePath, $mime, $context);
        }

        $fusion = PlantVisionFusionService::fuse($speciesResult, $conditionResult);
        $observations = is_array($conditionResult['observations'] ?? null) ? $conditionResult['observations'] : [];
        $health = TreeHealthEngine::compute($observations, $context['species'] ?? null);
        $risk = PublicRiskEngine::compute($observations, (float)$health['health_score']);

        $analysis = [
            'status' => $fusion['status'],
            'quality' => $quality,
            'species' => $speciesResult,
            'species_consensus' => $fusion['species_consensus'] ?? null,
            'condition' => $conditionResult,
            'segmentation' => $segmentResult,
            'health' => $health,
            'risk' => $risk,
            'fusion' => $fusion,
            'guidance' => $conditionResult['guidance'] ?? '',
            'pipeline_version' => 'plant-cloud-1.0',
        ];

        $store = new TreeInspectionStore();
        $inspectionId = $store->saveInspection($imagePath, $mime, $analysis, $context);

        $ciBridge = new PlantTreeCiBridge();
        $ciResult = $ciBridge->ingest($analysis, $context, $inspectionId);

        return [
            'ok' => in_array($fusion['status'], ['SUCCESS', 'PARTIAL_SUCCESS', 'LOW_CONFIDENCE', 'CONFLICTED_IDENTIFICATION'], true),
            'analysis' => $analysis,
            'inspection_id' => $inspectionId,
            'city_intel' => $ciResult,
        ];
    }

    /** @return array<string,mixed> */
    public function providerHealth(): array
    {
        return [
            'plant_tree_enabled' => plant_tree_enabled(),
            'species_provider' => plant_tree_species_provider(),
            'plantnet' => $this->plantNet->isAvailable(),
            'huggingface' => $this->huggingFace->isAvailable(),
            'cloud_vision' => $this->condition->isAvailable(),
            'segmentation' => $this->segmentation->isAvailable(),
            'mistral' => function_exists('mistral_api_key') && mistral_api_key() !== '',
            'openai' => function_exists('openai_api_key') && openai_api_key() !== '',
        ];
    }

    private function resolveSpeciesProvider(): ?PlantSpeciesProvider
    {
        $choice = plant_tree_species_provider();
        if ($choice === 'huggingface' && $this->huggingFace->isAvailable()) {
            return $this->huggingFace;
        }
        if ($this->plantNet->isAvailable()) {
            return $this->plantNet;
        }
        if ($this->huggingFace->isAvailable()) {
            return $this->huggingFace;
        }
        return null;
    }

    /** @param array<string,mixed> $extra */
    private function fail(string $state, array $extra = []): array
    {
        return array_merge(['ok' => false, 'status' => $state, 'analysis' => null, 'inspection_id' => null], $extra);
    }
}
