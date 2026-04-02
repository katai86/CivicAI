<?php
/**
 * M6 – Green Intelligence API.
 * GET, csak gov/admin. authority_id opcionális.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/GreenIntelligence.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$role = current_user_role();
$uid = current_user_id() ? (int)current_user_id() : 0;
$authorityId = null;

if (in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $authorityId = $aid > 0 ? $aid : null;
} else {
  if ($uid > 0) {
    try {
      $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id LIMIT 1");
      $stmt->execute([$uid]);
      $row = $stmt->fetch(PDO::FETCH_COLUMN);
      $authorityId = $row !== false ? (int)$row : null;
    } catch (Throwable $e) {}
  }
}

try {
  $service = new GreenIntelligence();
  $data = $service->compute($authorityId);
  $euNotes = [];
  if (!empty($data['eu_notes']) && is_array($data['eu_notes'])) {
    $euNotes = $data['eu_notes'];
    unset($data['eu_notes']);
  }
  $sources = $data['data_sources'] ?? [];
  $hasStac = in_array('copernicus_stac_sentinel2_l2a', $sources, true);
  $hasOAuth = in_array('copernicus_cdse_oauth', $sources, true);
  $hasClmsUa = in_array('clms_urban_atlas_2018_eea', $sources, true);
  $confidence = 'low';
  if ($hasStac && $hasOAuth) {
    $confidence = 'high';
  } elseif ($hasStac || $hasOAuth) {
    $confidence = 'medium';
  }
  if ($hasClmsUa) {
    if ($confidence === 'low') {
      $confidence = 'medium';
    }
    if ($confidence === 'medium' && $hasStac) {
      $confidence = 'high';
    }
  }
  $bbox = null;
  if ($authorityId !== null && $authorityId > 0) {
    try {
      $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
      $st->execute([$authorityId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row && $row['min_lat'] !== null) {
        $bbox = [
          'min_lat' => (float)$row['min_lat'],
          'max_lat' => (float)$row['max_lat'],
          'min_lng' => (float)$row['min_lng'],
          'max_lng' => (float)$row['max_lng'],
        ];
      }
    } catch (Throwable $e) {}
  }
  json_response([
    'ok' => true,
    'source' => (function () use ($sources) {
      $c = in_array('copernicus_stac_sentinel2_l2a', $sources, true) || in_array('copernicus_cdse_oauth', $sources, true);
      $u = in_array('clms_urban_atlas_2018_eea', $sources, true);
      if ($c && $u) {
        return 'eu_mixed';
      }
      if ($c) {
        return 'copernicus';
      }
      if ($u) {
        return 'clms';
      }
      return 'local';
    })(),
    'scope' => [
      'authority_id' => $authorityId,
      'bbox' => $bbox,
      'reference_period' => gmdate('Y-m'),
    ],
    'data' => $data,
    'meta' => [
      'fetched_at' => gmdate('c'),
      'cached' => false,
      'confidence' => $confidence,
      'notes' => $euNotes,
      'data_sources' => $sources,
    ],
  ]);
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('green_metrics: ' . $e->getMessage());
  }
  json_response(['ok' => false, 'error' => t('common.error_load')]);
}
