<?php
/**
 * M7 – ESG Command Center API.
 * GET, csak gov/admin. E/S/G szekciók: environmental (tree_coverage, heat_island_index, water_stress),
 * social (citizen_participation, volunteer_engagement), governance (response_transparency, resolution_rate).
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
$authorityIds = [];
$authorityCities = [];

if (in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  if ($aid > 0) {
    $authorityIds = [$aid];
  } else {
    try {
      $rows = db()->query("SELECT id FROM authorities ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
      $authorityIds = array_map('intval', $rows);
    } catch (Throwable $e) {}
  }
} else {
  if ($uid > 0) {
    try {
      $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id");
      $stmt->execute([$uid]);
      $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
      if (!empty($authorityIds)) {
        $stmt = db()->prepare("SELECT city FROM authorities WHERE id = ?");
        foreach ($authorityIds as $aid) {
          $stmt->execute([$aid]);
          $city = $stmt->fetchColumn();
          if ($city) $authorityCities[] = $city;
        }
        $authorityCities = array_values(array_unique(array_filter($authorityCities)));
      }
    } catch (Throwable $e) {}
  }
}

$baseWhere = '1=1';
$baseParams = [];
if (in_array($role, ['admin', 'superadmin'], true)) {
  if (!empty($authorityIds)) {
    $baseWhere = "r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
    $baseParams = $authorityIds;
  }
} else {
  if (empty($authorityIds)) {
    $baseWhere = '1=0';
  } else {
    $baseWhere = "r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
    $baseParams = $authorityIds;
    if (!empty($authorityCities)) {
      $baseWhere .= " OR (r.authority_id IS NULL AND r.city IN (" . implode(',', array_fill(0, count($authorityCities), '?')) . "))";
      $baseParams = array_merge($baseParams, $authorityCities);
    }
  }
}

$authorityId = !empty($authorityIds) ? (int)$authorityIds[0] : null;

$pdo = db();
$out = [
  'environmental' => [ 'tree_coverage' => 0.0, 'heat_island_index' => 0.5, 'water_stress' => 0.0 ],
  'social' => [ 'citizen_participation' => 0.0, 'volunteer_engagement' => 0.0 ],
  'governance' => [ 'response_transparency' => 0.0, 'resolution_rate' => 0.0 ],
];

try {
  $green = new GreenIntelligence();
  $g = $green->compute($authorityId);
  $out['environmental']['tree_coverage'] = round((float)($g['canopy_coverage'] ?? 0), 2);
  $out['environmental']['heat_island_index'] = round(1.0 - $out['environmental']['tree_coverage'], 2);
  $out['environmental']['water_stress'] = round((float)($g['drought_risk'] ?? 0), 2);
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $baseWhere AND r.user_id IS NOT NULL AND r.user_id > 0 AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
  $stmt->execute($baseParams);
  $active7 = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
  $stmt->execute($baseParams);
  $reports7 = (int)$stmt->fetchColumn();
  $participation = min(1.0, ($active7 / 20.0) * 0.5 + ($reports7 / 30.0) * 0.5);
  $out['social']['citizen_participation'] = round($participation, 2);
} catch (Throwable $e) {}

try {
  $adopters = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM tree_adoptions WHERE status = 'active'")->fetchColumn();
  $watering = (int)$pdo->query("SELECT COUNT(*) FROM tree_watering_logs WHERE created_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
  $volunteer = min(1.0, ($adopters / 50.0) * 0.5 + ($watering / 100.0) * 0.5);
  $out['social']['volunteer_engagement'] = round($volunteer, 2);
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere");
  $stmt->execute($baseParams);
  $totalReports = (int)$stmt->fetchColumn();
  $withLog = 0;
  $resolved = 0;
  if ($totalReports > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.id) FROM reports r INNER JOIN report_status_log l ON l.report_id = r.id WHERE $baseWhere");
    $stmt->execute($baseParams);
    $withLog = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.status IN ('solved','closed')");
    $stmt->execute($baseParams);
    $resolved = (int)$stmt->fetchColumn();
  }
  $out['governance']['response_transparency'] = $totalReports > 0 ? round($withLog / $totalReports, 2) : 0.0;
  $out['governance']['resolution_rate'] = $totalReports > 0 ? round($resolved / $totalReports, 2) : 0.0;
} catch (Throwable $e) {}

try {
  json_response(['ok' => true, 'data' => $out]);
} catch (Throwable $e) {
  if (function_exists('log_error')) log_error('esg_metrics: ' . $e->getMessage());
  json_response(['ok' => false, 'error' => t('common.error_load')]);
}
