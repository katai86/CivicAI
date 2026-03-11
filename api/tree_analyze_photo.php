<?php
/**
 * Fa fotó elemzés: fajta, törzsméret, koronaméret javaslat (AI). Fa feltöltés űrlap kitöltéséhez.
 * POST: photo (file). Rate limit: image_classification.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AiRouter.php';

start_secure_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
}

if (empty($_FILES['photo']) || !is_array($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK || $_FILES['photo']['size'] <= 0) {
  json_response(['ok' => false, 'error' => t('tree.health_analyze_need_photo')], 400);
}
$f = $_FILES['photo'];
if ($f['size'] > (defined('UPLOAD_MAX_BYTES') ? UPLOAD_MAX_BYTES : 6 * 1024 * 1024)) {
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
$allowed = defined('UPLOAD_ALLOWED_MIME') ? UPLOAD_ALLOWED_MIME : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
  json_response(['ok' => false, 'error' => t('api.upload_images_only')], 400);
}
$ext = $allowed[$mime];
$dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'trees';
if (!is_dir($dir)) {
  @mkdir($dir, 0775, true);
}
$tempFilename = 'analyze_' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $dir . DIRECTORY_SEPARATOR . $tempFilename;
if (!@move_uploaded_file($tmp, $dest)) {
  json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
}

$system = 'You are a tree identification and measurement assistant. Reply with a JSON object only. Use keys: species (string, common name of the tree e.g. oak, ash, or null if unknown), trunk_diameter_cm (number or null, estimated trunk diameter in cm), canopy_diameter_m (number or null, estimated canopy/crown diameter in metres). If not visible or uncertain, use null.';
$prompt = 'Analyze this tree photo. Estimate: 1) Tree species (common name, e.g. oak, ash, lime). 2) Trunk diameter in cm (at breast height if visible). 3) Canopy/crown diameter in metres. Return JSON with keys: species, trunk_diameter_cm, canopy_diameter_m. Use null for any value you cannot estimate.';

$router = new \AiRouter();
$resp = $router->callWithImage('image_classification', $prompt, $dest, $mime, $system);
@unlink($dest);

if (empty($resp['ok'])) {
  json_response(['ok' => false, 'error' => $resp['error'] ?? t('common.error_server')], 502);
}

$data = is_array($resp['data']) ? $resp['data'] : [];
$species = isset($data['species']) ? mb_substr(trim((string)$data['species']), 0, 120) : null;
if ($species === '') $species = null;
$trunkCm = isset($data['trunk_diameter_cm']) ? (is_numeric($data['trunk_diameter_cm']) ? (float)$data['trunk_diameter_cm'] : null) : null;
$canopyM = isset($data['canopy_diameter_m']) ? (is_numeric($data['canopy_diameter_m']) ? (float)$data['canopy_diameter_m'] : null) : null;
if ($trunkCm !== null && ($trunkCm < 0 || $trunkCm > 500)) $trunkCm = null;
if ($canopyM !== null && ($canopyM < 0 || $canopyM > 50)) $canopyM = null;

json_response([
  'ok' => true,
  'species' => $species,
  'trunk_diameter_cm' => $trunkCm,
  'canopy_diameter_m' => $canopyM,
]);
