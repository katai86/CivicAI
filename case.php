<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
start_secure_session();
if (isset($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
}
$currentLang = current_lang();

$token = trim($_GET['token'] ?? '');

if ($token === '' || strlen($token) < 16) {
  http_response_code(400);
  echo t('case.bad_token');
  exit;
}

// Report lekérés token alapján (csak azoknak, akik kérték az értesítést -> van token)
$stmt = db()->prepare("
  SELECT
    id, category, title, description, status, created_at,
    address_approx, road, suburb, city, postcode,
    lat, lng,
    reporter_name, reporter_is_anonymous,
    reporter_email,
    notify_enabled, notify_token
  FROM reports
  WHERE notify_token = :t
  LIMIT 1
");
$stmt->execute([':t' => $token]);
$r = $stmt->fetch();

if (!$r) {
  http_response_code(404);
  echo t('case.not_found');
  exit;
}

$rid = (int)$r['id'];
$caseNo = case_number($rid, (string)$r['created_at']);

$statusLabel = [
  'pending' => t('status.pending'),
  'approved' => t('status.approved'),
  'rejected' => t('status.rejected'),
  'new' => t('status.new'),
  'needs_info' => t('status.needs_info'),
  'forwarded' => t('status.forwarded'),
  'waiting_reply' => t('status.waiting_reply'),
  'in_progress' => t('status.in_progress'),
  'solved' => t('status.solved'),
  'closed' => t('status.closed'),
];

$catLabel = [
  'road' => t('cat.road_desc'),
  'sidewalk' => t('cat.sidewalk_desc'),
  'lighting' => t('cat.lighting_desc'),
  'trash' => t('cat.trash_desc'),
  'green' => t('cat.green_desc'),
  'traffic' => t('cat.traffic_desc'),
  'idea' => t('cat.idea_desc'),
  'civil_event' => t('cat.civil_event_desc'),
];

$st = (string)$r['status'];
$stHuman = $statusLabel[$st] ?? $st;
$catHuman = $catLabel[(string)$r['category']] ?? (string)$r['category'];

// Státusz-log
$logStmt = db()->prepare("
  SELECT old_status, new_status, note, changed_at
  FROM report_status_log
  WHERE report_id = :id
  ORDER BY changed_at DESC
  LIMIT 50
");
$logStmt->execute([':id' => $rid]);
$logs = $logStmt->fetchAll();

// „Frissítve” = legutóbbi log időpont vagy created_at
$updatedAt = (string)$r['created_at'];
if (!empty($logs) && !empty($logs[0]['changed_at'])) {
  $updatedAt = (string)$logs[0]['changed_at'];
}

$unsubscribeUrl = app_url('/api/notify_unsubscribe.php?token=' . rawurlencode($token));

// OSM link (privát oldalon oké a pontos koordináta link)
$osmUrl = "https://www.openstreetmap.org/?mlat=" . rawurlencode((string)$r['lat']) .
          "&mlon=" . rawurlencode((string)$r['lng']) . "#map=19/" .
          rawurlencode((string)$r['lat']) . "/" . rawurlencode((string)$r['lng']);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid = 0;
$role = 'guest';
$isMobile = function_exists('use_mobile_layout') ? use_mobile_layout() : false;
?><!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,viewport-fit=cover">
  <link rel="icon" type="image/png" href="<?= h(app_url('/assets/fav_icon.png')) ?>">
  <link rel="apple-touch-icon" href="<?= h(app_url('/assets/fav_icon.png')) ?>">
  <title><?= h(t('site.name')) ?> – <?= h($caseNo) ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
  <?php if ($isMobile): ?>
  <link rel="stylesheet" href="<?= h(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(app_url('/assets/mobilekit_civicai.css')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page<?= $isMobile ? ' civicai-mobile' : '' ?>">
<?php if ($isMobile): ?>
  <?php $mobilePageTitle = h(t('case.tracking')) . ' – ' . $caseNo; $mobileActiveTab = ''; $mobileBackUrl = app_url('/'); require __DIR__ . '/inc_mobile_header.php'; ?>
<?php else: ?>
  <?php require __DIR__ . '/inc_desktop_topbar.php'; ?>
<?php endif; ?>

<div class="wrap">

  <div class="card">
    <div class="top">
      <div>
        <h1 class="h1"><?= h(t('case.tracking')) ?> – <?= h($caseNo) ?></h1>
        <div class="meta">
          <?= h(t('case.report_id')) ?>: <b>#<?= (int)$rid ?></b> • <?= h(t('case.category')) ?>: <b><?= h($catHuman) ?></b><br>
          <?= h(t('case.created')) ?>: <b><?= h($r['created_at']) ?></b> • <?= h(t('case.updated')) ?>: <b><?= h($updatedAt) ?></b>
        </div>
      </div>
      <div class="pill"><?= h(t('case.status')) ?>: <b><?= h($stHuman) ?></b></div>
    </div>

    <div class="grid cols-split" style="margin-top:12px">
      <div class="card" style="box-shadow:none">
        <div style="font-weight:800; margin-bottom:6px"><?= h(t('case.description')) ?></div>
        <div><?= nl2br(h($r['description'])) ?></div>

        <?php if (!empty($r['title'])): ?>
          <div style="margin-top:10px" class="small"><b><?= h(t('case.short_title')) ?>:</b> <?= h($r['title']) ?></div>
        <?php endif; ?>
      </div>

      <div class="card" style="box-shadow:none">
        <div style="font-weight:800; margin-bottom:6px"><?= h(t('case.details')) ?></div>

        <div class="kv">
          <div class="k"><?= h(t('case.location')) ?></div>
          <div class="v">
            <?= h($r['address_approx'] ?: '—') ?><br>
            <a href="<?= h($osmUrl) ?>" target="_blank" rel="noopener"><?= h(t('case.open_on_map')) ?></a>
          </div>

          <div class="k"><?= h(t('case.reporter')) ?></div>
          <div class="v">
            <?php
              $anon = (int)$r['reporter_is_anonymous'] === 1;
              if ($anon) echo h(t('case.anonymous'));
              else echo h($r['reporter_name'] ?: '—');
            ?>
          </div>

          <div class="k"><?= h(t('case.notifications')) ?></div>
          <div class="v">
            <?= ((int)$r['notify_enabled'] === 1) ? h(t('case.notif_on')) : h(t('case.notif_off')) ?>
          </div>
        </div>

        <div class="actions">
          <a class="btn primary" href="<?= h(app_url('/')) ?>"><?= h(t('case.public_map')) ?></a>
          <a class="btn" href="<?= h($unsubscribeUrl) ?>"><?= h(t('case.unsubscribe')) ?></a>
        </div>

        <div class="small" style="margin-top:10px">
          <?= h(t('case.tip')) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="font-weight:800; margin-bottom:10px"><?= h(t('case.history')) ?></div>

    <?php if (empty($logs)): ?>
      <div class="small"><?= h(t('case.no_history')) ?></div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th><?= h(t('case.time')) ?></th>
            <th><?= h(t('case.change')) ?></th>
            <th><?= h(t('case.note')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $x):
          $old = (string)($x['old_status'] ?? '');
          $new = (string)($x['new_status'] ?? '');
          $oldH = $old !== '' ? ($statusLabel[$old] ?? $old) : '—';
          $newH = $statusLabel[$new] ?? $new;
          $note = (string)($x['note'] ?? '');
          $at   = (string)($x['changed_at'] ?? '');
        ?>
          <tr>
            <td><?= h($at) ?></td>
            <td><b><?= h($oldH) ?></b> → <b><?= h($newH) ?></b></td>
            <td class="note"><?= $note !== '' ? nl2br(h($note)) : '<span class="small">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>
<?php if ($isMobile): ?>
  <?php require __DIR__ . '/inc_mobile_footer.php'; ?>
  <script src="<?= h(app_url('/Mobilekit_v2-9-1/HTML/assets/js/lib/bootstrap.min.js')) ?>"></script>
  <script src="<?= h(app_url('/Mobilekit_v2-9-1/HTML/assets/js/base.js')) ?>"></script>
<?php endif; ?>
</body>
</html>