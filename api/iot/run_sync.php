<?php
/**
 * Közös IoT sync logika – hívja a cron_iot_sync.php és az admin_iot_sync.php.
 * Feltételezés: IoT enabled, táblák léteznek (a hívó ellenőrzi).
 */
require_once __DIR__ . '/ProviderRegistry.php';

use CivicAI\Iot\ProviderRegistry;

/** Convert ISO 8601 or any parseable datetime to MySQL datetime (Y-m-d H:i:s). */
function _iot_normalize_datetime(?string $value): ?string {
  if ($value === null || trim($value) === '') return null;
  $ts = strtotime($value);
  return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}

function run_iot_sync(): array {
  $db = db();
  $authorityCities = [];
  $allBounds = [];
  try {
    $rows = $db->query("SELECT city, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE city IS NOT NULL AND TRIM(city) <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $city = trim($r['city'] ?? '');
      if ($city !== '') $authorityCities[] = ['name' => $city, 'country' => 'HU'];
      if (isset($r['min_lat'], $r['max_lat'], $r['min_lng'], $r['max_lng']) &&
          $r['min_lat'] !== null && $r['max_lat'] !== null && $r['min_lng'] !== null && $r['max_lng'] !== null) {
        $allBounds[] = [(float)$r['min_lat'], (float)$r['max_lat'], (float)$r['min_lng'], (float)$r['max_lng']];
      }
    }
    if (!empty($allBounds)) {
      $bbox = [
        min(array_column($allBounds, 0)),
        max(array_column($allBounds, 1)),
        min(array_column($allBounds, 2)),
        max(array_column($allBounds, 3)),
      ];
    } else {
      $bbox = [46.5, 48.6, 16.0, 23.0];
    }
  } catch (Throwable $e) {
    $bbox = [46.5, 48.6, 16.0, 23.0];
  }

  $maxStations = (int)get_module_setting('iot', 'iot_max_stations_per_city') ?: 300;
  $maxStations = min(1000, max(10, $maxStations));

  $adapters = ProviderRegistry::getConfiguredAdapters();
  $results = [];

  foreach ($adapters as $providerKey => $adapter) {
    $started = date('Y-m-d H:i:s');
    $imported = 0;
    $updated = 0;
    $errors = 0;
    $logMsg = '';

    try {
      $options = ['limit' => $maxStations];
      if ($providerKey === 'openaq' || $providerKey === 'aqicn' || $providerKey === 'weatherxm') {
        $options['bbox'] = $bbox;
      }
      if ($providerKey === 'openweather') {
        $options['cities'] = array_slice($authorityCities, 0, 50);
        if (empty($options['cities'])) {
          $options['coords'] = [[$bbox[0] + ($bbox[1] - $bbox[0]) / 2, $bbox[2] + ($bbox[3] - $bbox[2]) / 2]];
          $options['names'] = ['Default'];
        }
      }

      $rawStations = $adapter->fetchStations($options);
      $extIdToVsId = [];

      foreach ($rawStations as $raw) {
        $row = $adapter->normalizeStation($raw);
        if (empty($row['source_provider']) || empty($row['external_station_id'])) continue;

        $lat = $row['latitude'];
        $lng = $row['longitude'];
        $name = $row['name'] ?? null;
        $sensorType = $row['sensor_type'] ?? null;
        $category = $row['category'] ?? null;
        $address = $row['address_or_area_name'] ?? null;
        $municipality = $row['municipality'] ?? null;
        $country = $row['country'] ?? null;
        $apiUrl = $row['api_source_url'] ?? null;

        $sql = "INSERT INTO virtual_sensors (source_provider, external_station_id, name, sensor_type, category, latitude, longitude, address_or_area_name, municipality, country, ownership_type, display_mode, status, is_active, api_source_url, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'external', 'virtual_external', 'active', 1, ?, NULL)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), name=VALUES(name), sensor_type=VALUES(sensor_type), category=VALUES(category), latitude=VALUES(latitude), longitude=VALUES(longitude), address_or_area_name=VALUES(address_or_area_name), municipality=VALUES(municipality), country=VALUES(country), api_source_url=VALUES(api_source_url)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$row['source_provider'], $row['external_station_id'], $name, $sensorType, $category, $lat, $lng, $address, $municipality, $country, $apiUrl]);
        $vsId = (int)$db->lastInsertId();
        if ($vsId > 0) {
          $extIdToVsId[$row['external_station_id']] = $vsId;
          $imported++;
        }

        if (isset($raw['metrics']) && is_array($raw['metrics'])) {
          $metrics = $adapter->normalizeMetrics($raw['metrics']);
          $latestMeasured = null;
          foreach ($metrics as $m) {
            $key = $m['metric_key'] ?? '';
            if ($key === '') continue;
            $val = isset($m['metric_value']) ? (float)$m['metric_value'] : null;
            $unit = $m['metric_unit'] ?? null;
            $measuredAt = _iot_normalize_datetime(!empty($m['measured_at']) ? $m['measured_at'] : null);
            if ($measuredAt && ($latestMeasured === null || strtotime($measuredAt) > strtotime($latestMeasured))) $latestMeasured = $measuredAt;
            try {
              $db->prepare("INSERT INTO virtual_sensor_metrics_latest (virtual_sensor_id, metric_key, metric_value, metric_unit, measured_at) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE metric_value=VALUES(metric_value), metric_unit=VALUES(metric_unit), measured_at=VALUES(measured_at)")
                ->execute([$vsId, $key, $val, $unit, $measuredAt]);
              $updated++;
            } catch (Throwable $e) { $errors++; }
          }
          if ($latestMeasured) {
            $db->prepare("UPDATE virtual_sensors SET last_seen_at = ? WHERE id = ?")->execute([$latestMeasured, $vsId]);
          }
        }
      }

      $extIdsForFetch = in_array($providerKey, ['openweather', 'aqicn'], true) ? [] : array_keys($extIdToVsId);

      if (empty($extIdsForFetch)) {
        $finished = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO virtual_sensor_provider_logs (provider_name, sync_started_at, sync_finished_at, status, imported_count, updated_count, error_count, log_message) VALUES (?, ?, ?, 'ok', ?, ?, ?, ?)")
          ->execute([$providerKey, $started, $finished, $imported, 0, $errors, $logMsg ?: null]);
        $results[$providerKey] = ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
        continue;
      }

      $chunkSize = ($providerKey === 'openaq' || $providerKey === 'weatherxm') ? 10 : 20;
      $latestByExt = [];
      foreach (array_chunk($extIdsForFetch, $chunkSize) as $chunk) {
        $latestByExt = array_merge($latestByExt, $adapter->fetchLatestMetrics($chunk));
      }

      foreach ($latestByExt as $extId => $rawMetrics) {
        $vsId = $extIdToVsId[$extId] ?? null;
        if ($vsId === null) continue;

        $metrics = $adapter->normalizeMetrics($rawMetrics);
        if ($providerKey === 'openweather' && is_array($rawMetrics) && isset($rawMetrics[0])) {
          $metrics = $adapter->normalizeMetrics($rawMetrics[0]);
        }

        $sensorLatest = null;
        foreach ($metrics as $m) {
          $key = $m['metric_key'] ?? '';
          if ($key === '') continue;
          $val = isset($m['metric_value']) ? (float)$m['metric_value'] : null;
          $unit = $m['metric_unit'] ?? null;
          $measuredAt = _iot_normalize_datetime(!empty($m['measured_at']) ? $m['measured_at'] : null);
          if ($measuredAt && ($sensorLatest === null || strtotime($measuredAt) > strtotime($sensorLatest))) $sensorLatest = $measuredAt;
          try {
            $db->prepare("INSERT INTO virtual_sensor_metrics_latest (virtual_sensor_id, metric_key, metric_value, metric_unit, measured_at) VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE metric_value=VALUES(metric_value), metric_unit=VALUES(metric_unit), measured_at=VALUES(measured_at)")
              ->execute([$vsId, $key, $val, $unit, $measuredAt]);
            $updated++;
          } catch (Throwable $e) {
            $errors++;
          }
        }
        if ($sensorLatest) {
          $db->prepare("UPDATE virtual_sensors SET last_seen_at = ? WHERE id = ?")->execute([$sensorLatest, $vsId]);
        }
      }

      $finished = date('Y-m-d H:i:s');
      $db->prepare("INSERT INTO virtual_sensor_provider_logs (provider_name, sync_started_at, sync_finished_at, status, imported_count, updated_count, error_count, log_message) VALUES (?, ?, ?, 'ok', ?, ?, ?, ?)")
        ->execute([$providerKey, $started, $finished, $imported, $updated, $errors, $logMsg ?: null]);
      $results[$providerKey] = ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];

    } catch (Throwable $e) {
      $logMsg = $e->getMessage();
      $errors++;
      $finished = date('Y-m-d H:i:s');
      try {
        $db->prepare("INSERT INTO virtual_sensor_provider_logs (provider_name, sync_started_at, sync_finished_at, status, imported_count, updated_count, error_count, log_message) VALUES (?, ?, ?, 'error', ?, ?, ?, ?)")
          ->execute([$providerKey, $started, $finished, $imported, $updated, $errors, $logMsg]);
      } catch (Throwable $e2) {}
      $results[$providerKey] = ['imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'error' => $logMsg];
    }
  }

  return ['ok' => true, 'providers' => $results];
}
