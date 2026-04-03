<?php
/**
 * M5 – Composite green + ESG dashboard (single round-trip for analytics UI).
 * GET, gov/admin. authority_id opcionális (admin): egy hatóság vagy összes.
 * Válasz: esg snapshot + GreenIntelligence + egyszerű pulse score (0–100).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/GreenIntelligence.php';

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

$authorityIdForGreen = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $reqAid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $authorityIdForGreen = $reqAid > 0 ? $reqAid : null;
} elseif (!empty($treeScopeIds)) {
  $authorityIdForGreen = (int)$treeScopeIds[0];
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

$green = [
  'canopy_coverage' => 0.0,
  'carbon_absorption' => 0.0,
  'biodiversity_index' => 0.0,
  'drought_risk' => 0.0,
];
try {
  $gi = new GreenIntelligence();
  $computed = $gi->compute($authorityIdForGreen);
  if (is_array($computed)) {
    $green = array_merge($green, $computed);
  }
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('green_dashboard GreenIntelligence: ' . $e->getMessage());
  }
}

$canopy = min(1.0, max(0.0, (float)($green['canopy_coverage'] ?? 0)));
$drought = min(1.0, max(0.0, (float)($green['drought_risk'] ?? 0)));
$bio = min(1.0, max(0.0, (float)($green['biodiversity_index'] ?? 0)));
$activeCit = (int)($snap['social']['active_citizens_30d'] ?? 0);
$participation = min(1.0, $activeCit / 80.0);

$pulse = (int)round(100 * (
  0.28 * $canopy +
  0.28 * (1.0 - $drought) +
  0.24 * $bio +
  0.20 * $participation
));
$pulse = max(0, min(100, $pulse));

json_response([
  'ok' => true,
  'data' => [
    'esg' => [
      'environment' => $snap['environment'],
      'social' => $snap['social'],
      'governance' => $snap['governance'],
    ],
    'green_intelligence' => $green,
    'pulse' => [
      'score' => $pulse,
      'factors' => [
        'canopy' => round($canopy, 2),
        'drought_risk' => round($drought, 2),
        'biodiversity' => round($bio, 2),
        'participation_norm' => round($participation, 2),
      ],
    ],
    'meta' => [
      'authority_id' => $authorityIdForGreen,
      'fetched_at' => gmdate('c'),
    ],
  ],
]);
