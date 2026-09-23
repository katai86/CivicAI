<?php
/**
 * Új fa felvitele a térképre (GPS + opcionális faj, megjegyzés, fotó).
 * POST: lat, lng, species (opc.), note (opc.), photo (file opc.), trunk_diameter_cm, canopy_diameter_m
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

$clip = static function (?string $s, int $max): ?string {
  $s = $s === null ? '' : trim($s);
  if ($s === '') {
    return null;
  }
  if (function_exists('mb_substr')) {
    return mb_substr($s, 0, $max);
  }
  return substr($s, 0, $max);
};

$latRaw = $_POST['lat'] ?? null;
$lngRaw = $_POST['lng'] ?? null;
if ($latRaw === null || $latRaw === '' || $lngRaw === null || $lngRaw === '' || !is_numeric($latRaw) || !is_numeric($lngRaw)) {
  json_response(['ok' => false, 'error' => t('api.tree_invalid_coords')], 400);
}
$lat = (float)$latRaw;
$lng = (float)$lngRaw;
if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
  json_response(['ok' => false, 'error' => t('api.tree_invalid_coords')], 400);
}

$species = $clip(isset($_POST['species']) ? (string)$_POST['species'] : null, 120);
$note = $clip(isset($_POST['note']) ? (string)$_POST['note'] : null, 500);
$trunkDiameter = isset($_POST['trunk_diameter_cm']) && is_numeric($_POST['trunk_diameter_cm']) ? (float)$_POST['trunk_diameter_cm'] : null;
$canopyDiameter = isset($_POST['canopy_diameter_m']) && is_numeric($_POST['canopy_diameter_m']) ? (float)$_POST['canopy_diameter_m'] : null;
if ($trunkDiameter !== null && ($trunkDiameter < 0 || $trunkDiameter > 500)) {
  $trunkDiameter = null;
}
if ($canopyDiameter !== null && ($canopyDiameter < 0 || $canopyDiameter > 50)) {
  $canopyDiameter = null;
}

$photoFilename = null;
$uploadMaxBytes = defined('UPLOAD_MAX_BYTES') ? (int)UPLOAD_MAX_BYTES : (6 * 1024 * 1024);
if (!empty($_FILES['photo']) && is_array($_FILES['photo']) && (int)$_FILES['photo']['error'] === UPLOAD_ERR_OK && (int)$_FILES['photo']['size'] > 0) {
  $f = $_FILES['photo'];
  if ((int)$f['size'] > $uploadMaxBytes) {
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
  $allowed = defined('UPLOAD_ALLOWED_MIME') && is_array(UPLOAD_ALLOWED_MIME)
    ? UPLOAD_ALLOWED_MIME
    : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($allowed[$mime])) {
    json_response(['ok' => false, 'error' => t('api.upload_images_only')], 400);
  }
  $ext = $allowed[$mime];
  try {
    $photoFilename = 'new_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
  } catch (Throwable $e) {
    $photoFilename = 'new_' . $uid . '_' . uniqid('', true) . '.' . $ext;
  }
  $dir = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads'), '/\\') . DIRECTORY_SEPARATOR . 'trees';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    log_error('tree_create: cannot create upload dir ' . $dir);
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  if (!is_writable($dir)) {
    log_error('tree_create: upload dir not writable ' . $dir);
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
  $dest = $dir . DIRECTORY_SEPARATOR . $photoFilename;
  if (!@move_uploaded_file($tmp, $dest)) {
    log_error('tree_create: move_uploaded_file failed for ' . $dest);
    json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
  }
}

/** @return array<string,bool> */
$loadTreeColumns = static function (PDO $pdo): array {
  $cols = [];
  $colStmt = $pdo->query('SHOW COLUMNS FROM trees');
  while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['Field'])) {
      $cols[(string)$row['Field']] = true;
    }
  }
  return $cols;
};

