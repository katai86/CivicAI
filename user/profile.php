<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$uid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($uid <= 0) {
  http_response_code(400);
  echo 'Hibas felhasznalo.';
  exit;
}

$stmt = db()->prepare("
  SELECT id, display_name, total_xp, level, streak_days, avatar_filename, profile_public
  FROM users
  WHERE id = :id
  LIMIT 1
");
$stmt->execute([':id' => $uid]);
$u = $stmt->fetch();
if (!$u) {
  http_response_code(404);
  echo 'Felhasznalo nem talalhato.';
  exit;
}
if ((int)$u['profile_public'] !== 1) {
  http_response_code(403);
  echo 'Ez a profil nem nyilvanos.';
  exit;
}

$xp = (int)($u['total_xp'] ?? 0);
$lvlInfo = level_from_xp($xp);
$lvlName = $lvlInfo['name'] ?? 'Szint';
$lvlNum = (int)($u['level'] ?? $lvlInfo['level'] ?? 1);
$streak = (int)($u['streak_days'] ?? 0);

ensure_level_badge((int)$u['id'], $lvlNum);

$badges = [];
try {
  $stmt = db()->prepare("
    SELECT b.code, b.name, b.icon
    FROM user_badges ub
    JOIN badges b ON b.id = ub.badge_id
    WHERE ub.user_id = :uid
    ORDER BY ub.earned_at DESC, ub.id DESC
    LIMIT 50
  ");
  $stmt->execute([':uid' => $uid]);
  $badges = $stmt->fetchAll() ?: [];
} catch (Throwable $e) { $badges = []; }

$reports = [];
try {
  $stmt = db()->prepare("
    SELECT id, category, title, description, status, created_at
    FROM reports
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 50
  ");
  $stmt->execute([':uid' => $uid]);
  $reports = $stmt->fetchAll() ?: [];
} catch (Throwable $e) { $reports = []; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge_icon_url($code){
  if (!$code) return null;
  $base = __DIR__ . '/../assets/badges/' . $code;
  $pathLower = $base . '.png';
  $pathUpper = $base . '.PNG';
  if (is_file($pathLower)) return app_url('/assets/badges/' . $code . '.png');
  if (is_file($pathUpper)) return app_url('/assets/badges/' . $code . '.PNG');
  return null;
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Profil</title>
  <style>
    :root{--b:#e5e7eb;--p:#2563eb;--m:#6b7280;}
    body{font:14px system-ui;background:#f6f7f9;margin:0}
    .wrap{max-width:1000px;margin:0 auto;padding:18px}
    .card{background:#fff;border:1px solid var(--b);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:14px}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .pill{display:inline-block;padding:3px 10px;border-radius:999px;border:1px solid var(--b);background:#f9fafb;font-size:12px}
    .muted{color:var(--m)}
    .grid{display:grid;gap:12px}
    @media(min-width:860px){ .grid{grid-template-columns:1fr 1fr} }
    .title{font-size:16px;font-weight:800;margin:0 0 6px 0}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row">
      <?php if (!empty($u['avatar_filename'])): ?>
        <img src="<?= h(app_url('/uploads/avatars/' . $u['avatar_filename'])) ?>" alt="avatar" style="width:72px;height:72px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:999px;border:1px solid #e5e7eb;background:#f3f4f6;display:grid;place-items:center;color:#6b7280">?</div>
      <?php endif; ?>
      <div>
        <div style="font-weight:900;font-size:18px"><?= h($u['display_name'] ?: ('User #' . $u['id'])) ?></div>
        <div class="row" style="margin-top:6px">
          <span class="pill">Szint: <b><?= h($lvlName) ?></b> (#<?= (int)$lvlNum ?>)</span>
          <span class="pill">XP: <b><?= (int)$xp ?></b></span>
          <span class="pill">Streak: <b><?= (int)$streak ?></b> nap</span>
        </div>
      </div>
    </div>
  </div>

  <div class="grid" style="margin-top:12px">
    <div class="card">
      <div class="title">Jelvenyek</div>
      <?php if (!$badges): ?>
        <div class="muted">Meg nincs jelvenye.</div>
      <?php else: ?>
        <div class="row" style="margin-top:8px">
          <?php foreach ($badges as $b): ?>
            <?php
              $code = (string)($b['code'] ?? '');
              $isLevel = strpos($code, 'level_') === 0;
              $burl = $code ? badge_icon_url($code) : null;
              $imgSize = $isLevel ? 64 : 20;
            ?>
            <span class="pill" style="<?= $isLevel ? 'padding:8px 12px;gap:8px;display:inline-flex;align-items:center' : '' ?>">
              <?php if ($burl): ?>
                <img src="<?= h($burl) ?>" alt="" style="width:<?= (int)$imgSize ?>px;height:<?= (int)$imgSize ?>px;vertical-align:middle;margin-right:<?= $isLevel ? '0' : '6px' ?>">
              <?php else: ?>
                <?= h($b['icon'] ?: '🏅') ?>
              <?php endif; ?>
              <?php if (!$isLevel): ?>
                <?= h($b['name']) ?>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title">Utolso bejelentesek</div>
      <?php if (!$reports): ?>
        <div class="muted">Nincs megjelenitheto bejelentes.</div>
      <?php else: ?>
        <?php foreach ($reports as $r): ?>
          <div style="margin-bottom:10px">
            <b>#<?= (int)$r['id'] ?></b> <?= h($r['title'] ?: '') ?><br>
            <span class="muted"><?= h($r['created_at']) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
