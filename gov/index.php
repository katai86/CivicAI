<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: '';

if ($uid <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if (!$isAdmin && !in_array($role, ['govuser'], true)) {
  header('Location: ' . app_url('/'));
  exit;
}

$ok = '';
$err = '';

// Load authorities for user (or all for admin)
$authorities = [];
if ($isAdmin) {
  try {
    $authorities = db()->query("SELECT * FROM authorities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $authorities = [];
  }
} else {
  try {
    $stmt = db()->prepare("
      SELECT a.*
      FROM authority_users au
      JOIN authorities a ON a.id = au.authority_id
      WHERE au.user_id = :uid
      ORDER BY a.name ASC
    ");
    $stmt->execute([':uid' => $uid]);
    $authorities = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $authorities = [];
  }
}

$authorityIds = array_map(fn($a) => (int)$a['id'], $authorities);
$authorityCities = array_values(array_filter(array_unique(array_map(function($a) {
  return trim((string)($a['city'] ?? ''));
}, $authorities))));

$statusFilter = isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : '';
$allowedStatuses = ['pending','approved','rejected','new','needs_info','forwarded','waiting_reply','in_progress','solved','closed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = '';
}

// Gov user: nem láthatja a bejelentések listáját, nem változtathat státuszt – csak statisztika
$showReportList = $isAdmin;

// Status update (csak adminnak van UI hozzá, de a backend ellenőrzi jogosultságot)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_status') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $new = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
  $note = safe_str($_POST['note'] ?? null, 255);
  $allowed = ['pending','approved','rejected','new','needs_info','forwarded','waiting_reply','in_progress','solved','closed'];

  if ($id <= 0 || !in_array($new, $allowed, true)) {
    $err = 'Érvénytelen adatok.';
  } else {
    try {
      $pdo = db();
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("SELECT * FROM reports WHERE id=:id LIMIT 1 FOR UPDATE");
      $stmt->execute([':id' => $id]);
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$r) {
        $pdo->rollBack();
        $err = 'Bejelentés nem található.';
      } else {
        $aid = (int)($r['authority_id'] ?? 0);
        $rCity = trim((string)($r['city'] ?? ''));
        $allowed = $isAdmin
          || in_array($aid, $authorityIds, true)
          || ($aid <= 0 && $rCity !== '' && in_array($rCity, $authorityCities, true));
        if (!$allowed) {
          $pdo->rollBack();
          $err = 'Nincs jogosultság.';
        } else {
          $old = (string)$r['status'];
          if ($old !== $new) {
            $pdo->prepare("UPDATE reports SET status=:st WHERE id=:id")->execute([':st'=>$new, ':id'=>$id]);
            $pdo->prepare("
              INSERT INTO report_status_log (report_id, old_status, new_status, note, changed_by)
              VALUES (:rid, :old, :new, :note, :by)
            ")->execute([
              ':rid' => $id,
              ':old' => $old,
              ':new' => $new,
              ':note' => $note,
              ':by' => $isAdmin ? 'admin' : 'govuser'
            ]);
          }
          if (!$isAdmin && $aid <= 0 && count($authorityIds) > 0) {
            $pdo->prepare("UPDATE reports SET authority_id = ? WHERE id = ?")->execute([$authorityIds[0], $id]);
          }
          $pdo->commit();

          // XP + email best-effort
          $uidReport = (int)($r['user_id'] ?? 0);
          if ($uidReport > 0) {
            if ($new === 'approved') add_user_xp($uidReport, 20, 'status_approved', (int)$r['id']);
            if ($new === 'solved') add_user_xp($uidReport, 50, 'status_solved', (int)$r['id']);
            if ($new === 'rejected') {
              $noteStr = $note ? (function_exists('mb_strtolower') ? mb_strtolower($note) : strtolower($note)) : '';
              if ($noteStr && (strpos($noteStr, 'duplik') !== false)) {
                add_user_xp($uidReport, -5, 'status_duplicate', (int)$r['id']);
              }
            }
          }

          $to = (string)($r['reporter_email'] ?? '');
          $notifyEnabled = (int)($r['notify_enabled'] ?? 0);
          $token = (string)($r['notify_token'] ?? '');
          if ($notifyEnabled === 1 && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && $token !== '') {
            $labels = [
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
            $case = case_number((int)$r['id'], (string)$r['created_at']);
            $trackUrl = app_url('/case.php?token=' . rawurlencode($token));
            $unsubscribeUrl = app_url('/api/notify_unsubscribe.php?token=' . rawurlencode($token));
            [$subject, $bodyText] = build_status_email(
              $case,
              (int)$r['id'],
              $old,
              $new,
              $labels,
              $r['title'] ? (string)$r['title'] : null,
              $r['address_approx'] ? (string)$r['address_approx'] : null,
              $note,
              $trackUrl,
              $unsubscribeUrl
            );
            send_mail($to, $subject, $bodyText);
          }

          $ok = 'Státusz frissítve.';
          $redirectFilter = isset($_POST['status_filter']) ? trim((string)$_POST['status_filter']) : '';
          if ($redirectFilter !== '' && in_array($redirectFilter, $allowedStatuses, true)) {
            header('Location: ' . app_url('/gov/index.php?status_filter=' . rawurlencode($redirectFilter)));
            exit;
          }
        }
      }
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      $err = 'Hiba történt mentés közben.';
    }
  }
}

$reports = [];
$stats = [
  'reports_1d' => 0,
  'reports_7d' => 0,
  'reports_total' => 0,
  'by_status' => [],
  'by_category' => [],
];

// Gov: hatósághoz tartozó VAGY városnév alapján (authority_id még nincs beállítva)
$govWhere = 'r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')';
$govParams = array_values($authorityIds);
if (!empty($authorityCities)) {
  $govWhere .= ' OR (r.authority_id IS NULL AND r.city IN (' . implode(',', array_fill(0, count($authorityCities), '?')) . '))';
  $govParams = array_merge($govParams, $authorityCities);
}
$baseWhere = $isAdmin ? '1=1' : $govWhere;
$baseParams = $isAdmin ? [] : $govParams;