$ensureTreeSchema = static function (PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS trees (
      id INT AUTO_INCREMENT PRIMARY KEY,
      lat DECIMAL(10,7) NOT NULL,
      lng DECIMAL(10,7) NOT NULL,
      address VARCHAR(255) NULL,
      species VARCHAR(120) NULL,
      estimated_age INT NULL,
      planting_year INT NULL,
      trunk_diameter DECIMAL(6,2) NULL,
      canopy_diameter DECIMAL(6,2) NULL,
      health_status VARCHAR(32) NULL,
      risk_level VARCHAR(32) NULL,
      last_inspection DATE NULL,
      last_watered DATE NULL,
      adopted_by_user_id INT NULL,
      gov_validated TINYINT(1) NOT NULL DEFAULT 0,
      public_visible TINYINT(1) NOT NULL DEFAULT 1,
      authority_id INT NULL,
      notes TEXT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_trees_geo (lat, lng),
      KEY idx_trees_visible (public_visible),
      KEY idx_trees_authority (authority_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS tree_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tree_id INT NOT NULL,
      user_id INT NULL,
      log_type VARCHAR(32) NOT NULL,
      note TEXT NULL,
      image_path VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_tree_logs_tree (tree_id),
      KEY idx_tree_logs_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  // Hiányzó oszlopok pótlása meglévő (régi) táblán
  $needed = [
    'trunk_diameter' => 'DECIMAL(6,2) NULL',
    'canopy_diameter' => 'DECIMAL(6,2) NULL',
    'health_status' => 'VARCHAR(32) NULL',
    'risk_level' => 'VARCHAR(32) NULL',
    'gov_validated' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'public_visible' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'authority_id' => 'INT NULL',
    'notes' => 'TEXT NULL',
    'species' => 'VARCHAR(120) NULL',
    'address' => 'VARCHAR(255) NULL',
  ];
  $have = [];
  try {
    $st = $pdo->query('SHOW COLUMNS FROM trees');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      if (!empty($row['Field'])) {
        $have[(string)$row['Field']] = true;
      }
    }
  } catch (Throwable $e) {
    return;
  }
  foreach ($needed as $col => $def) {
    if (!isset($have[$col])) {
      try {
        $pdo->exec('ALTER TABLE trees ADD COLUMN `' . $col . '` ' . $def);
      } catch (Throwable $e) {
        // ignore race / permission
      }
    }
  }

  // Magyar karakterek (ő, ű): species/latin1 → utf8mb4
  try {
    $pdo->exec('ALTER TABLE trees CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  } catch (Throwable $e) {
    try {
      $pdo->exec('ALTER TABLE trees MODIFY `species` VARCHAR(120) NULL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (Throwable $e2) {
      // ignore
    }
  }
};

$resolveAuthorityId = static function (PDO $pdo, float $lat, float $lng): ?int {
  try {
    $stmt = $pdo->prepare("
      SELECT id FROM authorities
      WHERE (is_active = 1 OR is_active IS NULL)
        AND min_lat IS NOT NULL AND max_lat IS NOT NULL
        AND min_lng IS NOT NULL AND max_lng IS NOT NULL
        AND ? BETWEEN min_lat AND max_lat
        AND ? BETWEEN min_lng AND max_lng
      ORDER BY id ASC
      LIMIT 1
    ");
    $stmt->execute([$lat, $lng]);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
  } catch (Throwable $e) {
    // is_active nélkül
    try {
      $stmt = $pdo->prepare("
        SELECT id FROM authorities
        WHERE min_lat IS NOT NULL AND max_lat IS NOT NULL
          AND min_lng IS NOT NULL AND max_lng IS NOT NULL
          AND ? BETWEEN min_lat AND max_lat
          AND ? BETWEEN min_lng AND max_lng
        ORDER BY id ASC
        LIMIT 1
      ");
      $stmt->execute([$lat, $lng]);
      $id = (int)$stmt->fetchColumn();
      return $id > 0 ? $id : null;
    } catch (Throwable $e2) {
      return null;
    }
  }
};

try {
  $pdo = db();
  $ensureTreeSchema($pdo);

  $cols = $loadTreeColumns($pdo);
  if ($cols === [] || !isset($cols['lat'], $cols['lng'])) {
    json_response([
      'ok' => false,
      'error' => t('common.error_save_failed') . ' (trees schema)',
      'error_code' => 'trees_schema_missing',
    ], 500);
  }

  // Duplikátum: ~2 m (public_visible opcionális)
  $delta = 0.00002;
  try {
    $dupSql = 'SELECT id FROM trees WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?';
    $dupParams = [$lat - $delta, $lat + $delta, $lng - $delta, $lng + $delta];
    if (isset($cols['public_visible'])) {
      $dupSql .= ' AND public_visible = 1';
    }
    $dupSql .= ' LIMIT 1';
    $stmtCheck = $pdo->prepare($dupSql);
    $stmtCheck->execute($dupParams);
    if ($stmtCheck->fetchColumn()) {
      json_response(['ok' => false, 'error' => (function_exists('t') ? t('api.tree_duplicate_location') : null) ?: 'Ezen a helyen már van fa.'], 409);
    }
  } catch (Throwable $dupEx) {
    log_error('tree_create: dup check skipped - ' . $dupEx->getMessage());
  }

  $authorityId = null;
  if (isset($cols['authority_id'])) {
    $authorityId = $resolveAuthorityId($pdo, $lat, $lng);
  }

  $fields = ['lat' => $lat, 'lng' => $lng];
  if (isset($cols['species'])) {
    $fields['species'] = $species;
  }
  if (isset($cols['address'])) {
    $fields['address'] = null;
  }
  if (isset($cols['trunk_diameter']) && $trunkDiameter !== null) {
    $fields['trunk_diameter'] = $trunkDiameter;
  }
  if (isset($cols['canopy_diameter']) && $canopyDiameter !== null) {
    $fields['canopy_diameter'] = $canopyDiameter;
  }
  if (isset($cols['notes']) && $note !== null) {
    $fields['notes'] = $note;
  }
  if (isset($cols['public_visible'])) {
    $fields['public_visible'] = 1;
  }
  if (isset($cols['gov_validated'])) {
    $fields['gov_validated'] = 0;
  }
  if (isset($cols['authority_id']) && $authorityId !== null) {
    $fields['authority_id'] = $authorityId;
  }

  $colNames = array_keys($fields);
  $placeholders = [];
  $params = [];
  foreach ($colNames as $i => $c) {
    $ph = 'p' . $i;
    $placeholders[] = ':' . $ph;
    $params[$ph] = $fields[$c];
  }
  $sql = 'INSERT INTO trees (`' . implode('`, `', $colNames) . '`) VALUES (' . implode(', ', $placeholders) . ')';

  $treeId = 0;
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $treeId = (int)$pdo->lastInsertId();
    if ($treeId <= 0) {
      $st2 = $pdo->prepare('SELECT id FROM trees WHERE lat = ? AND lng = ? ORDER BY id DESC LIMIT 1');
      $st2->execute([$lat, $lng]);
      $treeId = (int)$st2->fetchColumn();
    }
    if ($treeId <= 0) {
      throw new RuntimeException('tree_insert_no_id');
    }

    $imgPath = ($photoFilename !== null && $photoFilename !== '') ? $photoFilename : null;
    if ($imgPath !== null || ($note !== null && $note !== '')) {
      try {
        $stmtLog = $pdo->prepare("
          INSERT INTO tree_logs (tree_id, user_id, log_type, note, image_path, created_at)
          VALUES (?, ?, 'inspection', ?, ?, NOW())
        ");
        $stmtLog->execute([$treeId, $uid, $note, $imgPath]);
      } catch (Throwable $logEx) {
        log_error('tree_create: tree_logs insert failed - ' . $logEx->getMessage());
      }
    }

    $pdo->commit();
  } catch (Throwable $inner) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    // FK authority_id hiba → újrapróbál authority nélkül
    $em = $inner->getMessage();
    if (isset($fields['authority_id']) && (stripos($em, 'authority') !== false || stripos($em, '1452') !== false)) {
      unset($fields['authority_id']);
      $authorityId = null;
      $colNames = array_keys($fields);
      $placeholders = [];
      $params = [];
      foreach ($colNames as $i => $c) {
        $ph = 'p' . $i;
        $placeholders[] = ':' . $ph;
        $params[$ph] = $fields[$c];
      }
      $sql2 = 'INSERT INTO trees (`' . implode('`, `', $colNames) . '`) VALUES (' . implode(', ', $placeholders) . ')';
      $pdo->beginTransaction();
      $stmt = $pdo->prepare($sql2);
      $stmt->execute($params);
      $treeId = (int)$pdo->lastInsertId();
      if ($treeId <= 0) {
        $st2 = $pdo->prepare('SELECT id FROM trees WHERE lat = ? AND lng = ? ORDER BY id DESC LIMIT 1');
        $st2->execute([$lat, $lng]);
        $treeId = (int)$st2->fetchColumn();
      }
      if ($treeId <= 0) {
        throw $inner;
      }
      $pdo->commit();
    } else {
      throw $inner;
    }
  }

  try {
    if (function_exists('add_user_xp')) {
      add_user_xp($uid, 10, 'tree_create', null);
    }
  } catch (Throwable $e) { /* ignore */ }

  json_response([
    'ok' => true,
    'tree_id' => $treeId,
    'lat' => $lat,
    'lng' => $lng,
    'authority_id' => $authorityId,
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO) {
    try {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
    } catch (Throwable $ignored) {
    }
  }
  $hint = $e->getMessage();
  log_error('tree_create: ' . $hint . ' in ' . $e->getFile() . ':' . $e->getLine());

  $sqlstate = ($e instanceof PDOException && isset($e->errorInfo[0])) ? (string)$e->errorInfo[0] : null;
  $driverCode = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int)$e->errorInfo[1] : null;

  $msg = t('common.error_save_failed');
  if ($driverCode === 1146 || stripos($hint, "doesn't exist") !== false) {
    $msg = t('common.error_save_failed') . ' (hiányzó trees tábla – futtasd: sql/2026-13-tree-cadastre.sql)';
  } elseif ($driverCode === 1054 || stripos($hint, 'Unknown column') !== false) {
    $msg = t('common.error_save_failed') . ' (hiányzó oszlop a trees táblában)';
  }

  json_response([
    'ok' => false,
    'error' => $msg,
    'error_code' => 'tree_create_failed',
    'sqlstate' => $sqlstate,
    'driver_code' => $driverCode,
  ], 500);
}
