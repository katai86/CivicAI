<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

$rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rid <= 0) {
  http_response_code(400);
  echo 'Hibás ügyazonosító.';
  exit;
}

$stmt = db()->prepare("\n  SELECT\n    id, category, title, description, status, created_at,\n    address_approx, road, suburb, city, postcode,\n    lat, lng,\n    notify_enabled, notify_token\n  FROM reports\n  WHERE id = :id AND user_id = :uid\n  LIMIT 1\n");
$stmt->execute([':id' => $rid, ':uid' => $userId]);
$r = $stmt->fetch();

if (!$r) {
  http_response_code(404);
  echo 'Nem található ilyen ügy (vagy nem a tied).';
  exit;
}

$caseNo = case_number((int)$r['id'], (string)$r['created_at']);

$statusLabel = [
  'pending' => 'Ellenőrzés alatt',
  'approved' => 'Publikálva',
  'rejected' => 'Elutasítva',
  'new' => 'Új',
  'needs_info' => 'Kiegészítésre vár',
  'forwarded' => 'Továbbítva',
  'waiting_reply' => 'Válaszra vár',
  'in_progress' => 'Folyamatban',
  'solved' => 'Megoldva',
  'closed' => 'Lezárva',
];
$catLabel = [
  'road'=>'Úthiba / kátyú',
  'sidewalk'=>'Járda / burkolat hiba',
  'lighting'=>'Közvilágítás',
  'trash'=>'Szemét / illegális',
  'green'=>'Zöldterület / veszélyes fa',
  'traffic'=>'Közlekedés / tábla',
  'idea'=>'Ötlet / javaslat',
  'civil_event'=>'Civil esemény',
];

$st = (string)$r['status'];
$stHuman = $statusLabel[$st] ?? $st;
$catHuman = $catLabel[(string)$r['category']] ?? (string)$r['category'];

$logs = [];
try {
  $logStmt = db()->prepare("\n    SELECT old_status, new_status, note, changed_by, changed_at\n    FROM report_status_log\n    WHERE report_id = :id\n    ORDER BY changed_at DESC, id DESC\n    LIMIT 200\n  ");
  $logStmt->execute([':id' => $rid]);
  $logs = $logStmt->fetchAll();
} catch (Throwable $e) {
  $logs = [];
}

$updatedAt = (string)$r['created_at'];
if (!empty($logs) && !empty($logs[0]['changed_at'])) {
  $updatedAt = (string)$logs[0]['changed_at'];
}

$osmUrl = "https://www.openstreetmap.org/?mlat=" . rawurlencode((string)$r['lat']) .
          "&mlon=" . rawurlencode((string)$r['lng']) . "#map=19/" .
          rawurlencode((string)$r['lat']) . "/" . rawurlencode((string)$r['lng']);

$trackUrl = ((int)$r['notify_enabled'] === 1 && !empty($r['notify_token']))
  ? app_url('/case.php?token=' . rawurlencode((string)$r['notify_token']))
  : null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h(t('site.name')) ?> – <?= h($caseNo) ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="page"
  data-report-id="<?= (int)$rid ?>"
  data-api-attachments="<?= h(app_url('/api/report_attachments.php')) ?>"
  data-api-delete="<?= h(app_url('/api/report_attachment_delete.php')) ?>"
  data-api-upload="<?= h(app_url('/api/report_upload.php')) ?>"
>
<?php $uid = $userId; $role = function_exists('current_user_role') ? (current_user_role() ?: 'user') : 'user'; $currentLang = function_exists('current_lang') ? current_lang() : 'hu'; require __DIR__ . '/../inc_desktop_topbar.php'; ?>

<div class="wrap">
  <div class="card">
    <div class="top">
      <div>
        <h1>Saját ügy – <?= h($caseNo) ?></h1>
        <div class="meta">
          Bejelentés ID: <b>#<?= (int)$rid ?></b> • Kategória: <b><?= h($catHuman) ?></b><br>
          Létrehozva: <b><?= h($r['created_at']) ?></b> • Frissítve: <b><?= h($updatedAt) ?></b>
        </div>
      </div>
      <div class="pill">Státusz: <b><?= h($stHuman) ?></b></div>
    </div>

    <div class="grid cols-split" style="margin-top:12px">
      <div class="card" style="box-shadow:none">
        <div style="font-weight:900;margin-bottom:6px">Leírás</div>
        <div><?= nl2br(h($r['description'])) ?></div>

        <?php if (!empty($r['title'])): ?>
          <div style="margin-top:10px" class="small"><b>Rövid cím:</b> <?= h($r['title']) ?></div>
        <?php endif; ?>
      </div>

      <div class="card" style="box-shadow:none">
        <div style="font-weight:900;margin-bottom:6px">Helyszín</div>
        <div class="small"><b>Cím (csak neked):</b><br><?= h($r['address_approx'] ?: '—') ?></div>
        <div class="actions">
          <a class="btn" href="<?= h($osmUrl) ?>" target="_blank" rel="noopener">Megnyitás térképen</a>
          <a class="btn" href="<?= h(app_url('/user/my.php')) ?>">Vissza a listához</a>
        </div>

        <div style="margin-top:12px;font-weight:900">Értesítések</div>
        <div class="small"><?= ((int)$r['notify_enabled'] === 1) ? 'Bekapcsolva' : 'Kikapcsolva' ?></div>
        <?php if ($trackUrl): ?>
          <div class="actions">
            <a class="btn primary" href="<?= h($trackUrl) ?>">Követő link (token)</a>
            <a class="btn" href="<?= h(app_url('/api/notify_unsubscribe.php?token=' . rawurlencode((string)$r['notify_token']))) ?>">Leiratkozás</a>
          </div>
        <?php endif; ?>

        <div style="margin-top:12px;font-weight:900">Képcsatolmányok</div>
        <div class="small">Csak te (és az admin) látja. JPG/PNG/WebP, max. 6 MB.</div>

        <div class="urow" style="margin-top:8px">
          <input id="file" type="file" accept="image/*">
          <button id="uploadBtn" class="btn primary" type="button">Feltöltés</button>
          <span id="upMsg" class="small"></span>
        </div>

        <div id="gallery" class="gallery"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="font-weight:900;margin-bottom:8px">Státusz-napló</div>
    <?php if (!$logs): ?>
      <div class="small">Még nincs státuszváltozás rögzítve.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Időpont</th>
            <th>Változás</th>
            <th>Megjegyzés</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $lg):
            $old = (string)($lg['old_status'] ?? '');
            $new = (string)($lg['new_status'] ?? '');
            $oldH = $old !== '' ? ($statusLabel[$old] ?? $old) : '—';
            $newH = $new !== '' ? ($statusLabel[$new] ?? $new) : '—';
            $note = (string)($lg['note'] ?? '');
            $at = (string)($lg['changed_at'] ?? '');
          ?>
          <tr>
            <td><?= h($at) ?></td>
            <td><b><?= h($oldH) ?></b> → <b><?= h($newH) ?></b></td>
            <td><?= $note ? nl2br(h($note)) : '<span class="small">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>


<script src="<?= h(app_url('/assets/user/report.js')) ?>"></script>

</body>
</html>
