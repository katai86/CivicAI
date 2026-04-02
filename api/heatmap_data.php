<?php
/**
 * Urban Heatmap Engine (M1) – heatmap pontok; csak gov/admin számára (gov dashboardon).
 * GET: type, date_from, date_to, category, authority_id, minLat, maxLat, minLng, maxLng
 * Válasz: { ok, data: [ { lat, lng, weight }, ... ] }
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : 'issue_density';
$allowedTypes = ['issue_density', 'unresolved_issues', 'citizen_activity', 'tree_health_risk', 'esg_risk'];
if (!in_array($type, $allowedTypes, true)) {
  $type = 'issue_density';
}

$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : null;
$dateTo   = isset($_GET['date_to'])   ? trim((string)$_GET['date_to'])   : null;
$category = isset($_GET['category'])  ? trim((string)$_GET['category'])  : '';
$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;

// Gov user: csak a saját hatósága(i); ha nincs param, első hatóság
$role = current_user_role();
if ($role && !in_array($role, ['admin', 'superadmin'], true)) {
  $uid = current_user_id();
  if ($uid) {
    try {
      $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id LIMIT 1");
      $stmt->execute([$uid]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row && (int)$row['authority_id'] > 0) {
        if ($authorityId <= 0) $authorityId = (int)$row['authority_id'];
        else {
          $check = db()->prepare("SELECT 1 FROM authority_users WHERE user_id = ? AND authority_id = ? LIMIT 1");
          $check->execute([$uid, $authorityId]);
          if (!$check->fetch()) $authorityId = (int)$row['authority_id'];
        }
      }
    } catch (Throwable $e) { /* ignore */ }
  }
}

$minLat = isset($_GET['minLat']) && is_numeric($_GET['minLat']) ? (float)$_GET['minLat'] : null;
$maxLat = isset($_GET['maxLat']) && is_numeric($_GET['maxLat']) ? (float)$_GET['maxLat'] : null;
$minLng = isset($_GET['minLng']) && is_numeric($_GET['minLng']) ? (float)$_GET['minLng'] : null;
$maxLng = isset($_GET['maxLng']) && is_numeric($_GET['maxLng']) ? (float)$_GET['maxLng'] : null;

$limit = 2000; // max pont a válaszban (zoom-függő intenzitás: kliens decimálhat)

