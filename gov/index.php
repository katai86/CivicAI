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

$statusFilter = isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : '';
$allowedStatuses = ['pending','approved','rejected','new','needs_info','forwarded','waiting_reply','in_progress','solved','closed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = '';
}

// Status update
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
        if (!$isAdmin && (!$aid || !in_array($aid, $authorityIds, true))) {
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
$where = $isAdmin ? '1=1' : ('r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')');
$params = $isAdmin ? [] : array_values($authorityIds);
if ($statusFilter !== '') {
  $where .= ' AND r.status = ?';
  $params[] = $statusFilter;
}

if ($isAdmin || $authorityIds) {
  $pdo = db();
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

  // Statisztika: csak a városhoz / hatósághoz tartozó bejelentések (szűrés nélkül)
  $baseWhere = $isAdmin ? '1=1' : ('r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')');
  $baseParams = $isAdmin ? [] : array_values($authorityIds);
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

$statusLabels = [
  'new' => 'Új', 'pending' => 'Ellenőrzés alatt', 'approved' => 'Publikálva', 'rejected' => 'Elutasítva',
  'needs_info' => 'Kiegészítésre vár', 'forwarded' => 'Továbbítva', 'waiting_reply' => 'Válaszra vár',
  'in_progress' => 'Folyamatban', 'solved' => 'Megoldva', 'closed' => 'Lezárva',
];
$categoryLabels = [
  'road' => 'Úthiba', 'sidewalk' => 'Járda', 'lighting' => 'Közvilágítás', 'trash' => 'Szemét',
  'green' => 'Zöld', 'traffic' => 'Közlekedés', 'idea' => 'Ötlet', 'civil_event' => 'Civil esemény',
];

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Köz.Tér – Közigazgatási</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= h(app_url('/')) ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>Köz.Tér</b>
    </a>
    <div class="topbar-links">
      <a class="topbtn" href="<?= h(app_url('/')) ?>">Térkép</a>
      <a class="topbtn" href="<?= h(app_url('/user/my.php')) ?>">Saját ügyeim</a>
      <a class="topbtn" href="<?= h(app_url('/user/logout.php')) ?>">Kilépés</a>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div style="font-weight:900;font-size:18px">Közigazgatási dashboard</div>
        <div class="muted">Hatóságok: <?= h(implode(', ', array_map(fn($a)=>$a['name'], $authorities))) ?></div>
      </div>
      <a class="btn" href="<?= h(app_url('/')) ?>">Térkép</a>
    </div>

    <?php if($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>
    <?php if($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

    <?php if(!$isAdmin && !$authorityIds): ?>
      <div class="muted">Nincs hatóság hozzárendelve ehhez a fiókhoz.</div>
    <?php else: ?>
      <section class="gov-stats" aria-label="Statisztika">
        <h3 style="margin:0 0 12px 0; font-size:1rem">Statisztika – a városhoz tartozó bejelentések</h3>
        <div class="gov-stats-row">
          <div class="gov-stat-box">
            <span class="gov-stat-value"><?= (int)$stats['reports_1d'] ?></span>
            <span class="gov-stat-label">Ma</span>
          </div>
          <div class="gov-stat-box">
            <span class="gov-stat-value"><?= (int)$stats['reports_7d'] ?></span>
            <span class="gov-stat-label">Elmúlt 7 nap</span>
          </div>
          <div class="gov-stat-box">
            <span class="gov-stat-value"><?= (int)$stats['reports_total'] ?></span>
            <span class="gov-stat-label">Összesen</span>
          </div>
        </div>
        <div class="gov-stats-grid">
          <div>
            <h4 class="gov-stats-sub">Státusz megoszlás</h4>
            <ul class="gov-stats-list">
              <?php foreach ($stats['by_status'] as $st => $cnt): ?>
                <li><?= h($statusLabels[$st] ?? $st) ?>: <strong><?= (int)$cnt ?></strong></li>
              <?php endforeach; ?>
              <?php if (empty($stats['by_status'])): ?>
                <li class="muted">Nincs adat</li>
              <?php endif; ?>
            </ul>
          </div>
          <div>
            <h4 class="gov-stats-sub">Kategória megoszlás</h4>
            <ul class="gov-stats-list">
              <?php foreach ($stats['by_category'] as $cat => $cnt): ?>
                <li><?= h($categoryLabels[$cat] ?? $cat) ?>: <strong><?= (int)$cnt ?></strong></li>
              <?php endforeach; ?>
              <?php if (empty($stats['by_category'])): ?>
                <li class="muted">Nincs adat</li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      </section>

      <h3 style="margin:24px 0 12px 0; font-size:1rem">Bejelentések lista</h3>
      <form method="get" class="gov-filter-row" style="margin-bottom:12px">
        <label for="govStatusFilter" class="muted" style="margin-right:8px">Szűrés státusz szerint:</label>
        <select id="govStatusFilter" name="status_filter" onchange="this.form.submit()" class="select">
          <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>>Összes</option>
          <?php foreach ($allowedStatuses as $st): ?>
            <option value="<?= h($st) ?>"<?= $statusFilter === $st ? ' selected' : '' ?>><?= h($statusLabels[$st] ?? $st) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <p class="muted small" style="margin:0 0 8px 0">A listában legfeljebb 200 bejelentés.</p>
      <div class="gov-list">
        <?php foreach($reports as $r): ?>
          <div class="gov-item">
            <div class="gov-meta">
              <b>#<?= (int)$r['id'] ?></b> • <?= h($r['category']) ?> • <?= h($r['status']) ?>
            </div>
            <div class="gov-title"><?= h($r['title'] ?: 'Névtelen bejelentés') ?></div>
            <div class="muted"><?= h($r['address_approx'] ?: $r['city'] ?: '') ?></div>
            <form method="post" class="gov-actions">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="status_filter" value="<?= h($statusFilter) ?>">
              <select name="status" class="select">
                <?php foreach(['new','approved','needs_info','forwarded','waiting_reply','in_progress','solved','closed','rejected','pending'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= $st === $r['status'] ? 'selected' : '' ?>><?= h($st) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="note" class="input" placeholder="Megjegyzés (opcionális)">
              <button class="btn" type="submit">Mentés</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body></html>
