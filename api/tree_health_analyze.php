<?php
/**
 * M6 – AI tree health: fotó feltöltés → egyszerű egészségi javaslat (healthy | dry | disease_suspected).
 * POST: tree_id, photo (file). Rate limit: image_classification (AI_IMAGE_ANALYSIS_LIMIT).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AiRouter.php';
require_once __DIR__ . '/../services/AiPromptBuilder.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
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
$photoFilename = 'health_' . $treeId . '_' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $dir . DIRECTORY_SEPARATOR . $photoFilename;
if (!@move_uploaded_file($tmp, $dest)) {
  json_response(['ok' => false, 'error' => 'Feltöltés sikertelen.'], 500);
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, species, health_status FROM trees WHERE id = ? AND public_visible = 1 LIMIT 1");
$stmt->execute([$treeId]);
$tree = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tree) {
  @unlink($dest);
  json_response(['ok' => false, 'error' => t('api.tree_not_found')], 404);
}

$outputLang = function_exists('current_lang') ? current_lang() : 'hu';
$langName = \AiPromptBuilder::languageNameForCode($outputLang);
$prompt = "Analyze this tree photo. Assess: leaf color, dryness, visible disease or damage. Return JSON with: status (exactly one of: healthy, dry, disease_suspected), confidence (0-1), suggestion (one short sentence, in " . $langName . " only). Tree species if known: " . (trim($tree['species'] ?? '') ?: 'unknown') . ".";

$router = new \AiRouter();
$resp = $router->callWithImage('image_classification', $prompt, $dest, $mime);

if (empty($resp['ok'])) {
  json_response(['ok' => false, 'error' => $resp['error'] ?? t('api.health_analyze_failed')], 502);
}

$data = is_array($resp['data']) ? $resp['data'] : [];
$status = isset($data['status']) ? strtolower(trim((string)$data['status'])) : '';
if (!in_array($status, ['healthy', 'dry', 'disease_suspected'], true)) {
  $status = 'healthy';
}
$suggestion = isset($data['suggestion']) ? trim((string)$data['suggestion']) : '';
$confidence = isset($data['confidence']) ? (float)$data['confidence'] : 0;

try {
  $pdo->prepare("INSERT INTO tree_logs (tree_id, user_id, log_type, note, image_path, created_at) VALUES (?, ?, 'inspection', ?, ?, NOW())")
    ->execute([$treeId, $uid, 'AI health: ' . $status . ($suggestion ? ' – ' . $suggestion : ''), $photoFilename]);
} catch (Throwable $e) {
  // ignore
}

json_response([
  'ok' => true,
  'status' => $status,
  'suggestion' => $suggestion,
  'confidence' => $confidence,
  'image_saved' => $photoFilename,
]);
