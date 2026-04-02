<?php
/**
 * Nyilvános API dokumentáció – Open311 és CivicAI végpontok.
 * Auth nincs; statikus tartalom + dinamikus base URL.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/util.php';

$base = app_url('');
$discovery = app_url('open311/v2/discovery.php');
$services = app_url('open311/v2/services.php');
$requests = app_url('open311/v2/requests.php');
$serviceDef = app_url('open311/v2/service_definition.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CivicAI – API dokumentáció</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 24px auto; padding: 0 16px; line-height: 1.5; }
    h1 { font-size: 1.5rem; }
    h2 { font-size: 1.15rem; margin-top: 24px; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }
    a { color: #0a5; }
    .url { word-break: break-all; }
    ul { margin: 8px 0; }
  </style>
</head>
<body>
  <h1>CivicAI – API dokumentáció</h1>
  <p>Ez a platform <strong>Open311</strong> kompatibilis API-t biztosít bejelentések fogadására és listázására. Külső alkalmazások (mobil app, partner portál) ezen végpontokon keresztül küldhetnek bejelentést vagy lekérhetik az adatokat.</p>

  <h2>Discovery (szolgáltatás felderítés)</h2>
  <p>Az Open311 szabvány szerint a discovery végpont adja meg a többi URL-t:</p>
  <p class="url"><code><?= htmlspecialchars($discovery, ENT_QUOTES, 'UTF-8') ?></code></p>
  <p><a href="<?= htmlspecialchars($discovery, ENT_QUOTES, 'UTF-8') ?>" rel="noopener">Megnyitás (JSON)</a></p>

  <h2>Végpontok</h2>
  <ul>
    <li><strong>Szolgáltatástípusok (kategóriák):</strong> <code><?= htmlspecialchars($services, ENT_QUOTES, 'UTF-8') ?></code></li>
    <li><strong>Kérések (bejelentések) – GET (lista) / POST (új):</strong> <code><?= htmlspecialchars($requests, ENT_QUOTES, 'UTF-8') ?></code></li>
    <li><strong>Szolgáltatás definíció (opcionális mezők):</strong> <code><?= htmlspecialchars($serviceDef, ENT_QUOTES, 'UTF-8') ?></code></li>
  </ul>

  <h2>Jurisdiction (multi-city)</h2>
  <p>Ha be van állítva az <code>APP_JURISDICTION_ID</code> (vagy <code>FMS_OPEN311_JURISDICTION</code>), a discovery válasz tartalmazza a <code>jurisdiction_id</code> mezőt. Több város esetén külön példány / konfig lehet városonként.</p>

  <h2>POST új bejelentés (Open311)</h2>
  <p>Küldendő mezők (pl. JSON vagy form): <code>service_code</code>, <code>description</code>, <code>lat</code>, <code>long</code>; opcionális: <code>address_string</code>, <code>email</code>, <code>first_name</code>, <code>last_name</code>. A válaszban a <code>service_request_id</code> a CivicAI belső azonosítója.</p>

  <h2>Részletes leírás</h2>
  <p>A FixMyStreet / Open311 integráció, saját API vs. külső bridge: <a href="<?= htmlspecialchars(app_url('docs/MILESTONE_7_FIXMYSTREET_OPEN311_EXPLAINED.md'), ENT_QUOTES, 'UTF-8') ?>">MILESTONE_7_FIXMYSTREET_OPEN311_EXPLAINED.md</a> (projekt docs mappában).</p>

  <p><a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>">← Vissza a térképre</a></p>
</body>
</html>