$where = $baseWhere;
$params = $baseParams;
if ($statusFilter !== '') {
  $where .= ' AND r.status = ?';
  $params[] = $statusFilter;
}

if ($isAdmin || $authorityIds) {
  $pdo = db();
  // Lista csak adminnak (gov user csak statisztikát lát)
  if ($showReportList) {
    $listWhere = $where;
    $listParams = $params;
    $stmt = $pdo->prepare("
      SELECT r.id, r.category, r.title, r.description, r.status, r.created_at,
             r.address_approx, r.city, r.authority_id,
             u.display_name AS reporter_display_name, u.level AS reporter_level, u.profile_public AS reporter_profile_public
      FROM reports r
      LEFT JOIN users u ON u.id = r.user_id
      WHERE $listWhere
      ORDER BY r.created_at DESC
      LIMIT 200
    ");
    $stmt->execute($listParams);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Statisztika: ugyanaz a szűrő (városhoz tartozó), mind adminnak mind gov usernek
  try {
    $q0 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere");
    $q0->execute($baseParams);
    $stats['reports_total'] = (int)$q0->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $q1 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= (CURDATE())");
    $q1->execute($baseParams);
    $stats['reports_1d'] = (int)$q1->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $q7 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
    $q7->execute($baseParams);
    $stats['reports_7d'] = (int)$q7->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $qs = $pdo->prepare("SELECT r.status, COUNT(*) AS cnt FROM reports r WHERE $baseWhere GROUP BY r.status");
    $qs->execute($baseParams);
    foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $stats['by_status'][(string)$row['status']] = (int)$row['cnt'];
    }
  } catch (Throwable $e) { /* ignore */ }
  try {
    $qc = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $baseWhere GROUP BY r.category");
    $qc->execute($baseParams);
    foreach ($qc->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $stats['by_category'][(string)$row['category']] = (int)$row['cnt'];
    }
  } catch (Throwable $e) { /* ignore */ }
}

if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . app_url('/gov/index.php'));
  exit;
}
$currentLang = current_lang();
$statusLabels = [
  'new' => t('status.new'), 'pending' => t('status.pending'), 'approved' => t('status.approved'), 'rejected' => t('status.rejected'),
  'needs_info' => t('status.needs_info'), 'forwarded' => t('status.forwarded'), 'waiting_reply' => t('status.waiting_reply'),
  'in_progress' => t('status.in_progress'), 'solved' => t('status.solved'), 'closed' => t('status.closed'),
];
$categoryLabels = [
  'road' => t('cat.road_desc'), 'sidewalk' => t('cat.sidewalk_desc'), 'lighting' => t('cat.lighting_desc'), 'trash' => t('cat.trash_desc'),
  'green' => t('cat.green_desc'), 'traffic' => t('cat.traffic_desc'), 'idea' => t('cat.idea_desc'), 'civil_event' => t('cat.civil_event_desc'),
];

