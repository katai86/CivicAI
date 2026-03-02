<?php
require_once __DIR__ . '/../util.php';
require_admin();
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin – Problématérkép</title>

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --border:#e6eaf2;
      --muted:#6b7280;
      --shadow: 0 10px 30px rgba(0,0,0,.08);
      --radius:16px;
    }
    html, body { height:100%; margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; background:var(--bg); }
    #wrap{ height:100%; display:grid; grid-template-columns: 1fr 520px; gap:12px; padding:12px; box-sizing:border-box; }
    #map{ height:100%; border-radius:var(--radius); overflow:hidden; box-shadow: var(--shadow); }
    #side{ height:100%; display:flex; flex-direction:column; gap:12px; }

    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow: var(--shadow);
    }

    .topcard{ padding:12px; display:grid; gap:10px; }
    .row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }

    select{
      width:100%;
      box-sizing:border-box;
      padding:10px 12px;
      border:1px solid var(--border);
      border-radius:12px;
      background:#fff;
      font-size:14px;
    }

    .btnbar{ display:flex; gap:10px; }
    button{
      border:0;
      border-radius:12px;
      padding:10px 12px;
      cursor:pointer;
      font-size:14px;
      background:#eef2ff;
    }
    button.primary{ background:#2563eb; color:#fff; }
    button.soft{ background:#f3f4f6; }
    button:active{ transform: translateY(1px); }

    .counts{ display:flex; gap:10px; flex-wrap:wrap; }
    .pill{
      padding:6px 10px;
      border:1px solid var(--border);
      border-radius:999px;
      font-size:12px;
      background:#fff;
    }

    .hint{
      color:var(--muted);
      font-size:12px;
      line-height:1.35;
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:center;
      flex-wrap:wrap;
    }
    .hint a{ color:#2563eb; text-decoration:none; }

    .listcard{ padding:10px; overflow:auto; flex:1; }
    .item{
      border:1px solid var(--border);
      border-radius:14px;
      padding:12px;
      margin-bottom:10px;
      background:#fff;
    }
    .item:hover{ box-shadow: 0 10px 25px rgba(0,0,0,.06); }
    .item b{ font-size:15px; }
    .meta{ color:var(--muted); font-size:12px; margin-top:4px; }
    .btns{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }

    .ok{ background:#16a34a; color:#fff; }
    .no{ background:#ef4444; color:#fff; }
    .del{ background:#f3f4f6; }

    @media (max-width: 980px){
      #wrap{ grid-template-columns: 1fr; grid-template-rows: 55vh 45vh; }
      #side{ height:auto; }
    }

    .badge-marker{
      width:36px;
      height:36px;
      border-radius:999px;
      background:#fff;
      border:2px solid rgba(0,0,0,0.15);
      box-shadow:0 6px 18px rgba(0,0,0,0.18);
      display:flex;
      align-items:center;
      justify-content:center;
    }
  </style>
</head>
<body>

<div id="wrap">
  <div id="map" class="card"></div>

  <div id="side">
    <div class="card topcard">
      <div class="counts" id="counts"></div>

      <div class="row">
        <select id="status">
  <option value="new">Új</option>
  <option value="approved">Publikálva</option>
  <option value="in_progress">Megoldás alatt</option>
  <option value="solved">Megoldva</option>
  <option value="rejected">Elutasítva</option>
  <option value="pending">Pending (régi)</option>
  <option value="all">Összes</option>
</select>

        <button id="logout" class="soft" type="button">Kilépés</button>
      </div>

      <div class="row" style="grid-template-columns:1fr">
        <div class="btnbar">
          <button id="load" class="primary">Betöltés</button>
          <button id="refresh" class="soft">Frissítés</button>
        </div>
        <div class="hint">
          <div>
            Tipp: a listában egy sor fölé húzva az egeret odaugrik a térkép és megnyitja a popupot.
          </div>
          <div>
            <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank">Publikus térkép</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card listcard">
      <div id="list">Kattints: <b>Betöltés</b>.</div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  window.APP_BASE = <?= json_encode(APP_BASE, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="admin.js?v=5"></script>
</body>
</html>