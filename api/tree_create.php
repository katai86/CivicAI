<?php
/**
 * Új fa felvitele a térképre (GPS + opcionális faj, megjegyzés, fotó).
 * POST: lat, lng, species (opc.), note (opc.), photo (file opc.)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
}

$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
  json_response(['ok' => false, 'error' => t('api.tree_invalid_coords')], 400);
}

$species = isset($_POST['species']) ? mb_substr(trim((string)$_POST['species']), 0, 120) : null;
$note = isset($_POST['note']) ? mb_substr(trim((string)$_POST['note']), 0, 500) : null;
$trunkDiameter = isset($_POST['trunk_diameter_cm']) && is_numeric($_POST['trunk_diameter_cm']) ? (float)$_POST['trunk_diameter_cm'] : null;
$canopyDiameter = isset($_POST['canopy_diameter_m']) && is_numeric($_POST['canopy_diameter_m']) ? (float)$_POST['canopy_diameter_m'] : null;
if ($trunkDiameter !== null && ($trunkDiameter < 0 || $trunkDiameter > 500)) $trunkDiameter = null;
if ($canopyDiameter !== null && ($canopyDiameter < 0 || $canopyDiameter > 50)) $canopyDiameter = null;

$photoFilename = null;
if (!empty($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && $_FILES['photo']['size'] > 0) {
  $f = $_FILES['photo'];
  if ($f['size'] > UPLOAD_MAX_BYTES) {
    json_response(['ok' => false, 'error' => t('api.file_too_large')], 400);
  }
  $tmp = $f['tmp_name'];
  $mime = '';
  if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
      $mime = (string)finfo_file($fi, $tmp);
      finfo_close($fi);
    }
  }
  if ($mime === '' && function_exists('mime_content_type')) {
    $mime = (string)mime_content_type($tmp);
  }
  $allowed = UPLOAD_ALLOWED_MIME;
  if (!isset($allowed[$mime])) {
    json_response(['ok' => false, 'error' => t('api.upload_images_only')], 400);
  }
  $ext = $allowed[$mime];
  $photoFilename = 'new_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
  $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'trees';
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0775, true)) {
      log_error('tree_create: cannot create upload dir ' . $dir);
      json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
    }
  }
  $dest = $dir . DIRECTORY_SEPARATOR . $photoFilename;
  if (!@move_uploaded_file($tmp, $dest)) {
    log_error('tree_create: move_uploaded_file failed for ' . $dest);
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    INSERT INTO trees (lat, lng, address, species, trunk_diameter, canopy_diameter, health_status, risk_level, public_visible, gov_validated, created_at)
    VALUES (:lat, :lng, NULL, :species, :trunk, :canopy, NULL, NULL, 1, 0, NOW())
  ");
  $stmt->execute([
    ':lat' => $lat,
    ':lng' => $lng,
    ':species' => $species ?: null,
    ':trunk' => $trunkDiameter,
    ':canopy' => $canopyDiameter,
  ]);
  $treeId = (int)$pdo->lastInsertId();
  if ($treeId <= 0) {
    $pdo->rollBack();
    log_error('tree_create: lastInsertId failed');
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }

  $imgPath = ($photoFilename !== null && $photoFilename !== '') ? $photoFilename : null;
  if ($imgPath !== null || ($note !== null && $note !== '')) {
    $stmtLog = $pdo->prepare("
      INSERT INTO tree_logs (tree_id, user_id, log_type, note, image_path, created_at)
      VALUES (:tid, :uid, 'inspection', :note, :img, NOW())
    ");
    $stmtLog->execute([
      ':tid' => $treeId,
      ':uid' => $uid,
      ':note' => $note ?: null,
      ':img' => $imgPath,
    ]);
  }

  $pdo->commit();

  try {
    add_user_xp($uid, 10, 'tree_create', $treeId);
  } catch (Throwable $e) { /* ignore */ }

  json_response([
    'ok' => true,
    'tree_id' => $treeId,
    'lat' => $lat,
    'lng' => $lng,
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  log_error('tree_create: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  json_response(['ok' => false, 'error' => t('common.error_server')], 500);
}
