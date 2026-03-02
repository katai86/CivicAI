<?php
require_once __DIR__ . '/util.php';

$lbWeek = get_leaderboard('week', 10);
$lbMonth = get_leaderboard('month', 10);
$lbAll = get_leaderboard('all', 10);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Toplista</title>
  <style>
    :root{--b:#e5e7eb;--p:#2563eb;--m:#6b7280;}
    body{font:14px system-ui;background:#f6f7f9;margin:0}
    header{background:#fff;border-bottom:1px solid var(--b)}
    .wrap{max-width:1100px;margin:0 auto;padding:12px 14px}
    .top{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .card{background:#fff;border:1px solid var(--b);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:14px}
    .grid{display:grid;gap:12px}
    @media(min-width:860px){ .grid{grid-template-columns:1fr 1fr 1fr} }
    .title{font-size:16px;font-weight:800;margin:0 0 6px 0}
    .muted{color:var(--m)}
  </style>
</head>
<body>
<header>
  <div class="wrap">
    <div class="top">
      <div style="font-weight:900;font-size:18px">Toplista (Top 10)</div>
      <div><a href="<?= h(app_url('/')) ?>">← Vissza a térképre</a></div>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="grid">
    <div class="card">
      <div class="title">Heti</div>
      <?php if (!$lbWeek): ?>
        <div class="muted">Nincs adat.</div>
      <?php else: ?>
        <?php foreach ($lbWeek as $i => $row): ?>
          <div>#<?= (int)($i+1) ?> <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?> (<?= (int)$row['points'] ?> XP)</div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title">Havi</div>
      <?php if (!$lbMonth): ?>
        <div class="muted">Nincs adat.</div>
      <?php else: ?>
        <?php foreach ($lbMonth as $i => $row): ?>
          <div>#<?= (int)($i+1) ?> <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?> (<?= (int)$row['points'] ?> XP)</div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title">Összesített</div>
      <?php if (!$lbAll): ?>
        <div class="muted">Nincs adat.</div>
      <?php else: ?>
        <?php foreach ($lbAll as $i => $row): ?>
          <div>#<?= (int)($i+1) ?> <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?> (<?= (int)$row['points'] ?> XP)</div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
