<?php
/**
 * AQICN / WAQI adapter – air quality stations and AQI from map/bounds.
 * API: https://api.waqi.info/map/bounds/?latlng=minLat,minLng,maxLat,maxLng&token=TOKEN
 * Response: data[] with lat, lon, aqi, station.name (aqi can be "-" when missing).
 */
namespace CivicAI\Iot;

class AQICNAdapter extends AbstractProvider {

  private const BASE_URL = 'https://api.waqi.info';

  public function getProviderKey(): string {
    return 'aqicn';
  }

  public function isConfigured(): bool {
    $key = $this->getIotSetting('aqicn_api_key');
    return $key !== null && trim($key) !== '';
  }

  /**
   * @param array $options [ 'bbox' => [minLat, maxLat, minLng, maxLng] ]
   */
  public function fetchStations(array $options = []): array {
    if (!$this->isConfigured()) return [];
    $token = trim($this->getIotSetting('aqicn_api_key') ?? '');
    if ($token === '') return [];

    if (empty($options['bbox']) || !is_array($options['bbox']) || count($options['bbox']) < 4) {
      return [];
    }
    $minLat = (float)$options['bbox'][0];
    $maxLat = (float)$options['bbox'][1];
    $minLng = (float)$options['bbox'][2];
    $maxLng = (float)$options['bbox'][3];
    $latlng = implode(',', [$minLat, $minLng, $maxLat, $maxLng]);

    $url = self::BASE_URL . '/map/bounds/?latlng=' . rawurlencode($latlng) . '&token=' . rawurlencode($token);
    $data = $this->httpGet($url);
    if (!$data || !isset($data['data']) || !is_array($data['data'])) return [];

    $stations = [];
    foreach ($data['data'] as $d) {
      $item = $d;
      $item['metrics'] = [
        ['aqi' => $d['aqi'] ?? null, 'time' => isset($d['time']['iso']) ? (string)$d['time']['iso'] : null],
      ];
      $stations[] = $item;
    }
    return $stations;
  }

  public function fetchLatestMetrics(array $externalStationIds): array {
    return [];
  }

  public function normalizeStation($rawStation): array {
    if (!is_array($rawStation)) return [];
    $lat = isset($rawStation['lat']) ? (float)$rawStation['lat'] : null;
    $lon = isset($rawStation['lon']) ? (float)$rawStation['lon'] : null;
    $uid = isset($rawStation['uid']) ? (string)$rawStation['uid'] : null;
    $externalId = $uid !== null && $uid !== '' ? $uid : ($lat !== null && $lon !== null ? 'aqicn_' . round($lat, 4) . '_' . round($lon, 4) : null);
    if ($externalId === null) return [];

    $name = isset($rawStation['station']['name']) ? (string)$rawStation['station']['name'] : ('AQICN ' . $externalId);
    return [
      'source_provider' => $this->getProviderKey(),
      'external_station_id' => $externalId,
      'name' => $name,
      'sensor_type' => 'air_quality',
      'category' => 'aqicn',
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
      'api_source_url' => 'https://aqicn.org/',
      'is_active' => 1,
      'last_seen_at' => null,
    ];
  }

  /**
   * Normalize WAQI metrics: raw is [ ['aqi' => '45'|'-', 'time' => 'iso'] ].
   */
  public function normalizeMetrics($rawMetrics): array {
    $list = is_array($rawMetrics) && isset($rawMetrics[0]) ? $rawMetrics : (is_array($rawMetrics) ? [$rawMetrics] : []);
    $normalized = [];
    foreach ($list as $m) {
      if (!is_array($m)) continue;
      $aqiVal = $m['aqi'] ?? null;
      if ($aqiVal === null || $aqiVal === '' || $aqiVal === '-') continue;
      $num = is_numeric($aqiVal) ? (float)$aqiVal : null;
      if ($num === null) continue;
      $normalized[] = [
        'metric_key' => 'aqi',
        'metric_value' => $num,
        'metric_unit' => null,
        'measured_at' => !empty($m['time']) ? (string)$m['time'] : null,
      ];
    }
    return $normalized;
  }
}
