<?php
/**
 * City Brain dashboard – összesítő adatok a Live, Environmental és Risk tabokhoz.
 * GET: authority_id (opcionális). Válasz: ok, live { sensors_summary, reports_24h, ideas_24h, open_reports },
 * environmental { summary, by_provider }, risks [ { type, severity, message, since } ].
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/PrioritizationEngine.php';
require_once __DIR__ . '/../services/UrbanPredictionEngine.php';
require_once __DIR__ . '/../services/GreenIntelligence.php';

start_secure_session();
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$authorityIds = [];
$authorityCities = [];
$requestedAid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;

if ($isAdmin) {
  if ($requestedAid > 0) {
    $authorityIds = [$requestedAid];
  } else {
    try {
      $authorityIds = array_map('intval', db()->query("SELECT id FROM authorities ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
  }
} else {
  try {
    $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ?");
    $stmt->execute([$uid]);
    $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($requestedAid > 0 && !in_array($requestedAid, $authorityIds, true)) {
      json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    if ($requestedAid > 0) $authorityIds = [$requestedAid];
  } catch (Throwable $e) {}
}

$cities = [];
$bounds = [];
try {
  $stmt = db()->prepare("SELECT id, city, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ?");
  foreach ($authorityIds as $aid) {
    $stmt->execute([$aid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      if (!empty(trim($row['city'] ?? ''))) $cities[] = trim($row['city']);
      if (isset($row['min_lat'], $row['max_lat'], $row['min_lng'], $row['max_lng']) &&
          $row['min_lat'] !== null && $row['max_lat'] !== null && $row['min_lng'] !== null && $row['max_lng'] !== null) {
        $bounds[] = [(float)$row['min_lat'], (float)$row['max_lat'], (float)$row['min_lng'], (float)$row['max_lng']];
      }
    }
  }
} catch (Throwable $e) {}

list($where, $params) = virtual_sensors_scope_for_authority($cities, $bounds);

$pdo = db();
$sensorsSummary = ['total' => 0, 'active' => 0, 'stale_count' => 0, 'avg_aqi' => null, 'avg_pm25' => null, 'avg_temperature' => null];
$byProvider = [];
$sensorRows = [];
$staleSeconds = 24 * 3600;
$now = time();

try {
  $pdo->query("SELECT 1 FROM virtual_sensors LIMIT 1");
} catch (Throwable $e) {
  json_response([
    'ok' => true,
    'live' => build_live($sensorsSummary, 0, 0, 0),
    'environmental' => ['summary' => $sensorsSummary, 'by_provider' => $byProvider, 'green' => null],
    'risks' => [],
    'meta' => ['risk_method' => 'heuristic', 'authority_id' => null],
  ]);
}

$sql = "SELECT vs.id, vs.source_provider, vs.last_seen_at FROM virtual_sensors vs WHERE $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sensorRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sensorIds = array_map(function ($r) { return (int)$r['id']; }, $sensorRows);
$sensorsSummary['total'] = count($sensorRows);

foreach ($sensorRows as $r) {
  $p = (string)($r['source_provider'] ?? 'other');
  $byProvider[$p] = ($byProvider[$p] ?? 0) + 1;
  if ($r['last_seen_at'] && strtotime($r['last_seen_at']) < $now - $staleSeconds) {
    $sensorsSummary['stale_count']++;
  }
}
$sensorsSummary['active'] = $sensorsSummary['total'] - $sensorsSummary['stale_count'];

if (!empty($sensorIds)) {
  $in = implode(',', array_fill(0, count($sensorIds), '?'));
  $mStmt = $pdo->prepare("SELECT virtual_sensor_id, metric_key, metric_value, metric_unit FROM virtual_sensor_metrics_latest WHERE virtual_sensor_id IN ($in)");
  $mStmt->execute($sensorIds);
  $aqi = []; $pm25 = []; $temp = [];
  $normalizeTempCelsius = function (?float $value, ?string $unit): ?float {
    if ($value === null) return null;
    $u = strtolower(trim((string)($unit ?? '')));
    if ($u === 'fahrenheit' || $u === 'degf' || $u === 'f' || strpos($u, 'fahrenheit') !== false || strpos($u, 'degf') !== false) {
      $value = ($value - 32.0) * (5.0 / 9.0);
      return ($value > -60 && $value <= 50) ? $value : null;
    }
    if ($u === 'kelvin' || $u === 'k' || $u === 'degk' || strpos($u, 'kelvin') !== false || strpos($u, 'degk') !== false) {
      $value = $value - 273.15;
      return ($value > -60 && $value <= 50) ? $value : null;
    }
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
  while ($row = $mStmt->fetch(PDO::FETCH_ASSOC)) {
    $v = $row['metric_value'] !== null ? (float)$row['metric_value'] : null;
    if ($v === null) continue;
    if ($row['metric_key'] === 'aqi') $aqi[] = $v;
    if ($row['metric_key'] === 'pm25') $pm25[] = $v;
    if (in_array($row['metric_key'], ['temperature', 'temp'], true)) {
      $tv = $normalizeTempCelsius($v, $row['metric_unit'] ?? null);
      if ($tv !== null) $temp[] = $tv;
    }
  }
  $avg = function ($arr) {
    if (empty($arr)) return null;
    return round(array_sum($arr) / count($arr), 1);
  };
  $sensorsSummary['avg_aqi'] = $avg($aqi);
  $sensorsSummary['avg_pm25'] = $avg($pm25);
  $sensorsSummary['avg_temperature'] = $avg($temp);
}

// Reports: authority scope (authority_id IN + city IN for null authority_id)
$reportWhere = '1=0';
$reportParams = [];
$reportParts = [];
if (!empty($authorityIds)) {
  $reportParts[] = "r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
  $reportParams = array_merge($reportParams, $authorityIds);
}
if (!empty($cities)) {
  $reportParts[] = "(r.authority_id IS NULL AND r.city IN (" . implode(',', array_fill(0, count($cities), '?')) . "))";
  $reportParams = array_merge($reportParams, $cities);
}
if (!empty($reportParts)) {
  $reportWhere = '(' . implode(' OR ', $reportParts) . ')';
}

$reports_24h = 0;
$ideas_24h = 0;
$open_reports = 0;
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.created_at >= (NOW() - INTERVAL 24 HOUR)");
  $stmt->execute($reportParams);
  $reports_24h = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.status NOT IN ('solved','closed')");
  $stmt->execute($reportParams);
  $open_reports = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
try {
  if (!empty($authorityIds)) {
    $in = implode(',', array_fill(0, count($authorityIds), '?'));
    $stIdeas = $pdo->prepare("SELECT COUNT(*) FROM ideas WHERE authority_id IN ($in) AND created_at >= (NOW() - INTERVAL 24 HOUR)");
    $stIdeas->execute($authorityIds);
    $ideas_24h = (int)$stIdeas->fetchColumn();
  }
} catch (Throwable $e) {}

$primaryAid = !empty($authorityIds) ? (int)$authorityIds[0] : null;
if ($requestedAid > 0) {
  $primaryAid = $requestedAid;
}

$risks = [];
$riskMethod = 'heuristic';

// Sensor thresholds (honest heuristic)
if ($sensorsSummary['avg_aqi'] !== null && $sensorsSummary['avg_aqi'] > 100) {
  $risks[] = [
    'type' => 'aqi',
    'severity' => 'high',
    'source' => 'sensor_threshold',
    'message' => 'avg_aqi_high',
    'message_params' => ['value' => $sensorsSummary['avg_aqi']],
    'detail' => 'AQI ' . $sensorsSummary['avg_aqi'],
    'since' => date('Y-m-d H:i'),
  ];
}
if ($sensorsSummary['stale_count'] > 0) {
  $risks[] = [
    'type' => 'stale_sensors',
    'severity' => 'medium',
    'source' => 'sensor_threshold',
    'message' => 'stale_sensors',
    'message_params' => ['count' => $sensorsSummary['stale_count']],
    'detail' => (string)$sensorsSummary['stale_count'],
    'since' => null,
  ];
}

// Prioritization engine → intervention priorities
try {
  if ($reportWhere !== '1=0') {
    $prio = (new PrioritizationEngine())->compute($pdo, $reportWhere, $reportParams, $primaryAid);
    foreach (array_slice($prio['by_category'] ?? [], 0, 5) as $it) {
      $score = (float)($it['priority_score'] ?? 0);
      $openCnt = (int)($it['open_count'] ?? 0);
      if ($openCnt <= 0) {
        continue;
      }
      $sev = $score >= 80 ? 'high' : ($score >= 40 ? 'medium' : 'low');
      $risks[] = [
        'type' => 'backlog_priority',
        'severity' => $sev,
        'source' => 'prioritization_engine',
        'message' => 'backlog_category',
        'message_params' => [
          'category' => (string)($it['category'] ?? ''),
          'open' => $openCnt,
          'avg_age' => (float)($it['avg_age_days'] ?? 0),
          'score' => $score,
        ],
        'detail' => ($it['category'] ?? '') . ' · open ' . $openCnt . ' · score ' . $score,
        'since' => null,
      ];
    }
    foreach (array_slice($prio['by_zone'] ?? [], 0, 3) as $z) {
      $zc = (int)($z['open_count'] ?? 0);
      if ($zc < 3) {
        continue;
      }
      $risks[] = [
        'type' => 'zone_concentration',
        'severity' => $zc >= 10 ? 'high' : 'medium',
        'source' => 'prioritization_engine',
        'message' => 'zone_open',
        'message_params' => [
          'zone' => (string)($z['zone'] ?? ''),
          'open' => $zc,
        ],
        'detail' => ($z['zone'] ?? '') . ': ' . $zc,
        'since' => null,
      ];
    }
  }
} catch (Throwable $e) {}

// Spatial prediction clusters
try {
  if ($reportWhere !== '1=0') {
    $pred = (new UrbanPredictionEngine())->predict($reportWhere, $reportParams, []);
    $highClusters = 0;
    foreach ($pred['predicted_issues'] ?? [] as $iss) {
      if (($iss['risk_level'] ?? '') === 'high') {
        $highClusters++;
      }
    }
    if ($highClusters > 0) {
      $risks[] = [
        'type' => 'spatial_cluster',
        'severity' => 'high',
        'source' => 'prediction_engine',
        'message' => 'high_clusters',
        'message_params' => ['count' => $highClusters],
        'detail' => (string)$highClusters,
        'since' => null,
      ];
    }
    $treeRisk = count($pred['predicted_tree_failures'] ?? []);
    if ($treeRisk >= 3) {
      $risks[] = [
        'type' => 'tree_risk',
        'severity' => $treeRisk >= 10 ? 'high' : 'medium',
        'source' => 'prediction_engine',
        'message' => 'tree_risk_candidates',
        'message_params' => ['count' => $treeRisk],
        'detail' => (string)$treeRisk,
        'since' => null,
      ];
    }
  }
} catch (Throwable $e) {}

// Severity sort: high first
usort($risks, static function ($a, $b) {
  $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
  return ($rank[$a['severity'] ?? 'low'] ?? 3) <=> ($rank[$b['severity'] ?? 'low'] ?? 3);
});
$risks = array_slice($risks, 0, 12);

$greenBlock = null;
try {
  $g = (new GreenIntelligence())->compute($primaryAid);
  $greenBlock = [
    'canopy_coverage_pct' => round((float)($g['canopy_coverage'] ?? 0) * 100, 1),
    'carbon_absorption_t' => round((float)($g['carbon_absorption'] ?? 0), 1),
    'drought_risk_pct' => round((float)($g['drought_risk'] ?? 0) * 100, 0),
    'biodiversity_index_pct' => round((float)($g['biodiversity_index'] ?? 0) * 100, 0),
    'source' => 'green_intelligence',
  ];
} catch (Throwable $e) {
  $greenBlock = null;
}

function build_live($sensorsSummary, $reports_24h, $ideas_24h, $open_reports) {
  return [
    'sensors_summary' => $sensorsSummary,
    'reports_24h' => $reports_24h,
    'ideas_24h' => $ideas_24h,
    'open_reports' => $open_reports,
  ];
}

json_response([
  'ok' => true,
  'live' => build_live($sensorsSummary, $reports_24h, $ideas_24h, $open_reports),
  'environmental' => [
    'summary' => $sensorsSummary,
    'by_provider' => $byProvider,
    'green' => $greenBlock,
  ],
  'risks' => $risks,
  'meta' => [
    'risk_method' => $riskMethod,
    'authority_id' => $primaryAid,
  ],
]);
