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
  echo htmlspecialchars(t('report.error_bad_id'), ENT_QUOTES, 'UTF-8');
  exit;
}

$stmt = db()->prepare("\n  SELECT\n    id, category, title, description, status, created_at,\n    address_approx, road, suburb, city, postcode,\n    lat, lng,\n    notify_enabled, notify_token\n  FROM reports\n  WHERE id = :id AND user_id = :uid\n  LIMIT 1\n");
$stmt->execute([':id' => $rid, ':uid' => $userId]);
$r = $stmt->fetch();

if (!$r) {
  http_response_code(404);
  echo htmlspecialchars(t('report.error_not_found'), ENT_QUOTES, 'UTF-8');
  exit;
}

$caseNo = case_number((int)$r['id'], (string)$r['created_at']);

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

$currentLang = function_exists('current_lang') ? current_lang() : 'hu';
$isMobile = function_exists('use_mobile_layout') ? use_mobile_layout() : false;
$uid = $userId;
$role = function_exists('current_user_role') ? (current_user_role() ?: 'user') : 'user';
?><!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,viewport-fit=cover">
  <title><?= h(t('site.name')) ?> – <?= h($caseNo) ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
  <?php if ($isMobile): ?>
  <link rel="stylesheet" href="<?= h(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(app_url('/assets/mobilekit_civicai.css')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <?php endif; ?>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="page<?= $isMobile ? ' civicai-mobile' : '' ?>"
  data-report-id="<?= (int)$rid ?>"
  data-api-attachments="<?= h(app_url('/api/report_attachments.php')) ?>"
  data-api-delete="<?= h(app_url('/api/report_attachment_delete.php')) ?>"
  data-api-upload="<?= h(app_url('/api/report_upload.php')) ?>"
>
<?php if ($isMobile): ?>
  <?php $mobilePageTitle = t('user.my_reports') . ' – ' . $caseNo; $mobileActiveTab = 'my'; $mobileBackUrl = app_url('/user/my.php'); require __DIR__ . '/../inc_mobile_header.php'; ?>
<?php else: ?>
  <?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>
<?php endif; ?>

<div class="wrap">
  <div class="card">
    <div class="top">
      <div>
        <h1><?= h(t('report.heading')) ?> – <?= h($caseNo) ?></h1>
        <div class="meta">
          <?= h(t('report.meta_id')) ?>: <b>#<?= (int)$rid ?></b> • <?= h(t('report.meta_category')) ?>: <b><?= h($catHuman) ?></b><br>
          <?= h(t('report.meta_created')) ?>: <b><?= h($r['created_at']) ?></b> • <?= h(t('report.meta_updated')) ?>: <b><?= h($updatedAt) ?></b>
        </div>
      </div>
      <div class="pill"><?= h(t('report.status_label')) ?>: <b><?= h($stHuman) ?></b></div>
    </div>

    <div class="grid cols-split" style="margin-top:12px">
      <div class="card" style="box-shadow:none">
        <div style="font-weight:900;margin-bottom:6px"><?= h(t('report.section_description')) ?></div>
        <div><?= nl2br(h($r['description'])) ?></div>

        <?php if (!empty($r['title'])): ?>
          <div style="margin-top:10px" class="small"><b><?= h(t('user.short_title')) ?>:</b> <?= h($r['title']) ?></div>
        <?php endif; ?>
      </div>

      <div class="card" style="box-shadow:none">
        <div style="font-weight:900;margin-bottom:6px"><?= h(t('report.section_location')) ?></div>
        <div class="small"><b><?= h(t('report.address_private')) ?>:</b><br><?= h($r['address_approx'] ?: '—') ?></div>
        <div class="actions">
          <a class="btn" href="<?= h($osmUrl) ?>" target="_blank" rel="noopener"><?= h(t('report.open_map')) ?></a>
          <a class="btn" href="<?= h(app_url('/user/my.php')) ?>"><?= h(t('report.back_list')) ?></a>
        </div>

        <div style="margin-top:12px;font-weight:900"><?= h(t('report.notifications')) ?></div>
        <div class="small"><?= ((int)$r['notify_enabled'] === 1) ? h(t('report.notify_on')) : h(t('report.notify_off')) ?></div>
        <?php if ($trackUrl): ?>
          <div class="actions">
            <a class="btn primary" href="<?= h($trackUrl) ?>"><?= h(t('report.track_link')) ?></a>
            <a class="btn" href="<?= h(app_url('/api/notify_unsubscribe.php?token=' . rawurlencode((string)$r['notify_token']))) ?>"><?= h(t('report.unsubscribe')) ?></a>
          </div>
        <?php endif; ?>

        <div style="margin-top:12px;font-weight:900"><?= h(t('report.attachments')) ?></div>
        <div class="small"><?= h(t('report.attachments_hint')) ?></div>

        <div class="urow" style="margin-top:8px">
          <input id="file" type="file" accept="image/*">
          <button id="uploadBtn" class="btn primary" type="button"><?= h(t('report.upload')) ?></button>
          <span id="upMsg" class="small"></span>
        </div>

        <div id="gallery" class="gallery"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="font-weight:900;margin-bottom:8px"><?= h(t('report.status_log')) ?></div>
    <?php if (!$logs): ?>
      <div class="small"><?= h(t('report.status_log_empty')) ?></div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th><?= h(t('report.col_time')) ?></th>
            <th><?= h(t('report.col_change')) ?></th>
            <th><?= h(t('report.col_note')) ?></th>
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
<?php if ($isMobile): ?>
  <?php require __DIR__ . '/../inc_mobile_footer.php'; ?>
  <script src="<?= h(app_url('/Mobilekit_v2-9-1/HTML/assets/js/lib/bootstrap.min.js')) ?>"></script>
  <script src="<?= h(app_url('/Mobilekit_v2-9-1/HTML/assets/js/base.js')) ?>"></script>
<?php endif; ?>
<script>
window.REPORT_PAGE_I18N = <?= json_encode([
  'no_attachments' => t('report.js_no_attachments'),
  'delete' => t('report.js_delete'),
  'delete_confirm' => t('report.js_delete_confirm'),
  'delete_error' => t('report.js_delete_error'),
  'load_error' => t('report.js_load_error'),
  'pick_file' => t('report.js_pick_file'),
  'uploading' => t('report.js_uploading'),
  'upload_error' => t('report.js_upload_error'),
  'upload_ok' => t('report.js_upload_ok'),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>window.CIVIC_API = <?= json_encode(['loginUrl' => app_url('/user/login.php')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?= h(app_url('/assets/api_client.js')) ?>?v=1"></script>
<script src="<?= h(app_url('/assets/user/report.js')) ?>?v=2"></script>
</body>
</html>
