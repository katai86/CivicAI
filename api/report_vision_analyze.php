<?php
/**
 * Polgári / bejelentés fotó → élő AI Vision javaslatok (kategória, prioritás, leírás).
 * POST multipart: photo|image (file), opcionális model=ai_blip.
 * Rate limit: image_classification (ai_image_analysis_limit).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AiVisionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('api.method_not_allowed') : 'Method not allowed'], 405);
}

start_secure_session();
require_user();
$uid = current_user_id();
if (!$uid) {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('auth.login_required') : 'Login required'], 401);
}

$fileKey = null;
if (!empty($_FILES['photo']['tmp_name'])) {
    $fileKey = 'photo';
} elseif (!empty($_FILES['image']['tmp_name'])) {
    $fileKey = 'image';
} elseif (!empty($_FILES['file']['tmp_name'])) {
    $fileKey = 'file';
}
if ($fileKey === null) {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('intel.ai_vision_need_image') : 'Image required'], 400);
}

$f = $_FILES[$fileKey];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($f['size'] ?? 0) <= 0) {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('intel.ai_vision_need_image') : 'Image required'], 400);
}

$maxBytes = defined('UPLOAD_MAX_BYTES') ? (int)UPLOAD_MAX_BYTES : 6 * 1024 * 1024;
if ((int)$f['size'] > $maxBytes) {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('api.file_too_large') : 'File too large'], 400);
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
    json_response(['ok' => false, 'error' => function_exists('t') ? t('api.upload_images_only') : 'Images only'], 400);
}

$model = trim((string)($_POST['model'] ?? 'ai_blip'));
$reportId = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
$entityId = $reportId > 0 ? $reportId : $uid;
$entityType = $reportId > 0 ? 'report' : 'user_vision';

$result = (new AiVisionService())->analyzeFile($model, $tmp, $mime, (string)($f['name'] ?? 'photo.jpg'), $entityId, $entityType);

if (empty($result['ok'])) {
    json_response([
        'ok' => false,
        'error' => $result['error'] ?? (function_exists('t') ? t('intel.ai_vision_failed') : 'Vision failed'),
        'data' => $result,
    ], 502);
}

json_response([
    'ok' => true,
    'suggested_category' => $result['suggested_category'] ?? null,
    'suggested_subcategory' => $result['suggested_subcategory'] ?? null,
    'urgency_level' => $result['urgency_level'] ?? null,
    'hazard_level' => $result['hazard_level'] ?? null,
    'short_title' => $result['short_title'] ?? null,
    'description' => $result['description'] ?? null,
    'confidence_score' => $result['confidence_score'] ?? null,
    'objects' => $result['objects'] ?? [],
    'segments' => $result['segments'] ?? [],
    'depth_notes' => $result['depth_notes'] ?? null,
    'provider_model' => $result['provider_model'] ?? null,
    'data' => $result,
]);
