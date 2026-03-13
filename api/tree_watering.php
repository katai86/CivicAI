<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$treeId = isset($_POST['tree_id']) ? (int)$_POST['tree_id'] : 0;
$waterAmount = isset($_POST['water_amount']) ? trim((string)$_POST['water_amount']) : '';
$note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';

if ($treeId <= 0) {
  json_response(['ok' => false, 'error' => 'Invalid tree'], 400);
}

if ($waterAmount !== '' && !is_numeric($waterAmount)) {
  json_response(['ok' => false, 'error' => 'Invalid water amount'], 400);
}

// Fallback konstansok, ha nincs config (öntözési kép feltöltés ne fusson hibára)
if (!defined('UPLOAD_MAX_BYTES')) {
  define('UPLOAD_MAX_BYTES', 6 * 1024 * 1024);
}
if (!defined('UPLOAD_DIR')) {
  define('UPLOAD_DIR', __DIR__ . '/../uploads');
}

$photoFilename = null;

// Optional photo upload
if (!empty($_FILES['photo']) && is_array($_FILES['photo'])) {
  $f = $_FILES['photo'];
  if ($f['error'] === UPLOAD_ERR_OK && $f['size'] > 0) {
    $uploadMax = defined('UPLOAD_MAX_BYTES') ? (int)UPLOAD_MAX_BYTES : (6 * 1024 * 1024);
    if ($f['size'] > $uploadMax) {
      json_response(['ok' => false, 'error' => 'File too large'], 400);
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
    $mime = $mime ?: '';
    $allowed = defined('UPLOAD_ALLOWED_MIME') && is_array(UPLOAD_ALLOWED_MIME) ? UPLOAD_ALLOWED_MIME : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
      json_response(['ok'=>false,'error'=>'Invalid file type'], 400);
    }
    $ext = $allowed[$mime];
    $photoFilename = 't'.$treeId.'_u'.$uid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
    $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'trees';
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, true)) {
        log_error('tree_watering: cannot create upload dir ' . $dir);
        json_response(['ok'=>false,'error'=>'Upload failed'], 500);
      }
    }
    if (!is_writable($dir)) {
      log_error('tree_watering: upload dir not writable ' . $dir);
      json_response(['ok'=>false,'error'=>'Upload failed'], 500);
    }
    $dest = $dir . DIRECTORY_SEPARATOR . $photoFilename;
    if (!@move_uploaded_file($tmp, $dest)) {
      log_error('tree_watering: move_uploaded_file failed ' . $dest);
      json_response(['ok'=>false,'error'=>'Cannot save file'], 500);
    }
  }
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT id FROM trees WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $treeId]);
  if (!$stmt->fetchColumn()) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => 'Tree not found'], 404);
  }

  // Rate limit: max 5 öntözés/fa/nap/user (duplikátum és abuse ellen)
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM tree_watering_logs
    WHERE tree_id = :tid AND user_id = :uid AND DATE(created_at) = CURDATE()
  ");
  $stmt->execute([':tid' => $treeId, ':uid' => $uid]);
  if ((int)$stmt->fetchColumn() >= 5) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => 'Ma már elérted az öntözési limitet erre a fára (max 5/nap).'], 429);
  }

  $stmt = $pdo->prepare("INSERT INTO tree_watering_logs (tree_id, user_id, photo, water_amount, note)
                         VALUES (:tid, :uid, :photo, :amount, :note)");
  $stmt->execute([
    ':tid' => $treeId,
    ':uid' => $uid,
    ':photo' => $photoFilename,
    ':amount' => $waterAmount !== '' ? (float)$waterAmount : null,
    ':note' => $note !== '' ? mb_substr($note, 0, 255) : null,
  ]);

  // Update trees.last_watered to today
  $pdo->prepare("UPDATE trees SET last_watered = CURDATE() WHERE id = :id")
      ->execute([':id' => $treeId]);

  // XP jutalom
  try {
    add_user_xp($uid, 5, 'watering_tree', null);
  } catch (Throwable $e) {
    // ignore XP errors
  }

  $pdo->commit();

  json_response([
    'ok' => true,
    'tree_id' => $treeId,
    'last_watered' => date('Y-m-d'),
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  log_error('tree_watering error: ' . $e->getMessage());
  json_response(['ok' => false, 'error' => 'Server error'], 500);
}

