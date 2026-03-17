<?php
/**
 * OpenAQ v3 adapter – air quality locations and latest measurements.
 * API: https://docs.openaq.org/ (v3 locations, location latest).
 */
namespace CivicAI\Iot;

class OpenAQAdapter extends AbstractProvider {

  private const BASE_URL = 'https://api.openaq.org/v3';

  public function getProviderKey(): string {
    return 'openaq';
  }

  public function isConfigured(): bool {
    $key = $this->getIotSetting('openaq_api_key');
    return $key !== null && trim($key) !== '';
  }

  /**
   * @param array $options [ 'bbox' => [minLat, maxLat, minLng, maxLng], 'limit' => 100, 'page' => 1 ]
   */
  public function fetchStations(array $options = []): array {
    if (!$this->isConfigured()) return [];
    $limit = (int)($options['limit'] ?? 100);
    $page = (int)($options['page'] ?? 1);
    $params = ['limit' => min(100, max(1, $limit)), 'page' => max(1, $page)];
    if (!empty($options['bbox']) && is_array($options['bbox']) && count($options['bbox']) >= 4) {
      $minLat = $options['bbox'][0];
      $maxLat = $options['bbox'][1];
      $minLng = $options['bbox'][2];
      $maxLng = $options['bbox'][3];
      $params['bbox'] = implode(',', [(float)$minLng, (float)$minLat, (float)$maxLng, (float)$maxLat]);
    }
    $url = self::BASE_URL . '/locations?' . http_build_query($params);
    $headers = ['X-API-Key: ' . trim($this->getIotSetting('openaq_api_key') ?? '')];
    $data = $this->httpGet($url, $headers);
    if (!$data || !isset($data['results']) || !is_array($data['results'])) return [];
    return $data['results'];
  }

  public function fetchLatestMetrics(array $externalStationIds): array {
    if (!$this->isConfigured() || empty($externalStationIds)) return [];
    $headers = ['X-API-Key: ' . trim($this->getIotSetting('openaq_api_key') ?? '')];
    $out = [];
    foreach ($externalStationIds as $id) {
      $url = self::BASE_URL . '/locations/' . (int)$id . '/latest';
      $data = $this->httpGet($url, $headers);
      if (is_array($data) && isset($data['results'])) {
        $out[(string)$id] = $data['results'];
      }
    }
    return $out;
  }

  public function normalizeStation($rawStation): array {
    if (!is_array($rawStation)) return [];
    $id = $rawStation['id'] ?? null;
    $lat = isset($rawStation['coordinates']['latitude']) ? (float)$rawStation['coordinates']['latitude'] : null;
    $lng = isset($rawStation['coordinates']['longitude']) ? (float)$rawStation['coordinates']['longitude'] : null;
    $country = isset($rawStation['country']['code']) ? (string)$rawStation['country']['code'] : (isset($rawStation['country']['name']) ? (string)$rawStation['country']['name'] : null);
    $locality = isset($rawStation['locality']) ? (string)$rawStation['locality'] : null;
    $name = isset($rawStation['name']) ? (string)$rawStation['name'] : ('OpenAQ #' . $id);
    return [
      'source_provider' => $this->getProviderKey(),
      'external_station_id' => (string)$id,
      'name' => $name,
      'sensor_type' => 'air_quality',
      'category' => 'openaq',
      'latitude' => $lat,
      'longitude' => $lng,
      'address_or_area_name' => $locality,
      'municipality' => $locality,
      'country' => $country,
      'ownership_type' => 'external',
      'display_mode' => 'virtual_external',
      'status' => 'active',
      'trust_score' => null,
      'confidence_score' => null,
      'license_note' => null,
      'api_source_url' => 'https://api.openaq.org/v3/locations/' . $id,
      'is_active' => 1,
      'last_seen_at' => null,
    ];
  }

  /**
   * OpenAQ latest results: array of { parameter: { name, units }, datetime, value }.
   */
  public function normalizeMetrics($rawMetrics): array {
    $list = is_array($rawMetrics) && isset($rawMetrics[0]) ? $rawMetrics : (is_array($rawMetrics) ? [$rawMetrics] : []);
    $normalized = [];
    $paramToKey = [
      'pm25' => 'pm25', 'pm2.5' => 'pm25', 'pm10' => 'pm10',
      'o3' => 'o3', 'no2' => 'no2', 'so2' => 'so2', 'co' => 'co',
      'bc' => 'bc', 'temperature' => 'temperature', 'humidity' => 'humidity',
    ];
    foreach ($list as $m) {
      if (!is_array($m)) continue;
      $param = isset($m['parameter']['name']) ? strtolower((string)$m['parameter']['name']) : '';
      $key = $paramToKey[$param] ?? $param;
      if ($key === '') continue;
      $value = isset($m['value']) ? (float)$m['value'] : null;
      $unit = isset($m['parameter']['units']) ? (string)$m['parameter']['units'] : null;
      $dt = null;
      if (isset($m['datetime']['utc'])) $dt = (string)$m['datetime']['utc'];
      elseif (isset($m['datetime']['local'])) $dt = (string)$m['datetime']['local'];
      $normalized[] = [
        'metric_key' => $key,
        'metric_value' => $value,
        'metric_unit' => $unit,
        'measured_at' => $dt,
      ];
    }
    if (!empty($normalized)) {
      $pm25 = array_filter($normalized, fn($n) => $n['metric_key'] === 'pm25');
      $pm10 = array_filter($normalized, fn($n) => $n['metric_key'] === 'pm10');
      if (!empty($pm25) || !empty($pm10)) {
        $v = !empty($pm25) ? current($pm25)['metric_value'] : (current($pm10)['metric_value'] ?? null);
        if ($v !== null) {
          $aqi = $this->pmToAqi($v);
          $normalized[] = ['metric_key' => 'aqi', 'metric_value' => $aqi, 'metric_unit' => null, 'measured_at' => current($pm25)['measured_at'] ?? current($pm10)['measured_at'] ?? null];
        }
      }
    }
    return $normalized;
  }

  private function pmToAqi(float $pm25): float {
    if ($pm25 <= 12) return round($pm25 * (50 / 12), 1);
    if ($pm25 <= 35.4) return round(50 + ($pm25 - 12) * (50 / 23.4), 1);
    if ($pm25 <= 55.4) return round(100 + ($pm25 - 35.4) * (50 / 20), 1);
    if ($pm25 <= 150.4) return round(150 + ($pm25 - 55.4) * (100 / 95), 1);
    if ($pm25 <= 250.4) return round(200 + ($pm25 - 150.4) * (100 / 100), 1);
    return round(min(500, 300 + ($pm25 - 250.4) * (200 / 249.6)), 1);
  }
}
