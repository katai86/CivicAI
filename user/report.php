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
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($caseNo) ?> – Saját ügy</title>
  <style>
    :root{--bg:#f5f7fb;--card:#fff;--border:#e6eaf2;--muted:#6b7280;--shadow:0 10px 30px rgba(0,0,0,.08);--r:16px;--p:#2563eb;}
    body{margin:0;background:var(--bg);font:14px system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#111827;}
    .wrap{max-width:980px;margin:24px auto;padding:0 12px;display:grid;gap:12px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);padding:16px;}
    .top{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;}
    h1{font-size:18px;font-weight:900;margin:0;}
    .meta{color:var(--muted);margin-top:6px;line-height:1.35;}
    .pill{display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:#fff;font-size:12px;}
    .pill b{font-weight:800;}
    .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:12px;}
    @media (max-width: 920px){.grid{grid-template-columns:1fr;}}
    a{color:var(--p);text-decoration:none;}
    a:hover{text-decoration:underline;}
    .btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;color:#111827;font-weight:800;text-decoration:none;}
    .btn.primary{background:var(--p);border-color:var(--p);color:#fff;}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
    table{width:100%;border-collapse:collapse;}
    th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:top;}
    th{font-size:12px;color:var(--muted);font-weight:800;}
    .small{font-size:12px;color:var(--muted);}
    .urow{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .urow input[type=file]{max-width:320px}
    .gallery{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:10px;}
    @media (max-width: 920px){.gallery{grid-template-columns:repeat(2,1fr);}}
    .thumb{border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff;}
    .thumb img{width:100%;height:160px;object-fit:cover;display:block;}
    .thumb .cap{padding:8px 10px;font-size:12px;color:var(--muted);word-break:break-word;}
  </style>
</head>
<body>

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

    <div class="grid" style="margin-top:12px">
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


<script>
(function(){
  const rid = <?= (int)$rid ?>;
  const gallery = document.getElementById('gallery');
  const file = document.getElementById('file');
  const btn = document.getElementById('uploadBtn');
  const msg = document.getElementById('upMsg');

  function esc(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }

  async function load(){
    gallery.innerHTML = '';
    try{
      const r = await fetch('<?= h(app_url('/api/report_attachments.php')) ?>?id=' + encodeURIComponent(rid), {credentials:'same-origin'});
      const j = await r.json();
      if(!j.ok || !j.data || !j.data.length){
        gallery.innerHTML = '<div class="small">Nincs csatolmány.</div>';
        return;
      }
      gallery.innerHTML = j.data.map(a => `
        <div class="thumb">
          <a href="${esc(a.url)}" target="_blank" rel="noopener">
            <img src="${esc(a.url)}" alt="">
          </a>
          <div class="cap">
            ${esc(a.filename)}<br><span class="small">${esc(a.created_at)}</span>
            <div style="margin-top:6px">
              <button class="btn" data-del="${a.id}" type="button">Törlés</button>
            </div>
          </div>
        </div>
      `).join('');

      gallery.querySelectorAll('button[data-del]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-del'));
          if (!id) return;
          if (!confirm('Biztos törlöd a képet?')) return;
          btn.disabled = true;

          try{
            const r = await fetch('<?= h(app_url('/api/report_attachment_delete.php')) ?>', {
              method:'POST',
              headers:{ 'Content-Type':'application/json' },
              credentials:'same-origin',
              body: JSON.stringify({ id })
            });
            const j2 = await r.json().catch(() => null);
            if(!j2 || !j2.ok){
              alert((j2 && j2.error) ? j2.error : 'Törlési hiba.');
            }else{
              await load();
            }
          }catch(e){
            alert('Törlési hiba.');
          }finally{
            btn.disabled = false;
          }
        });
      });
    }catch(e){
      gallery.innerHTML = '<div class="small">Hiba a csatolmányok betöltésekor.</div>';
    }
  }

  btn.addEventListener('click', async () => {
    msg.textContent = '';
    if(!file.files || !file.files[0]){ msg.textContent = 'Válassz ki egy képet!'; return; }
    btn.disabled = true;
    msg.textContent = 'Feltöltés...';

    const fd = new FormData();
    fd.append('report_id', String(rid));
    fd.append('file', file.files[0]);

    try{
      const r = await fetch('<?= h(app_url('/api/report_upload.php')) ?>', {method:'POST', body:fd, credentials:'same-origin'});
      const j = await r.json().catch(() => null);
      if(!j || !j.ok){
        msg.textContent = (j && j.error) ? j.error : 'Feltöltési hiba.';
      }else{
        msg.textContent = 'Sikeres feltöltés.';
        file.value = '';
        await load();
      }
    }catch(e){
      msg.textContent = 'Feltöltési hiba.';
    }finally{
      btn.disabled = false;
      setTimeout(()=>{ msg.textContent=''; }, 2500);
    }
  });

  load();
})();
</script>

</body>
</html>