$out = [];
try {
  $pdo = db();
  $treeScopeIds = heatmap_tree_scope_authority_ids($pdo, $authorityId, $role);
  [$treeScopeWhere, $treeScopeParams] = gov_trees_scope_where_sql($pdo, $treeScopeIds, 't');

  // Dátum szűrés SQL
  $dateWhere = '';
  $dateParams = [];
  if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateWhere .= " AND r.created_at >= ?";
    $dateParams[] = $dateFrom . ' 00:00:00';
  }
  if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateWhere .= " AND r.created_at <= ?";
    $dateParams[] = $dateTo . ' 23:59:59';
  }

  $authWhere = '';
  $authParams = [];
  if ($authorityId > 0) {
    $authWhere = " AND r.authority_id = ?";
    $authParams[] = $authorityId;
  }

  $bboxWhere = '';
  $bboxParams = [];
  if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
    $bboxWhere = " AND r.lat BETWEEN ? AND ? AND r.lng BETWEEN ? AND ?";
    $bboxParams = [$minLat, $maxLat, $minLng, $maxLng];
  }

  $catWhere = '';
  $catParams = [];
  if ($category !== '' && $category !== 'all') {
    $catWhere = " AND r.category = ?";
    $catParams[] = $category;
  }

  switch ($type) {
    case 'issue_density':
      // Minden (approved stb.) report pont, weight = 1
      $sql = "SELECT r.lat AS lat, r.lng AS lng, 1 AS w FROM reports r WHERE 1=1 AND r.lat IS NOT NULL AND r.lng IS NOT NULL $dateWhere $authWhere $catWhere $bboxWhere ORDER BY r.created_at DESC LIMIT $limit";
      $params = array_merge($dateParams, $authParams, $catParams, $bboxParams);
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => (float)($row['w'] ?? 1)];
      }
      break;

    case 'unresolved_issues':
      $statusWhere = " AND r.status NOT IN ('solved','closed','rejected')";
      $sql = "SELECT r.lat AS lat, r.lng AS lng, 1 AS w FROM reports r WHERE 1=1 AND r.lat IS NOT NULL AND r.lng IS NOT NULL $statusWhere $dateWhere $authWhere $catWhere $bboxWhere ORDER BY r.created_at DESC LIMIT $limit";
      $params = array_merge($dateParams, $authParams, $catParams, $bboxParams);
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => (float)($row['w'] ?? 1)];
      }
      break;

    case 'citizen_activity':
      // Report pontok + öntözési események (fa koordináta)
      $sql = "SELECT r.lat AS lat, r.lng AS lng, 1 AS w FROM reports r WHERE 1=1 AND r.lat IS NOT NULL AND r.lng IS NOT NULL $dateWhere $authWhere $catWhere $bboxWhere ORDER BY r.created_at DESC LIMIT " . (int)($limit / 2);
      $params = array_merge($dateParams, $authParams, $catParams, $bboxParams);
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => 1.0];
      }
      $twDateWhere = str_replace('r.created_at', 'tw.created_at', $dateWhere);
      $twDateParams = $dateParams;
      $sql2 = "SELECT t.lat AS lat, t.lng AS lng, 0.5 AS w FROM tree_watering_logs tw JOIN trees t ON t.id = tw.tree_id WHERE ($treeScopeWhere) AND t.lat IS NOT NULL AND t.lng IS NOT NULL $twDateWhere LIMIT " . (int)($limit / 2);
      try {
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute(array_merge($treeScopeParams, $twDateParams));
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
          $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => 0.5];
        }
      } catch (Throwable $e) { /* tree_watering_logs lehet nincs */ }
      break;

    case 'tree_health_risk':
      $tBbox = '';
      $tBboxParams = [];
      if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
        $tBbox = " AND t.lat BETWEEN ? AND ? AND t.lng BETWEEN ? AND ?";
        $tBboxParams = [$minLat, $maxLat, $minLng, $maxLng];
      }
      $sql = "SELECT t.lat AS lat, t.lng AS lng,
              CASE WHEN t.risk_level = 'high' THEN 2.0 WHEN t.risk_level = 'medium' THEN 1.2 ELSE 0.5 END AS w
              FROM trees t WHERE ($treeScopeWhere) AND t.lat IS NOT NULL AND t.lng IS NOT NULL $tBbox LIMIT $limit";
      try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($treeScopeParams, $tBboxParams));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => (float)$row['w']];
        }
      } catch (Throwable $e) {
        // trees tábla lehet más név vagy nincs
      }
      break;

    case 'esg_risk':
      // Egyszerű kombináció: megoldatlan reportok + magas kockázatú fák
      $statusWhere = " AND r.status NOT IN ('solved','closed','rejected')";
      $sql = "SELECT r.lat AS lat, r.lng AS lng, 1 AS w FROM reports r WHERE 1=1 AND r.lat IS NOT NULL AND r.lng IS NOT NULL $statusWhere $dateWhere $authWhere $catWhere $bboxWhere LIMIT " . (int)($limit / 2);
      $params = array_merge($dateParams, $authParams, $catParams, $bboxParams);
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => 1.0];
      }
      try {
        $tBbox = '';
        $tBboxParams = [];
        if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
          $tBbox = " AND t.lat BETWEEN ? AND ? AND t.lng BETWEEN ? AND ?";
          $tBboxParams = [$minLat, $maxLat, $minLng, $maxLng];
        }
        $sql2 = "SELECT t.lat AS lat, t.lng AS lng, 1.5 AS w FROM trees t WHERE ($treeScopeWhere) AND t.risk_level = 'high' AND t.lat IS NOT NULL AND t.lng IS NOT NULL $tBbox LIMIT " . (int)($limit / 2);
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute(array_merge($treeScopeParams, $tBboxParams));
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
          $out[] = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'weight' => 1.5];
        }
      } catch (Throwable $e) {}
      break;
  }

} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('heatmap_data: ' . $e->getMessage());
  }
  json_response(['ok' => false, 'error' => function_exists('t') ? t('common.error_load') : 'Betöltési hiba']);
}

json_response(['ok' => true, 'data' => $out]);
