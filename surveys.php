<?php
/**
 * M8 Citizen Participation – nyilvános felmérések: lista és kitöltés.
 */
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: 'guest';

if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . app_url('/surveys.php'));
  exit;
}
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();

$surveysActive = function_exists('surveys_enabled') && surveys_enabled();
$pageTitle = t('survey.page_title');
?><!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .surveys-page { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
    .surveys-page h1 { margin: 0 0 8px 0; font-size: 1.75rem; }
    .surveys-page .intro { color: var(--muted, #6c757d); font-size: 0.95rem; margin-bottom: 24px; }
    .survey-card { border: 1px solid var(--border, #dee2e6); border-radius: 12px; padding: 16px; margin-bottom: 16px; background: var(--card, #fff); cursor: pointer; }
    .survey-card h3 { margin: 0 0 8px 0; font-size: 1.1rem; }
    .survey-card .meta { font-size: 0.85rem; color: var(--muted); margin-bottom: 8px; }
    .survey-form { border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .survey-form .q { margin-bottom: 16px; }
    .survey-form label { display: block; font-weight: 600; margin-bottom: 4px; }
    .survey-form input[type="text"], .survey-form textarea { width: 100%; max-width: 100%; }
    .surveys-back { display: inline-block; margin-top: 24px; color: var(--primary); }
  </style>
</head>
<body data-logged-in="<?= $uid > 0 ? '1' : '0' ?>" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" data-lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>" data-app-base="<?= htmlspecialchars(defined('APP_BASE') ? APP_BASE : '/terkep', ENT_QUOTES, 'UTF-8') ?>" data-surveys-active="<?= $surveysActive ? '1' : '0' ?>">

<?php $desktop_topbar_show_search = false; require __DIR__ . '/inc_desktop_topbar.php'; ?>

<main class="surveys-page">
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if (!$surveysActive): ?>
  <p class="intro"><?= htmlspecialchars(t('survey.module_not_active') ?: t('survey.not_active') ?: 'A felmérések jelenleg nem aktívak.', ENT_QUOTES, 'UTF-8') ?></p>
  <div id="surveysList"></div>
  <?php else: ?>
  <p class="intro"><?= htmlspecialchars(t('survey.intro'), ENT_QUOTES, 'UTF-8') ?></p>
  <div id="surveysList"><?= htmlspecialchars(t('gov.loading'), ENT_QUOTES, 'UTF-8') ?>...</div>
  <?php endif; ?>
  <div id="surveyFormWrap" style="display:none">
    <div class="survey-form" id="surveyForm">
      <h3 id="surveyFormTitle"></h3>
      <p class="text-secondary small" id="surveyFormDesc"></p>
      <div id="surveyFormQuestions"></div>
      <button type="button" class="btn btn-primary" id="surveySubmitBtn"><?= h(t('common.save') ?: 'Küldés') ?></button>
      <button type="button" class="btn btn-outline-secondary ms-2" id="surveyFormBack">← <?= h(t('nav.map') ?: 'Vissza') ?></button>
    </div>
  </div>
  <a class="surveys-back" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">← <?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
</main>

<script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function(){
  const BASE = document.body.dataset.appBase || '';
  const API_LIST = BASE + '/api/survey.php';
  const loggedIn = document.body.dataset.loggedIn === '1';

  function t(key) { return (window.LANG && window.LANG[key]) || key; }
  function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  async function loadList() {
    const el = document.getElementById('surveysList');
    if (!el) return;
    try {
      const res = await fetch(API_LIST);
      const j = await res.json();
      const data = (j && j.data) ? j.data : [];
      if (!data.length) {
        el.innerHTML = '<p class="intro">' + esc(t('gov.no_data') || 'Nincs aktív felmérés.') + '</p>';
        return;
      }
      el.innerHTML = data.map(s => {
        const desc = (s.description || '').slice(0, 120);
        return '<div class="survey-card" data-id="' + s.id + '">' +
          '<h3>' + esc(s.title) + '</h3>' +
          '<div class="meta">' + (s.authority_name ? esc(s.authority_name) + ' · ' : '') + (s.response_count || 0) + ' ' + (t('gov.survey_responses') || 'válasz') + '</div>' +
          (desc ? '<p class="small text-secondary mb-0">' + esc(desc) + '</p>' : '') +
          '</div>';
      }).join('');
      el.querySelectorAll('.survey-card').forEach(card => {
        card.addEventListener('click', () => openSurvey(card.getAttribute('data-id')));
      });
    } catch (e) {
      el.innerHTML = '<p class="intro text-danger">' + esc(e.message || 'Betöltési hiba.') + '</p>';
    }
  }

  async function openSurvey(id) {
    if (!loggedIn) {
      alert(t('survey.login_required') || 'A kitöltéshez be kell jelentkezni.');
      return;
    }
    try {
      const res = await fetch(API_LIST + '?id=' + encodeURIComponent(id));
      const j = await res.json();
      if (!j || !j.ok) {
        alert(j.error || t('common.error_load'));
        return;
      }
      const s = j.data;
      if (s.voted_by_me) {
        alert(t('survey.already_responded') || 'Már kitöltötte a felmérést.');
        return;
      }
      document.getElementById('surveysList').style.display = 'none';
      document.getElementById('surveyFormWrap').style.display = 'block';
      document.getElementById('surveyFormTitle').textContent = s.title || '';
      document.getElementById('surveyFormDesc').textContent = s.description || '';
      const qWrap = document.getElementById('surveyFormQuestions');
      qWrap.innerHTML = (s.questions || []).map(q => {
        const name = 'q_' + q.id;
        let input = '';
        if (q.question_type === 'textarea') {
          input = '<textarea name="' + name + '" data-id="' + q.id + '" class="form-control" rows="3"></textarea>';
        } else {
          input = '<input type="text" name="' + name + '" data-id="' + q.id + '" class="form-control">';
        }
        return '<div class="q"><label>' + esc(q.question_text) + '</label>' + input + '</div>';
      }).join('');
      qWrap.setAttribute('data-survey-id', id);
    } catch (e) {
      alert(e.message || 'Hiba');
    }
  }

  document.getElementById('surveyFormBack') && document.getElementById('surveyFormBack').addEventListener('click', () => {
    document.getElementById('surveyFormWrap').style.display = 'none';
    document.getElementById('surveysList').style.display = 'block';
    loadList();
  });

  document.getElementById('surveySubmitBtn') && document.getElementById('surveySubmitBtn').addEventListener('click', async () => {
    const qWrap = document.getElementById('surveyFormQuestions');
    const surveyId = qWrap && qWrap.getAttribute('data-survey-id');
    if (!surveyId) return;
    const responses = {};
    qWrap.querySelectorAll('[data-id]').forEach(inp => {
      const qid = inp.getAttribute('data-id');
      const v = inp.value !== undefined ? inp.value : inp.textContent;
      if (qid) responses[qid] = (v || '').trim();
    });
    try {
      const res = await fetch(API_LIST, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'submit_response', survey_id: parseInt(surveyId, 10), responses })
      });
      const j = await res.json();
      if (j && j.ok) {
        alert(t('survey.thank_you') || 'Köszönjük a kitöltést!');
        document.getElementById('surveyFormBack').click();
      } else {
        alert(j.error || t('common.error_save_failed'));
      }
    } catch (e) {
      alert(e.message || 'Hiba');
    }
  });

  if (document.body.dataset.surveysActive === '1') loadList();
})();
</script>
</body>
</html>
