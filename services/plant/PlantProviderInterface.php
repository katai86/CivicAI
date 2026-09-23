<?php
/**
 * M16 – Plant & Tree Intelligence provider interfaces (cloud-only).
 */

interface PlantSpeciesProvider
{
    /** @param array<string,mixed> $context */
    public function identify(string $imagePath, array $context = []): array;

    public function isAvailable(): bool;

    public function providerMeta(): array;
}

interface PlantConditionProvider
{
    /** @param array<string,mixed> $context */
    public function extractObservations(string $imagePath, string $mime, array $context = []): array;

    public function isAvailable(): bool;

    public function providerMeta(): array;
}

interface PlantSegmentationProvider
{
    /** @param array<string,mixed> $context */
    public function estimateMeasurements(string $imagePath, string $mime, array $context = []): array;

    public function isAvailable(): bool;

    public function providerMeta(): array;
}

interface PlantExternalValidationProvider
{
    /** @param array<string,mixed> $context */
    public function validate(array $candidates, string $imagePath, array $context = []): array;

    public function isAvailable(): bool;
}

interface TextInterpretationProvider
{
    /** @param array<string,mixed> $analysis */
    public function narrate(array $analysis, string $lang = 'hu'): array;

    public function isAvailable(): bool;
}
