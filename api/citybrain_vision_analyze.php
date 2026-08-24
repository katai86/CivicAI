<?php
/**
 * City Brain – WOW AI Vision: utcaállapot, zöld felületek, fa fajta/egészség/méret.
 * POST multipart: image|photo (file). Gov/admin.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AiVisionService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('api.method_not_allowed') : 'Method not allowed'], 405);
}

$fileKey = null;
if (!empty($_FILES['photo']['tmp_name'])) {
    $fileKey = 'photo';
} elseif (!empty($_FILES['image']['tmp_name'])) {
    $fileKey = 'image';
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
$allowed = defined('UPLOAD_ALLOWED_MIME') ? UPLOAD_ALLOWED_MIME : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    json_response(['ok' => false, 'error' => function_exists('t') ? t('api.upload_images_only') : 'Images only'], 400);
}

$result = (new AiVisionService())->analyzeCitybrain($tmp, $mime, (string)($f['name'] ?? 'street.jpg'));

if (empty($result['ok'])) {
    json_response([
        'ok' => false,
        'error' => $result['error'] ?? (function_exists('t') ? t('intel.ai_vision_failed') : 'Vision failed'),
        'data' => $result,
    ], 502);
}

json_response(['ok' => true, 'data' => $result]);