$statusOrder = ['new','approved','in_progress','solved','rejected','needs_info','forwarded','waiting_reply','closed','pending'];
$statusColors = [ 'new'=>'#0d6efd', 'approved'=>'#198754', 'in_progress'=>'#ffc107', 'solved'=>'#20c997', 'rejected'=>'#dc3545', 'needs_info'=>'#6f42c1', 'forwarded'=>'#fd7e14', 'waiting_reply'=>'#0dcaf0', 'closed'=>'#6c757d', 'pending'=>'#adb5bd' ];
$catColors = ['#e74c3c','#3498db','#f1c40f','#34495e','#27ae60','#9b59b6','#ff7a00','#0ea5e9'];
$maxStatus = 1;
if (!empty($stats['by_status'])) {
    $v = array_values($stats['by_status']);
    $maxStatus = $v ? max(1, max($v)) : 1;
}
$maxCategory = 1;
if (!empty($stats['by_category'])) {
    $v = array_values($stats['by_category']);
    $maxCategory = $v ? max(1, max($v)) : 1;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="<?= h($currentLang) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(t('site.name')) ?> – <?= h(t('gov.title')) ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= h(app_url('/')) ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= h(t('site.name')) ?></b>
    </a>
    <div class="topbar-right">
      <div class="topbar-tools">
        <button type="button" id="themeToggle" class="topbtn topbtn-icon" aria-label="<?= h(t('theme.aria')) ?>" title="<?= h(t('theme.dark')) ?>" data-title-light="<?= h(t('theme.light')) ?>" data-title-dark="<?= h(t('theme.dark')) ?>">
          <span class="theme-icon theme-sun" aria-hidden="true">☀️</span>
          <span class="theme-icon theme-moon" aria-hidden="true">🌙</span>
        </button>
        <div class="lang-dropdown">
          <button type="button" class="topbtn lang-btn" id="langToggle" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= h(t('lang.choose')) ?>">
            <span class="lang-label"><?= h(strtoupper($currentLang)) ?></span><span class="lang-chevron" aria-hidden="true">▼</span>
          </button>
          <div class="lang-menu" id="langMenu" role="listbox" aria-hidden="true">
            <?php foreach (LANG_ALLOWED as $code): ?>
              <a class="lang-option<?= $code === $currentLang ? ' active' : '' ?>" href="<?= h(app_url('/gov/index.php?lang=' . $code)) ?>" data-lang="<?= h($code) ?>"><?= h(strtoupper($code)) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <div class="topbar-links">
      <a class="topbtn" href="<?= h(app_url('/')) ?>"><?= h(t('nav.map')) ?></a>
      <a class="topbtn" href="<?= h(app_url('/user/settings.php')) ?>"><?= h(t('nav.settings')) ?></a>
      <a class="topbtn primary" href="<?= h(app_url('/gov/index.php')) ?>"><?= h(t('nav.gov')) ?></a>
      <a class="topbtn" href="<?= h(app_url('/user/logout.php')) ?>"><?= h(t('nav.logout')) ?></a>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div style="font-weight:900;font-size:18px"><?= h(t('gov.title')) ?></div>
        <div class="muted"><?= h(t('gov.authorities')) ?>: <?= h(implode(', ', array_map(fn($a)=>$a['name'], $authorities))) ?></div>
      </div>
      <a class="btn" href="<?= h(app_url('/')) ?>"><?= h(t('nav.map')) ?></a>
    </div>

    <?php if($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>
    <?php if($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

    <?php if(!$isAdmin && !$authorityIds): ?>
      <div class="muted"><?= h(t('gov.no_authority')) ?></div>
    <?php else: ?>
      <section class="gov-stats" aria-label="<?= h(t('gov.stats_title')) ?>">
        <h3 style="margin:0 0 12px 0; font-size:1rem"><?= h(t('gov.stats_title')) ?></h3>
        <div class="gov-stats-row">
          <div class="gov-stat-box">
            <span class="gov-stat-value"><?= (int)$stats['reports_1d'] ?></span>
            <span class="gov-stat-label"><?= h(t('gov.stat_today')) ?></span>
          </div>
          <div class="gov-stat-box">
            <span class="gov-stat-value"><?= (int)$stats['reports_7d'] ?></span>
            <span class="gov-stat-label"><?= h(t('gov.stat_7d')) ?></span>
          </div>
          <div class="gov-stat-box">
            <span class="gov-stat-value"><?= (int)$stats['reports_total'] ?></span>
            <span class="gov-stat-label"><?= h(t('gov.stat_total')) ?></span>
          </div>
        </div>
        <div class="gov-stats-grid">
          <div>
            <h4 class="gov-stats-sub"><?= h(t('gov.by_status')) ?></h4>
            <div class="gov-chart">
              <?php
              $statusItems = [];
              foreach ($statusOrder as $st) {
                if (!empty($stats['by_status'][$st])) {
                  $statusItems[] = ['k' => $st, 'cnt' => (int)$stats['by_status'][$st], 'label' => $statusLabels[$st] ?? $st, 'color' => $statusColors[$st] ?? '#6c757d'];
                }
              }
              if ($statusItems): ?>
                <?php foreach ($statusItems as $x): ?>
                <div class="gov-chart-bar">
                  <span class="label"><?= h($x['label']) ?></span>
                  <div class="bar-wrap"><div class="bar" style="width:<?= (int)round(100 * $x['cnt'] / $maxStatus) ?>%;background:<?= h($x['color']) ?>"></div></div>
                  <span class="val"><?= $x['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="muted small"><?= h(t('gov.no_data')) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <h4 class="gov-stats-sub"><?= h(t('gov.by_category')) ?></h4>
            <div class="gov-chart">
              <?php
              $catItems = $stats['by_category'];
              arsort($catItems);
              $i = 0;
              if (!empty($catItems)): ?>
                <?php foreach ($catItems as $cat => $cnt): $cnt = (int)$cnt; $color = $catColors[$i % count($catColors)]; $i++; ?>
                <div class="gov-chart-bar">
                  <span class="label"><?= h($categoryLabels[$cat] ?? $cat) ?></span>
                  <div class="bar-wrap"><div class="bar" style="width:<?= (int)round(100 * $cnt / $maxCategory) ?>%;background:<?= h($color) ?>"></div></div>
                  <span class="val"><?= $cnt ?></span>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="muted small"><?= h(t('gov.no_data')) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <?php if ($showReportList): ?>
      <h3 style="margin:24px 0 12px 0; font-size:1rem"><?= h(t('gov.reports_list')) ?></h3>
      <form method="get" class="gov-filter-row" style="margin-bottom:12px">
        <label for="govStatusFilter" class="muted" style="margin-right:8px"><?= h(t('gov.filter_status')) ?></label>
        <select id="govStatusFilter" name="status_filter" onchange="this.form.submit()" class="select">
          <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>><?= h(t('legend.all')) ?></option>
          <?php foreach ($allowedStatuses as $st): ?>
            <option value="<?= h($st) ?>"<?= $statusFilter === $st ? ' selected' : '' ?>><?= h($statusLabels[$st] ?? $st) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <p class="muted small" style="margin:0 0 8px 0"><?= h(t('gov.list_max')) ?></p>
      <div class="gov-list">
        <?php foreach($reports as $r): ?>
          <div class="gov-item">
            <div class="gov-meta">
              <b>#<?= (int)$r['id'] ?></b> • <?= h($r['category']) ?> • <?= h($r['status']) ?>
            </div>
            <div class="gov-title"><?= h($r['title'] ?: t('gov.report_anonymous')) ?></div>
            <div class="muted"><?= h($r['address_approx'] ?: $r['city'] ?: '') ?></div>
            <form method="post" class="gov-actions">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="status_filter" value="<?= h($statusFilter) ?>">
              <select name="status" class="select">
                <?php foreach(['new','approved','needs_info','forwarded','waiting_reply','in_progress','solved','closed','rejected','pending'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= $st === $r['status'] ? 'selected' : '' ?>><?= h($statusLabels[$st] ?? $st) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="note" class="input" placeholder="<?= h(t('gov.note_placeholder')) ?>">
              <button class="btn" type="submit"><?= h(t('gov.save')) ?></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <section class="gov-next" style="margin-top:24px;padding:20px;background:var(--card-2);border:1px dashed var(--border);border-radius:12px;">
        <h3 style="margin:0 0 8px 0; font-size:1rem"><?= h(t('gov.next_steps')) ?></h3>
        <p class="muted" style="margin:0 0 12px 0"><?= h(t('gov.next_intro')) ?></p>
        <ul class="muted" style="margin:0; padding-left:1.2em;">
          <li><strong><?= h(t('gov.next_street')) ?></strong></li>
          <li><strong><?= h(t('gov.next_ai')) ?></strong></li>
          <li><strong><?= h(t('gov.next_esg')) ?></strong></li>
        </ul>
        <p class="muted small" style="margin:12px 0 0 0"><?= h(t('gov.next_dashboard')) ?></p>
      </section>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body></html>
