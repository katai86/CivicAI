<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

$token = trim($_GET['token'] ?? '');

if ($token === '' || strlen($token) < 16) {
  http_response_code(400);
  echo "Hibás vagy hiányzó token.";
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
  echo "Nem található ügy ezzel a tokennel (vagy már nem aktív).";
  exit;
}

$rid = (int)$r['id'];
$caseNo = case_number($rid, (string)$r['created_at']);

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
  'road' => 'Úthiba / kátyú',
  'sidewalk' => 'Járda / burkolat hiba',
  'lighting' => 'Közvilágítás',
  'trash' => 'Szemét / illegális',
  'green' => 'Zöldterület / veszélyes fa',
  'traffic' => 'Közlekedés / tábla',
  'idea' => 'Ötlet / javaslat'
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

?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($caseNo) ?> – Ügykövetés</title>
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#fff;
      --border:#e6eaf2;
      --muted:#6b7280;
      --shadow:0 10px 30px rgba(0,0,0,.08);
      --radius:16px;
    }
    body{ margin:0; background:var(--bg); font:14px system-ui,-apple-system,Segoe UI,Roboto,Arial; color:#111827; }
    .wrap{ max-width:980px; margin:24px auto; padding:0 12px; display:grid; gap:12px; }
    .card{ background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:16px; }
    .top{ display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; }
    .h1{ font-size:18px; font-weight:800; margin:0; }
    .meta{ color:var(--muted); margin-top:6px; line-height:1.35; }
    .grid{ display:grid; grid-template-columns: 1.2fr .8fr; gap:12px; }
    @media (max-width: 920px){ .grid{ grid-template-columns: 1fr; } }

    .pill{ display:inline-block; padding:6px 10px; border-radius:999px; border:1px solid var(--border); background:#fff; font-size:12px; }
    .pill b{ font-weight:800; }

    a{ color:#2563eb; text-decoration:none; }
    a:hover{ text-decoration:underline; }

    .kv{ display:grid; grid-template-columns: 160px 1fr; gap:8px 12px; margin-top:10px; }
    .k{ color:var(--muted); }
    .v{ font-weight:600; }

    table{ width:100%; border-collapse:collapse; }
    th, td{ text-align:left; padding:10px; border-bottom:1px solid var(--border); vertical-align:top; }
    th{ font-size:12px; color:var(--muted); font-weight:700; }
    .note{ color:#111827; }
    .small{ font-size:12px; color:var(--muted); }

    .actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .btn{
      display:inline-block; padding:10px 12px; border-radius:12px; border:1px solid var(--border);
      background:#fff; color:#111827; font-weight:700;
    }
    .btn.primary{ background:#2563eb; color:#fff; border-color:#2563eb; }
  </style>
</head>
<body>

<div class="wrap">

  <div class="card">
    <div class="top">
      <div>
        <h1 class="h1">Ügykövetés – <?= h($caseNo) ?></h1>
        <div class="meta">
          Bejelentés ID: <b>#<?= (int)$rid ?></b> • Kategória: <b><?= h($catHuman) ?></b><br>
          Létrehozva: <b><?= h($r['created_at']) ?></b> • Frissítve: <b><?= h($updatedAt) ?></b>
        </div>
      </div>
      <div class="pill">Státusz: <b><?= h($stHuman) ?></b></div>
    </div>

    <div class="grid" style="margin-top:12px">
      <div class="card" style="box-shadow:none">
        <div style="font-weight:800; margin-bottom:6px">Leírás</div>
        <div><?= nl2br(h($r['description'])) ?></div>

        <?php if (!empty($r['title'])): ?>
          <div style="margin-top:10px" class="small"><b>Rövid cím:</b> <?= h($r['title']) ?></div>
        <?php endif; ?>
      </div>

      <div class="card" style="box-shadow:none">
        <div style="font-weight:800; margin-bottom:6px">Részletek</div>

        <div class="kv">
          <div class="k">Helyszín</div>
          <div class="v">
            <?= h($r['address_approx'] ?: '—') ?><br>
            <a href="<?= h($osmUrl) ?>" target="_blank" rel="noopener">Megnyitás térképen</a>
          </div>

          <div class="k">Beküldő</div>
          <div class="v">
            <?php
              $anon = (int)$r['reporter_is_anonymous'] === 1;
              if ($anon) echo "Anonim";
              else echo h($r['reporter_name'] ?: '—');
            ?>
          </div>

          <div class="k">Értesítések</div>
          <div class="v">
            <?= ((int)$r['notify_enabled'] === 1) ? 'Bekapcsolva' : 'Kikapcsolva' ?>
          </div>
        </div>

        <div class="actions">
          <a class="btn primary" href="<?= h(app_url('/')) ?>">Publikus térkép</a>
          <a class="btn" href="<?= h($unsubscribeUrl) ?>">Leiratkozás az értesítésekről</a>
        </div>

        <div class="small" style="margin-top:10px">
          Tipp: ha kiegészítésre kérünk, elég válaszolnod az e-mailre (fotóval is lehet).
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="font-weight:800; margin-bottom:10px">Státusz-történet</div>

    <?php if (empty($logs)): ?>
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

</body>
</html>