<?php
/**
 * City Brain – WOW AI Vision: utcaállapot, zöld felületek, fa fajta/egészség/méret.
 * POST multipart: image|photo (file), opcionális authority_id, lat, lng.
 * Eredmény tartósan mentve urban_observations-be.
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

$role = current_user_role() ?: '';
$uid = current_user_id() ? (int)current_user_id() : 0;
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$requestedAid = isset($_POST['authority_id']) ? (int)$_POST['authority_id'] : (isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0);
$authorityId = null;
if ($isAdmin) {
    $authorityId = $requestedAid > 0 ? $requestedAid : gov_primary_authority_id();
} else {
    $primary = gov_primary_authority_id();
    if ($requestedAid > 0) {
        $scope = gov_resolve_report_scope(db(), 'r', $requestedAid);
        if (empty($scope['authority_ids']) || !in_array($requestedAid, $scope['authority_ids'], true)) {
            json_response(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $authorityId = $requestedAid;
    } else {
        $authorityId = $primary;
    }
}

$lat = null;
$lng = null;
if (isset($_POST['lat']) && is_numeric($_POST['lat'])) {
    $lat = (float)$_POST['lat'];
}
if (isset($_POST['lng']) && is_numeric($_POST['lng'])) {
    $lng = (float)$_POST['lng'];
}

// Persist copy under uploads for observation reference
$imagePublic = null;
$destFs = null;
try {
    $uploadDir = defined('UPLOAD_DIR') ? rtrim((string)UPLOAD_DIR, '/\\') : (__DIR__ . '/../uploads');
    $obsDir = $uploadDir . DIRECTORY_SEPARATOR . 'observations';
    if (!is_dir($obsDir)) {
        @mkdir($obsDir, 0755, true);
    }
    $ext = $allowed[$mime] ?? 'jpg';
    $base = 'obs_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destFs = $obsDir . DIRECTORY_SEPARATOR . $base;
    if (@copy($tmp, $destFs)) {
        $publicBase = defined('UPLOAD_PUBLIC') ? rtrim((string)UPLOAD_PUBLIC, '/') : 'uploads';
        $imagePublic = $publicBase . '/observations/' . $base;
    }
} catch (Throwable $e) {
    $destFs = null;
    $imagePublic = null;
}

$analyzePath = $destFs && is_file($destFs) ? $destFs : $tmp;

$result = (new AiVisionService())->analyzeCitybrain($analyzePath, $mime, (string)($f['name'] ?? 'street.jpg'), [
    'persist' => true,
    'authority_id' => $authorityId,
    'lat' => $lat,
    'lng' => $lng,
    'image_public_path' => $imagePublic,
    'created_by' => $uid > 0 ? $uid : null,
]);

if (empty($result['ok'])) {
    json_response([
        'ok' => false,
        'error' => $result['error'] ?? (function_exists('t') ? t('intel.ai_vision_failed') : 'Vision failed'),
        'data' => $result,
    ], 502);
}

json_response(['ok' => true, 'data' => $result]);
