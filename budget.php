<?php
/**
 * M4 Participatory Budgeting – polgári oldal: közzétett projektek listája, szavazás.
 */
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: 'guest';

if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . app_url('/budget.php'));
  exit;
}
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();

$budgetActive = function_exists('participatory_budget_enabled') && participatory_budget_enabled();
$pageTitle = t('budget.page_title');
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
    .budget-page { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
    .budget-page h1 { margin: 0 0 8px 0; font-size: 1.75rem; }
    .budget-page .intro { color: var(--muted, #6c757d); font-size: 0.95rem; margin-bottom: 24px; }
    .budget-card { border: 1px solid var(--border, #dee2e6); border-radius: 12px; padding: 16px; margin-bottom: 16px; background: var(--card, #fff); }
    .budget-card h3 { margin: 0 0 8px 0; font-size: 1.1rem; }
    .budget-card .meta { font-size: 0.85rem; color: var(--muted); margin-bottom: 8px; }
    .budget-card .desc { font-size: 0.9rem; line-height: 1.5; margin-bottom: 12px; }
    .budget-card .vote-wrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .budget-card .vote-count { font-weight: 600; }
    .budget-card .btn-vote { font-size: 0.9rem; }
    .budget-back { display: inline-block; margin-top: 24px; color: var(--primary); }
  </style>
</head>
<body data-logged-in="<?= $uid > 0 ? '1' : '0' ?>" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" data-lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>" data-app-base="<?= htmlspecialchars(defined('APP_BASE') ? APP_BASE : '/terkep', ENT_QUOTES, 'UTF-8') ?>" data-budget-active="<?= $budgetActive ? '1' : '0' ?>">

<?php $desktop_topbar_show_search = false; require __DIR__ . '/inc_desktop_topbar.php'; ?>

<main class="budget-page">
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if (!$budgetActive): ?>
  <p class="intro"><?= htmlspecialchars(t('budget.not_active'), ENT_QUOTES, 'UTF-8') ?></p>
  <div id="budgetList"></div>
  <?php else: ?>
  <p class="intro"><?= htmlspecialchars(t('budget.intro'), ENT_QUOTES, 'UTF-8') ?></p>
  <div id="budgetList"><?= htmlspecialchars(t('admin.load'), ENT_QUOTES, 'UTF-8') ?>...</div>
  <?php endif; ?>

  <a class="budget-back" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">← <?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
</main>

<script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function(){
  const BASE = document.body.dataset.appBase || '';
  const API_LIST = BASE + '/api/budget_projects_list.php';
  const API_VOTE = BASE + '/api/budget_vote.php';
  const loggedIn = document.body.dataset.loggedIn === '1';

  function t(key) { return (window.LANG && window.LANG[key]) || key; }
  function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  async function load() {
    const el = document.getElementById('budgetList');
    if (!el) return;
    try {
      const res = await fetch(API_LIST + '?status=published');
      const j = await res.json();
      const data = (j && j.data) ? j.data : [];
      const settings = (j && j.settings) ? j.settings : null;
      const userHasAddress = !!(j && j.user_has_address);
      const votingClosed = !!(settings && settings.voting_closed === 1);

      let html = '';
      if (settings && (settings.frame_amount != null || settings.description || settings.conditions_text)) {
        html += '<div class="budget-card budget-settings-block mb-3">';
        if (settings.frame_amount != null && settings.frame_amount > 0) {
          html += '<p class="mb-2"><strong>' + esc(t('budget.frame_amount') || 'Keret összeg') + ':</strong> ' + Number(settings.frame_amount).toLocaleString('hu-HU') + ' Ft</p>';
        }
        if (settings.description) html += '<div class="desc mb-2">' + esc(settings.description).replace(/\n/g, '<br>') + '</div>';
        if (settings.conditions_text) html += '<div class="small text-muted"><strong>' + esc(t('budget.conditions') || 'Feltételek, kizárások') + ':</strong><br>' + esc(settings.conditions_text).replace(/\n/g, '<br>') + '</div>';
        html += '</div>';
      }
      if (loggedIn && !userHasAddress) {
        html += '<p class="alert alert-warning small">' + esc(t('budget.address_required_hint') || 'A szavazáshoz a Beállításokban add meg a címed (város), hogy részt vehess.') + ' <a href="' + esc(BASE + '/user/settings.php') + '">' + esc(t('nav.settings') || 'Beállítások') + '</a></p>';
      }
      if (votingClosed) {
        html += '<p class="text-muted small mb-2">' + esc(t('budget.voting_closed') || 'A szavazás lezárva.') + '</p>';
      }
      if (!data.length) {
        el.innerHTML = html + '<p class="intro">' + esc(t('gov.no_data') || 'Nincs közzétett projekt.') + '</p>';
        return;
      }
      html += data.map(p => {
        const voted = !!p.voted_by_me;
        const voteLabel = voted ? (t('budget.voted') || 'Visszavonás') : (t('idea.vote') || 'Támogatom');
        let voteBtn = '<span class="text-muted small">' + esc(t('idea.login_to_vote') || 'Szavazáshoz jelentkezz be.') + '</span>';
        if (loggedIn) {
          if (votingClosed) voteBtn = '<span class="text-muted small">' + esc(t('budget.voting_closed') || 'Szavazás lezárva') + '</span>';
          else voteBtn = '<button type="button" class="btn btn-sm ' + (voted ? 'btn-outline-secondary' : 'btn-primary') + ' btn-vote" data-id="' + p.id + '" data-voted="' + (voted ? '1' : '0') + '">' + esc(voteLabel) + '</button>';
        }
        return '<div class="budget-card" data-id="' + p.id + '">' +
          '<h3>' + esc(p.title) + '</h3>' +
          '<div class="meta">' + (p.authority_name ? esc(p.authority_name) + ' · ' : '') + (t('budget.budget_label') || 'Költségvetés') + ': ' + Number(p.budget).toLocaleString('hu-HU') + ' Ft</div>' +
          (p.description ? '<div class="desc">' + esc(p.description) + '</div>' : '') +
          '<div class="vote-wrap"><span class="vote-count">' + (p.vote_count || 0) + ' ' + (t('idea.votes') || 'szavazat') + '</span> ' + voteBtn + '</div>' +
          '</div>';
      }).join('');
      el.innerHTML = html;

      el.querySelectorAll('.btn-vote').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!loggedIn) return;
          const id = parseInt(btn.getAttribute('data-id'), 10);
          try {
            const r = await fetch(API_VOTE, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body: JSON.stringify({ id })
            });
            const jr = await r.json();
            if (jr && jr.ok) {
              const card = el.querySelector('.budget-card[data-id="' + id + '"]');
              const countEl = card ? card.querySelector('.vote-count') : null;
              if (countEl) countEl.textContent = (jr.count || 0) + ' ' + (t('idea.votes') || 'szavazat');
              btn.setAttribute('data-voted', jr.voted ? '1' : '0');
              btn.textContent = jr.voted ? (t('budget.voted') || 'Visszavonás') : (t('idea.vote') || 'Támogatom');
              btn.className = 'btn btn-sm ' + (jr.voted ? 'btn-outline-secondary' : 'btn-primary') + ' btn-vote';
            } else {
              alert(jr.error || 'Hiba');
            }
          } catch (e) {
            alert(e.message || 'Hiba');
          }
        });
      });
    } catch (e) {
      el.innerHTML = '<p class="intro text-danger">' + esc(e.message || 'Betöltési hiba.') + '</p>';
    }
  }

  if (document.body.dataset.budgetActive === '1') load();
})();
</script>
</body>
</html>
