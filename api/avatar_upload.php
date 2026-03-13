<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();
require_user();

$uid = current_user_id();
if (!$uid) json_response(['ok'=>false,'error'=>t('api.unauthorized')], 401);

if (!isset($_FILES['file'])) json_response(['ok'=>false,'error'=>t('api.no_file')], 400);
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>t('api.upload_error')], 400);

$size = (int)$f['size'];
if ($size <= 0) json_response(['ok'=>false,'error'=>t('api.empty_file')], 400);
if ($size > 2 * 1024 * 1024) json_response(['ok'=>false,'error'=>t('api.file_too_large')], 400);

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
$mime = $mime ?: '';

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
  json_response(['ok'=>false,'error'=>t('api.invalid_file_type')], 400);
}

$ext = $allowed[$mime];
$stored = 'u'.$uid.'_'.bin2hex(random_bytes(8)).'.'.$ext;

$dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'avatars';
if (!is_dir($dir)) {
  @mkdir($dir, 0775, true);
}
$dest = $dir . DIRECTORY_SEPARATOR . $stored;

if (!@move_uploaded_file($tmp, $dest)) {
  json_response(['ok'=>false,'error'=>t('api.cannot_move_file')], 500);
}

// delete old avatar
$stmt = db()->prepare("SELECT avatar_filename FROM users WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$uid]);
$old = (string)($stmt->fetchColumn() ?: '');
if ($old) {
  $oldPath = $dir . DIRECTORY_SEPARATOR . $old;
  if (is_file($oldPath)) @unlink($oldPath);
}

$stmt = db()->prepare("UPDATE users SET avatar_filename=:f WHERE id=:id");
$stmt->execute([':f'=>$stored, ':id'=>$uid]);

header('Location: ' . app_url('/user/settings.php'));
exit;
