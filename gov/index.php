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
  $authorities = db()->query("SELECT * FROM authorities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
  $stmt = db()->prepare("
    SELECT a.*
    FROM authority_users au
    JOIN authorities a ON a.id = au.authority_id
    WHERE au.user_id = :uid
    ORDER BY a.name ASC
  ");
  $stmt->execute([':uid' => $uid]);
  $authorities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$authorityIds = array_map(fn($a) => (int)$a['id'], $authorities);

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
        }
      }
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      $err = 'Hiba történt mentés közben.';
    }
  }
}

$reports = [];
if ($isAdmin || $authorityIds) {
  $where = $isAdmin ? '1=1' : ('r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')');
  $params = $isAdmin ? [] : $authorityIds;
  $stmt = db()->prepare("
    SELECT r.id, r.category, r.title, r.description, r.status, r.created_at,
           r.address_approx, r.city, r.authority_id,
           u.display_name AS reporter_display_name, u.level AS reporter_level, u.profile_public AS reporter_profile_public
    FROM reports r
    LEFT JOIN users u ON u.id = r.user_id
    WHERE $where
    ORDER BY r.created_at DESC
    LIMIT 200
  ");
  $stmt->execute($params);
  $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Köz.Tér – Közigazgatási</title>
<link rel="stylesheet" href="/terkep/assets/style.css">
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
