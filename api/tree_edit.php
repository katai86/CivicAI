<?php
/**
 * M4 – Fa adat szerkesztése (Smart Tree Registry).
 * POST: tree_id, species?, estimated_age?, planting_year?, health_status?, risk_level?, notes?
 * Jog: admin, govuser (saját hatóság), vagy a fa örökbefogadója.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

start_secure_session();
require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
}

$treeId = isset($_POST['tree_id']) ? (int)$_POST['tree_id'] : 0;
if ($treeId <= 0) {
  json_response(['ok' => false, 'error' => t('api.tree_invalid_id')], 400);
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, adopted_by_user_id FROM trees WHERE id = ? LIMIT 1");
$stmt->execute([$treeId]);
$tree = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tree) {
  json_response(['ok' => false, 'error' => 'Fa nem található.'], 404);
}

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$isGov = ($role === 'govuser');
$isAdopter = ((int)$tree['adopted_by_user_id']) === ((int)$uid);

$canEdit = $isAdmin;
if (!$canEdit && $isGov) {
  $st = $pdo->prepare("SELECT 1 FROM authority_users WHERE user_id = ? LIMIT 1");
  $st->execute([$uid]);
  $canEdit = (bool)$st->fetchColumn();
}
if (!$canEdit) {
  $canEdit = $isAdopter;
}
if (!$canEdit) {
  json_response(['ok' => false, 'error' => t('api.tree_no_permission_edit')], 403);
}

$updates = [];
$params = [];
$canEditGov = $isAdmin || $isGov;

$fields = [
  'species' => ['varchar', 120],
  'address' => ['varchar', 255],
  'estimated_age' => ['int', null],
  'planting_year' => ['int', null],
  'trunk_diameter' => ['decimal', null],
  'canopy_diameter' => ['decimal', null],
  'health_status' => ['varchar', 32],
  'risk_level' => ['varchar', 32],
  'notes' => ['text', null],
];
$govOnlyFields = ['last_watered' => 'date', 'last_inspection' => 'date', 'public_visible' => 'int', 'gov_validated' => 'int'];

foreach ($fields as $key => $cfg) {
  if (!array_key_exists($key, $_POST)) continue;
  $v = $_POST[$key];
  if ($cfg[0] === 'varchar' && $cfg[1]) {
    $v = mb_substr(trim((string)$v), 0, $cfg[1]);
    $updates[] = "`$key` = ?";
    $params[] = $v === '' ? null : $v;
  } elseif ($cfg[0] === 'int') {
    $v = $v === '' || $v === null ? null : (int)$v;
    $updates[] = "`$key` = ?";
    $params[] = $v;
  } elseif ($cfg[0] === 'text') {
    $v = trim((string)$v);
    $updates[] = "`$key` = ?";
    $params[] = $v === '' ? null : $v;
  } elseif ($cfg[0] === 'decimal') {
    $v = ($v === '' || $v === null) ? null : (float)$v;
    $updates[] = "`$key` = ?";
    $params[] = $v;
  }
}

foreach ($govOnlyFields as $key => $type) {
  if (!array_key_exists($key, $_POST) || !$canEditGov) continue;
  $v = $_POST[$key];
  if ($type === 'date') {
    $v = trim((string)$v);
    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) continue;
    $updates[] = "`$key` = ?";
    $params[] = $v === '' ? null : $v;
  } elseif ($type === 'int') {
    $v = $v === '' || $v === null ? 0 : (int)$v;
    $updates[] = "`$key` = ?";
    $params[] = $v ? 1 : 0;
  }
}

if (empty($updates)) {
  json_response(['ok' => true, 'message' => t('api.tree_no_change')]);
}

$params[] = $treeId;
$sql = "UPDATE trees SET " . implode(', ', $updates) . " WHERE id = ?";
$pdo->prepare($sql)->execute($params);

json_response(['ok' => true, 'message' => t('common.saved')]);