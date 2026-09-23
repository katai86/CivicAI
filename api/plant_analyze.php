<?php
/**
 * M15+ – Full plant/tree cloud analysis API.
 * POST: photo (file), tree_id (optional), lat, lng, plant_part, session_id
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/plant/PlantTreeVisionRouter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();
require_user();
$uid = current_user_id();
if (!$uid) {
    json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
}

if (!plant_tree_enabled() && !ai_configured()) {
    json_response(['ok' => false, 'error' => t('plant_tree.module_disabled')], 503);
}

$limit = (int)(get_module_setting('plant_tree', 'daily_analysis_limit') ?: 200);
if ($limit > 0) {
    try {
        $cnt = (int)db()->query("SELECT COUNT(*) FROM tree_inspections WHERE created_at >= CURDATE()")->fetchColumn();
        if ($cnt >= $limit) {
            json_response(['ok' => false, 'error' => t('plant_tree.daily_limit_reached')], 429);
        }
    } catch (Throwable $e) {
        // table may not exist yet
    }
}

if (empty($_FILES['photo']) || !is_array($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
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
$allowed = defined('UPLOAD_ALLOWED_MIME') ? UPLOAD_ALLOWED_MIME : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    json_response(['ok' => false, 'error' => t('api.upload_images_only')], 400);
}
$ext = $allowed[$mime];
$dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'trees';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$treeId = isset($_POST['tree_id']) ? (int)$_POST['tree_id'] : 0;
$dest = $dir . DIRECTORY_SEPARATOR . 'plant_' . ($treeId ?: 'x') . '_' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
if (!@move_uploaded_file($tmp, $dest)) {
    json_response(['ok' => false, 'error' => t('api.upload_failed')], 500);
}

$context = [
    'tree_id' => $treeId > 0 ? $treeId : null,
    'lat' => isset($_POST['lat']) ? (float)$_POST['lat'] : null,
    'lng' => isset($_POST['lng']) ? (float)$_POST['lng'] : null,
    'plant_part' => (string)($_POST['plant_part'] ?? 'whole_tree'),
    'session_id' => (string)($_POST['session_id'] ?? ''),
    'lang' => function_exists('current_lang') ? current_lang() : 'hu',
];

if ($treeId > 0) {
    $st = db()->prepare('SELECT id, species, lat, lng, authority_id FROM trees WHERE id = ? AND public_visible = 1 LIMIT 1');
    $st->execute([$treeId]);
    $tree = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tree) {
        @unlink($dest);
        json_response(['ok' => false, 'error' => t('api.tree_not_found')], 404);
    }
    $context['species'] = $tree['species'] ?? '';
    $context['authority_id'] = (int)($tree['authority_id'] ?? 0);
    if ($context['lat'] === null && isset($tree['lat'])) $context['lat'] = (float)$tree['lat'];
    if ($context['lng'] === null && isset($tree['lng'])) $context['lng'] = (float)$tree['lng'];
} else {
    $context['authority_id'] = isset($_POST['authority_id']) ? (int)$_POST['authority_id'] : null;
}

$router = new PlantTreeVisionRouter();
$result = $router->analyze($dest, $mime, $context);

if (empty($result['ok'])) {
    json_response([
        'ok' => false,
        'error' => t('plant_tree.analysis_failed'),
        'status' => $result['status'] ?? 'PROCESSING_FAILED',
        'details' => $result,
    ], 502);
}

$a = $result['analysis'];
json_response([
    'ok' => true,
    'inspection_id' => $result['inspection_id'],
    'status' => $a['status'] ?? 'SUCCESS',
    'species' => $a['species_consensus'] ?? null,
    'health' => $a['health'] ?? null,
    'risk' => $a['risk'] ?? null,
    'guidance' => $a['guidance'] ?? '',
    'confidence_label' => $a['fusion']['confidence_label'] ?? 'UNKNOWN',
    'city_intel' => $result['city_intel'] ?? null,
]);
