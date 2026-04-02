<?php
/**
 * Interface for virtual sensor (IoT) data providers.
 * Adapters fetch stations and latest metrics from external APIs and normalize to CivicAI schema.
 */
namespace CivicAI\Iot;

interface VirtualSensorProviderInterface {

  /**
   * Provider key (e.g. openaq, openweather).
   */
  public function getProviderKey(): string;

  /**
   * Whether this provider is configured (API key etc.).
   */
  public function isConfigured(): bool;

  /**
   * Fetch stations/locations from the provider.
   * @param array $options e.g. [ 'bbox' => [minLat, maxLat, minLng, maxLng], 'limit' => 100, 'municipality' => 'City' ]
   * @return array List of raw station/location objects from the provider
   */
  public function fetchStations(array $options = []): array;

  /**
   * Fetch latest measurements for given external station IDs (provider-specific IDs).
   * @param array $externalStationIds e.g. [ '123', '456' ] (OpenAQ location IDs)
   * @return array Map external_station_id => list of raw measurement objects
   */
  public function fetchLatestMetrics(array $externalStationIds): array;

  /**
   * Normalize one raw station to virtual_sensors row format.
   * @param mixed $rawStation One item from fetchStations()
   * @return array Keys: source_provider, external_station_id, name, sensor_type, category, latitude, longitude, address_or_area_name, municipality, country, ...
   */
  public function normalizeStation($rawStation): array;

  /**
   * Normalize raw metrics to virtual_sensor_metrics_latest format.
   * @param mixed $rawMetrics One item or list from fetchLatestMetrics()
   * @return array List of [ 'metric_key' => string, 'metric_value' => float|null, 'metric_unit' => string|null, 'measured_at' => string|null ]
   */
  public function normalizeMetrics($rawMetrics): array;
}
