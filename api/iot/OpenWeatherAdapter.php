<?php
/**
 * OpenWeatherMap adapter – current weather by city/coords as virtual stations.
 * One logical "station" per city/location. Uses Current Weather API.
 */
namespace CivicAI\Iot;

class OpenWeatherAdapter extends AbstractProvider {

  private const BASE_URL = 'https://api.openweathermap.org/data/2.5/weather';

  public function getProviderKey(): string {
    return 'openweather';
  }

  public function isConfigured(): bool {
    $key = $this->getIotSetting('openweather_api_key');
    return $key !== null && trim($key) !== '';
  }

  /**
   * @param array $options [ 'cities' => [ ['name' => 'Orosháza', 'country' => 'HU'], ... ], 'limit' => 50 ]
   *   or [ 'coords' => [ [lat, lng], ... ] ] with optional 'names' => [ 'Station 1', ... ]
   */
  public function fetchStations(array $options = []): array {
    if (!$this->isConfigured()) return [];
    $stations = [];
    $cities = $options['cities'] ?? [];
    $coords = $options['coords'] ?? [];
    $names = $options['names'] ?? [];
    $limit = (int)($options['limit'] ?? 50);

    foreach (array_slice($cities, 0, $limit) as $i => $c) {
      $name = is_array($c) ? ($c['name'] ?? '') : (string)$c;
      $country = is_array($c) ? ($c['country'] ?? '') : '';
      if ($name === '') continue;
      $q = $name . ($country !== '' ? ',' . $country : '');
      $data = $this->fetchCurrentWeather(['q' => $q]);
      if ($data) {
        $stations[] = [
          'raw_type' => 'city',
          'name' => $data['name'] ?? $name,
          'country' => $data['sys']['country'] ?? $country,
          'lat' => $data['coord']['lat'] ?? null,
          'lon' => $data['coord']['lon'] ?? null,
          'id' => 'city:' . $q,
          'metrics' => $data,
        ];
      }
    }

    foreach (array_slice($coords, 0, $limit) as $j => $coord) {
      $lat = isset($coord[0]) ? (float)$coord[0] : null;
      $lng = isset($coord[1]) ? (float)$coord[1] : null;
      if ($lat === null || $lng === null) continue;
      $data = $this->fetchCurrentWeather(['lat' => $lat, 'lon' => $lng]);
      if ($data) {
        $label = $names[$j] ?? ($data['name'] ?? "{$lat},{$lng}");
        $stations[] = [
          'raw_type' => 'coord',
          'name' => $label,
          'country' => $data['sys']['country'] ?? null,
          'lat' => $lat,
          'lon' => $lng,
          'id' => 'coord:' . $lat . ',' . $lng,
          'metrics' => $data,
        ];
      }
    }

    return $stations;
  }

  /**
   * Re-fetch current weather by external_station_id (city:name,country or coord:lat,lng).
   */
  public function fetchLatestMetrics(array $externalStationIds): array {
    if (!$this->isConfigured() || empty($externalStationIds)) return [];
    $out = [];
    foreach ($externalStationIds as $extId) {
      $data = null;
      if (strpos($extId, 'coord:') === 0) {
        $pair = substr($extId, 6);
        $parts = explode(',', $pair, 2);
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
          $data = $this->fetchCurrentWeather(['lat' => (float)$parts[0], 'lon' => (float)$parts[1]]);
        }
      } elseif (strpos($extId, 'city:') === 0) {
        $q = substr($extId, 5);
        $data = $this->fetchCurrentWeather(['q' => $q]);
      }
      if ($data) {
        $out[$extId] = [$data];
      }
    }
    return $out;
  }

  private function fetchCurrentWeather(array $params): ?array {
    $key = trim($this->getIotSetting('openweather_api_key') ?? '');
    $params['appid'] = $key;
    $params['units'] = 'metric';
    $url = self::BASE_URL . '?' . http_build_query($params);
    return $this->httpGet($url, [], 10);
  }

  public function normalizeStation($rawStation): array {
    if (!is_array($rawStation)) return [];
    $id = $rawStation['id'] ?? ('openweather:' . ($rawStation['name'] ?? '') . ($rawStation['lat'] ?? '') . ($rawStation['lon'] ?? ''));
    $lat = $rawStation['lat'] ?? null;
    $lng = $rawStation['lon'] ?? null;
    return [
      'source_provider' => $this->getProviderKey(),
      'external_station_id' => (string)$id,
      'name' => (string)($rawStation['name'] ?? 'OpenWeather'),
      'sensor_type' => 'weather',
      'category' => 'openweather',
      'latitude' => $lat !== null ? (float)$lat : null,
      'longitude' => $lng !== null ? (float)$lng : null,
      'address_or_area_name' => $rawStation['name'] ?? null,
      'municipality' => $rawStation['name'] ?? null,
      'country' => isset($rawStation['country']) ? (string)$rawStation['country'] : null,
      'ownership_type' => 'external',
      'display_mode' => 'virtual_external',
      'status' => 'active',
      'trust_score' => null,
      'confidence_score' => null,
      'license_note' => null,
      'api_source_url' => null,
      'is_active' => 1,
      'last_seen_at' => null,
    ];
  }

  /**
   * rawStation may contain 'metrics' (OpenWeather current response); or rawMetrics is that response.
   */
  public function normalizeMetrics($rawMetrics): array {
    $m = is_array($rawMetrics) && isset($rawMetrics['main']) ? $rawMetrics : (is_array($rawMetrics) && isset($rawMetrics[0]['main']) ? $rawMetrics[0] : null);
    if (!$m || !isset($m['main'])) return [];
    $main = $m['main'];
    $normalized = [];
    $ts = isset($m['dt']) ? date('Y-m-d H:i:s', (int)$m['dt']) : null;
    if (isset($main['temp'])) {
      $normalized[] = ['metric_key' => 'temperature', 'metric_value' => (float)$main['temp'], 'metric_unit' => 'celsius', 'measured_at' => $ts];
    }
    if (isset($main['humidity'])) {
      $normalized[] = ['metric_key' => 'humidity', 'metric_value' => (float)$main['humidity'], 'metric_unit' => '%', 'measured_at' => $ts];
    }
    if (isset($main['pressure'])) {
      $normalized[] = ['metric_key' => 'pressure', 'metric_value' => (float)$main['pressure'], 'metric_unit' => 'hPa', 'measured_at' => $ts];
    }
    if (isset($m['wind']['speed'])) {
      $normalized[] = ['metric_key' => 'wind_speed', 'metric_value' => (float)$m['wind']['speed'], 'metric_unit' => 'm/s', 'measured_at' => $ts];
    }
    return $normalized;
  }
}
