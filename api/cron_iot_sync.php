<?php
/**
 * IoT virtual sensors sync – station discovery and latest metrics from configured providers.
 * Call via cron (e.g. every hour). Optional: ?token=ADMIN_TOKEN for auth.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if (defined('ADMIN_TOKEN') && ADMIN_TOKEN !== '') {
  $token = $_GET['token'] ?? '';
  if (!hash_equals((string)ADMIN_TOKEN, (string)$token)) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
}

if (get_module_setting('iot', 'enabled') !== '1') {
  json_response(['ok' => true, 'message' => 'IoT module disabled', 'providers' => []]);
}

require_once __DIR__ . '/iot/ProviderRegistry.php';

use CivicAI\Iot\ProviderRegistry;

$db = db();

$tablesOk = false;
try {
  $db->query("SELECT 1 FROM virtual_sensors LIMIT 1");
  $db->query("SELECT 1 FROM virtual_sensor_metrics_latest LIMIT 1");
  $db->query("SELECT 1 FROM virtual_sensor_provider_logs LIMIT 1");
  $tablesOk = true;
} catch (Throwable $e) {}

if (!$tablesOk) {
  json_response(['ok' => false, 'error' => 'IoT tables missing']);
}

$authorityCities = [];
$bbox = null;
try {
  $rows = $db->query("SELECT city, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE city IS NOT NULL AND TRIM(city) <> ''")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $city = trim($r['city'] ?? '');
    if ($city !== '') $authorityCities[] = ['name' => $city, 'country' => 'HU'];
    if ($bbox === null && isset($r['min_lat'], $r['max_lat'], $r['min_lng'], $r['max_lng']) &&
        $r['min_lat'] !== null && $r['max_lat'] !== null && $r['min_lng'] !== null && $r['max_lng'] !== null) {
      $bbox = [(float)$r['min_lat'], (float)$r['max_lat'], (float)$r['min_lng'], (float)$r['max_lng']];
    }
  }
  if ($bbox === null) {
    $bbox = [47.0, 48.0, 19.0, 23.0];
  }
} catch (Throwable $e) {
  $bbox = [47.0, 48.0, 19.0, 23.0];
}

$maxStations = (int)get_module_setting('iot', 'iot_max_stations_per_city') ?: 100;
$maxStations = min(200, max(10, $maxStations));

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
    if ($providerKey === 'openaq') {
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
          $measuredAt = !empty($m['measured_at']) ? $m['measured_at'] : null;
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

    $extIdsForFetch = ($providerKey === 'openweather') ? [] : array_keys($extIdToVsId);

    if (empty($extIdsForFetch)) {
      $finished = date('Y-m-d H:i:s');
      $db->prepare("INSERT INTO virtual_sensor_provider_logs (provider_name, sync_started_at, sync_finished_at, status, imported_count, updated_count, error_count, log_message) VALUES (?, ?, ?, 'ok', ?, ?, ?, ?)")
        ->execute([$providerKey, $started, $finished, $imported, 0, $errors, $logMsg ?: null]);
      $results[$providerKey] = ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
      continue;
    }

    $chunkSize = $providerKey === 'openaq' ? 10 : 20;
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
        $measuredAt = !empty($m['measured_at']) ? $m['measured_at'] : null;
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

json_response(['ok' => true, 'providers' => $results]);
