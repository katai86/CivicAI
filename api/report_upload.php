<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();
require_user();

// Biztonság: ha a csatolmány tábla hiányzik (pl. új DB), próbáljuk létrehozni.
function ensure_attachments_table_exists(): void {
  $sql = "CREATE TABLE IF NOT EXISTS report_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime VARCHAR(120) NOT NULL,
    size_bytes INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_user_id (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  db()->exec($sql);
}

$uid = current_user_id();
$rid = (int)($_POST['report_id'] ?? 0);
if ($rid <= 0) json_response(['ok'=>false,'error'=>'Missing report_id'], 400);

// Check report belongs to user OR admin (MVP: user only)
$stmt = db()->prepare("SELECT id, user_id FROM reports WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$rid]);
$r = $stmt->fetch();
if (!$r) json_response(['ok'=>false,'error'=>'Report not found'], 404);
if ((int)$r['user_id'] !== (int)$uid) json_response(['ok'=>false,'error'=>'Forbidden'], 403);

if (!isset($_FILES['file'])) json_response(['ok'=>false,'error'=>'No file'], 400);
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>'Upload error'], 400);

$size = (int)$f['size'];
if ($size <= 0) json_response(['ok'=>false,'error'=>'Empty file'], 400);
if ($size > UPLOAD_MAX_BYTES) json_response(['ok'=>false,'error'=>'File too large'], 400);

$tmp = $f['tmp_name'];
$mime = '';
// mime_content_type shared hoston néha fals / nincs: finfo a stabilabb
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

$allowed = UPLOAD_ALLOWED_MIME;
if (!isset($allowed[$mime])) {
  json_response(['ok'=>false,'error'=>'Invalid file type: '.($mime ?: 'unknown')], 400);
}

$ext = $allowed[$mime];
$origName = basename((string)$f['name']);
$origName = preg_replace('/[^\pL\pN\.\-\_\s]/u', '_', $origName);

$stored = 'r'.$rid.'_'.bin2hex(random_bytes(8)).'.'.$ext;

if (!is_dir(UPLOAD_DIR)) {
  @mkdir(UPLOAD_DIR, 0775, true);
}

$dest = rtrim(UPLOAD_DIR, '/').'/'.$stored;

if (!@move_uploaded_file($tmp, $dest)) {
  json_response(['ok'=>false,'error'=>'Cannot move file'], 500);
}

// Létrehozzuk a táblát, ha hiányzik (ha nincs jog CREATE-re, akkor az INSERT úgyis hibázik)
try { ensure_attachments_table_exists(); } catch (Throwable $e) { /* ignore */ }

try {
  $stmt = db()->prepare('INSERT INTO report_attachments (report_id, user_id, filename, stored_name, mime, size_bytes) VALUES (:rid,:uid,:fn,:sn,:mime,:sz)');
  $stmt->execute([
    ':rid'=>$rid,
    ':uid'=>$uid,
    ':fn'=>$origName,
    ':sn'=>$stored,
    ':mime'=>$mime,
    ':sz'=>$size,
  ]);
} catch (Throwable $e) {
  @unlink($dest);
  json_response(['ok'=>false,'error'=>'DB error: '.$e->getMessage()], 500);
}

// XP: foto csatolas
add_user_xp($uid, 10, 'photo_upload', $rid);

json_response([
  'ok'=>true,
  'file'=>[
    'stored_name'=>$stored,
    'filename'=>$origName,
    'mime'=>$mime,
    'size'=>$size,
    'url'=>rtrim(UPLOAD_PUBLIC, '/').'/'.$stored,
  ]
]);