<?php
/**
 * WeatherXM Pro adapter – weather stations and latest observations.
 * API: https://pro.weatherxm.com/docs
 * Endpoints: GET /stations/bounds (min_lat, min_lon, max_lat, max_lon), GET /stations/{id}/latest
 * Auth: X-API-KEY header.
 */
namespace CivicAI\Iot;

class WeatherXMAdapter extends AbstractProvider {

  private const BASE_URL = 'https://pro.weatherxm.com/api/v1';

  public function getProviderKey(): string {
    return 'weatherxm';
  }

  public function isConfigured(): bool {
    $key = $this->getIotSetting('weatherxm_api_key');
    return $key !== null && trim($key) !== '';
  }

  /**
   * @param array $options [ 'bbox' => [minLat, maxLat, minLng, maxLng], 'limit' => 300 ]
   */
  public function fetchStations(array $options = []): array {
    if (!$this->isConfigured()) return [];
    $key = trim($this->getIotSetting('weatherxm_api_key') ?? '');
    if ($key === '') return [];

    if (empty($options['bbox']) || !is_array($options['bbox']) || count($options['bbox']) < 4) {
      return [];
    }
    $minLat = (float)$options['bbox'][0];
    $maxLat = (float)$options['bbox'][1];
    $minLng = (float)$options['bbox'][2];
    $maxLng = (float)$options['bbox'][3];

    $params = [
      'min_lat' => $minLat,
      'min_lon' => $minLng,
      'max_lat' => $maxLat,
      'max_lon' => $maxLng,
    ];
    $url = self::BASE_URL . '/stations/bounds?' . http_build_query($params);
    $headers = ['X-API-KEY: ' . $key];
    $data = $this->httpGet($url, $headers, 20);
    if (!$data) return [];

    $list = isset($data['stations']) && is_array($data['stations']) ? $data['stations'] : (is_array($data) && isset($data[0]) ? $data : []);
    $limit = (int)($options['limit'] ?? 300);
    return array_slice($list, 0, min(1000, max(1, $limit)));
  }

  public function fetchLatestMetrics(array $externalStationIds): array {
    if (!$this->isConfigured() || empty($externalStationIds)) return [];
    $key = trim($this->getIotSetting('weatherxm_api_key') ?? '');
    $headers = ['X-API-KEY: ' . $key];
    $out = [];
    foreach (array_slice($externalStationIds, 0, 50) as $id) {
      $id = (string)$id;
      if ($id === '') continue;
      $url = self::BASE_URL . '/stations/' . rawurlencode($id) . '/latest';
      $data = $this->httpGet($url, $headers, 15);
      if (is_array($data) && isset($data['observation'])) {
        $out[$id] = $data['observation'];
      }
    }
    return $out;
  }

  public function normalizeStation($rawStation): array {
    if (!is_array($rawStation)) return [];
    $id = isset($rawStation['id']) ? (string)$rawStation['id'] : null;
    if ($id === null || $id === '') return [];

    $lat = null;
    $lon = null;
    if (isset($rawStation['location']['lat'], $rawStation['location']['lon'])) {
      $lat = (float)$rawStation['location']['lat'];
      $lon = (float)$rawStation['location']['lon'];
    }
    $name = isset($rawStation['name']) && (string)$rawStation['name'] !== '' ? (string)$rawStation['name'] : ('WeatherXM ' . substr($id, 0, 8));
    return [
      'source_provider' => $this->getProviderKey(),
      'external_station_id' => $id,
      'name' => $name,
      'sensor_type' => 'weather',
      'category' => 'weatherxm',
      'latitude' => $lat,
      'longitude' => $lon,
      'address_or_area_name' => $name,
      'municipality' => null,
      'country' => null,
      'ownership_type' => 'external',
      'display_mode' => 'virtual_external',
      'status' => 'active',
      'trust_score' => null,
      'confidence_score' => null,
      'license_note' => null,
      'api_source_url' => 'https://pro.weatherxm.com/',
      'is_active' => 1,
      'last_seen_at' => null,
    ];
  }

  /**
   * Normalize WeatherXM observation: temperature, humidity, pressure, wind_speed, etc.
   */
  public function normalizeMetrics($rawMetrics): array {
    if (!is_array($rawMetrics)) return [];
    $obs = isset($rawMetrics['observation']) ? $rawMetrics['observation'] : $rawMetrics;
    if (!is_array($obs)) return [];

    $measuredAt = isset($obs['timestamp']) ? (string)$obs['timestamp'] : (isset($obs['created_at']) ? (string)$obs['created_at'] : null);
    $normalized = [];
    $map = [
      'temperature' => ['key' => 'temperature', 'unit' => 'celsius'],
      'feels_like' => ['key' => 'feels_like', 'unit' => 'celsius'],
      'dew_point' => ['key' => 'dew_point', 'unit' => 'celsius'],
      'humidity' => ['key' => 'humidity', 'unit' => '%'],
      'pressure' => ['key' => 'pressure', 'unit' => 'hPa'],
      'wind_speed' => ['key' => 'wind_speed', 'unit' => 'm/s'],
      'wind_gust' => ['key' => 'wind_gust', 'unit' => 'm/s'],
      'wind_direction' => ['key' => 'wind_direction', 'unit' => 'degrees'],
      'uv_index' => ['key' => 'uv_index', 'unit' => null],
      'precipitation_rate' => ['key' => 'precipitation_rate', 'unit' => 'mm/h'],
      'solar_irradiance' => ['key' => 'solar_irradiance', 'unit' => 'W/m²'],
    ];
    $normalizeTempCelsius = function (?float $value): ?float {
      if ($value === null) return null;
      // 50°C felett nem tekintjük valós Celsiusnak lakott területi mérésnél.
      // Előbb Fahrenheit, utána Kelvin fallback.
      if ($value > 50 && $value <= 180) {
        $f = ($value - 32.0) * (5.0 / 9.0);
        if ($f > -60 && $f <= 50) return $f;
      }
      if ($value > 180 && $value <= 400) {
        $k = $value - 273.15;
        if ($k > -60 && $k <= 50) return $k;
      }
      return ($value > -60 && $value <= 50) ? $value : null;
    };
    foreach ($map as $apiKey => $def) {
      if (!array_key_exists($apiKey, $obs)) continue;
      $v = $obs[$apiKey];
      if ($v === null && $obs[$apiKey] !== 0) continue;
      $num = is_numeric($v) ? (float)$v : null;
      if ($num !== null && in_array($def['key'], ['temperature', 'feels_like', 'dew_point'], true)) {
        $num = $normalizeTempCelsius($num);
      }
      if ($num !== null) {
        $normalized[] = [
          'metric_key' => $def['key'],
          'metric_value' => $num,
          'metric_unit' => $def['unit'],
          'measured_at' => $measuredAt,
        ];
      }
    }
    return $normalized;
  }
}
