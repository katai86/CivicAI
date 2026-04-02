<?php
/**
 * Urban ESG kártya (Elemzés fül) – JSON snapshot hatósági scope-pal.
 * GET: authority_id (opcionális, admin). Gov: saját hatóság(ok).
 * Válasz: ok, data: { environment, social, governance } (governance.reports_total benne).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role();
$uid = current_user_id() ? (int)current_user_id() : 0;
$authorityIds = [];
$authorityCities = [];

if ($role && in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  if ($aid > 0) {
    $authorityIds = [$aid];
  } else {
    try {
      $rows = db()->query('SELECT id FROM authorities ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
      $authorityIds = array_map('intval', $rows ?: []);
    } catch (Throwable $e) {
      $authorityIds = [];
    }
  }
} elseif ($uid > 0) {
  try {
    $stmt = db()->prepare('SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id');
    $stmt->execute([$uid]);
    $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    // Gov dashboard: első hatóság (ugyanúgy, mint gov/index.php)
    if (!empty($authorityIds)) {
      $authorityIds = [$authorityIds[0]];
      $authorityCities = [];
      $stmt = db()->prepare('SELECT city FROM authorities WHERE id = ?');
      $stmt->execute([$authorityIds[0]]);
      $city = $stmt->fetchColumn();
      if ($city) {
        $authorityCities = [trim((string)$city)];
      }
    }
  } catch (Throwable $e) {
    $authorityIds = [];
  }
}

$baseWhere = '1=0';
$baseParams = [];
if (in_array($role, ['admin', 'superadmin'], true)) {
  $reqAid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  if ($reqAid > 0) {
    $baseWhere = 'r.authority_id = ?';
    $baseParams = [$reqAid];
  } else {
    $baseWhere = '1=1';
    $baseParams = [];
  }
} elseif (empty($authorityIds)) {
  $baseWhere = '1=0';
} else {
  $ph = implode(',', array_fill(0, count($authorityIds), '?'));
  $baseWhere = "r.authority_id IN ($ph)";
  $baseParams = $authorityIds;
  if (!empty($authorityCities)) {
    $baseWhere .= ' OR (r.authority_id IS NULL AND r.city IN (' . implode(',', array_fill(0, count($authorityCities), '?')) . '))';
    $baseParams = array_merge($baseParams, $authorityCities);
  }
}

$treeScopeIds = array_values(array_filter(array_map('intval', $authorityIds), static fn ($x) => $x > 0));
if (in_array($role, ['admin', 'superadmin'], true) && isset($_GET['authority_id']) && (int)$_GET['authority_id'] > 0) {
  $treeScopeIds = [(int)$_GET['authority_id']];
}

$pdo = db();
$snap = gov_compute_esg_snapshot($pdo, $treeScopeIds, $baseWhere, $baseParams);
try {
  $q0 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere");
  $q0->execute($baseParams);
  $snap['governance']['reports_total'] = (int)$q0->fetchColumn();
} catch (Throwable $e) {
  $snap['governance']['reports_total'] = 0;
}

json_response([
  'ok' => true,
  'data' => [
    'environment' => $snap['environment'],
    'social' => $snap['social'],
    'governance' => $snap['governance'],
  ],
]);
