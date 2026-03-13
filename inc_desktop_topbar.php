<?php
/**
 * Közös desktop topbar – brand, opcionális kereső, téma/nyelv, navigáció.
 * Kötelező változók: $uid (int), $role (string), $currentLang (string).
 * Opcionális: $desktop_topbar_show_search (bool), $rankAll (array|null).
 */
$uid = isset($uid) ? (int)$uid : 0;
$role = isset($role) ? (string)$role : 'guest';
$currentLang = isset($currentLang) ? (string)$currentLang : (function_exists('current_lang') ? current_lang() : 'hu');
$showSearch = !empty($desktop_topbar_show_search);
$rankAll = isset($rankAll) ? $rankAll : null;
?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></b>
    </a>

    <?php if ($showSearch): ?>
    <form class="topbar-search" id="mapSearchForm">
      <div class="search-wrap">
        <input id="mapSearchInput" type="search" placeholder="<?= htmlspecialchars(t('search.placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(t('search.aria'), ENT_QUOTES, 'UTF-8') ?>">
        <div id="mapSearchResults" class="search-results" role="listbox" aria-label="<?= htmlspecialchars(t('search.results_aria'), ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>
      <button type="submit" class="search-btn" aria-label="<?= htmlspecialchars(t('search.btn'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="icon-search" aria-hidden="true"></span>
        <span class="sr-only"><?= htmlspecialchars(t('search.btn'), ENT_QUOTES, 'UTF-8') ?></span>
      </button>
    </form>
    <?php endif; ?>

    <div class="topbar-right">
      <?php include __DIR__ . '/user/inc_topbar_tools.php'; ?>
      <div class="topbar-links">
        <div class="topbar-legend-wrap">
          <button type="button" class="topbtn" id="legendMenuBtn" aria-expanded="false" aria-haspopup="true"><?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?></button>
          <div id="legendPanel" class="topbar-legend-dropdown" hidden>
            <div class="legend legend-in-menu" id="legend" aria-label="<?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?>">
              <button type="button" class="legend-toggle" id="legendToggle" aria-expanded="false">
                <span class="legend-toggle-text"><?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="legend-chevron" aria-hidden="true">▼</span>
                <span class="legend-count" id="legendCount">0</span>
              </button>
              <div class="legend-body" id="legendBody">
                <div class="legend-filters-single">
                  <button class="legend-filter active" data-cat="all" type="button"><?= htmlspecialchars(t('legend.all'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
                <div class="legend-list">
                  <button type="button" class="legend-item legend-item-btn" data-cat="road"><span class="legend-badge b-road">🚧</span><span><?= htmlspecialchars(t('cat.road_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="sidewalk"><span class="legend-badge b-sidewalk">🚶</span><span><?= htmlspecialchars(t('cat.sidewalk_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="lighting"><span class="legend-badge b-lighting">💡</span><span><?= htmlspecialchars(t('cat.lighting_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="trash"><span class="legend-badge b-trash">🗑️</span><span><?= htmlspecialchars(t('cat.trash_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="green"><span class="legend-badge b-green">🌳</span><span><?= htmlspecialchars(t('cat.green_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="traffic"><span class="legend-badge b-traffic">🚦</span><span><?= htmlspecialchars(t('cat.traffic_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="idea"><span class="legend-badge b-idea">❗</span><span><?= htmlspecialchars(t('cat.idea_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                  <button type="button" class="legend-item legend-item-btn" data-cat="civil_event"><span class="legend-badge b-civil">🤝</span><span><?= htmlspecialchars(t('cat.civil_event_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
                </div>
                <div class="legend-ideas-section">
                  <div class="legend-list">
                    <span class="legend-label"><?= htmlspecialchars(t('legend.ideas_section') ?? 'Ötletek', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($uid > 0): ?>
                    <button type="button" class="legend-item legend-item-btn legend-add-idea" id="btnAddIdea"><span class="legend-badge b-idea">💡</span><span><?= htmlspecialchars(t('legend.idea_add') ?? 'Új ötlet', ENT_QUOTES, 'UTF-8') ?></span></button>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="legend-tree-section">
                  <div class="legend-list">
                    <button type="button" class="legend-item legend-item-btn legend-tree-filter active" data-tree-filter="all"><span class="legend-badge b-green">🌳</span><span><?= htmlspecialchars(t('legend.trees_all') ?? 'Összes', ENT_QUOTES, 'UTF-8') ?></span></button>
                    <button type="button" class="legend-item legend-item-btn legend-tree-filter" data-tree-filter="adopted"><span class="legend-badge b-green">🌳</span><span><?= htmlspecialchars(t('legend.trees_adopted') ?? 'Örökbefogadott', ENT_QUOTES, 'UTF-8') ?></span></button>
                    <button type="button" class="legend-item legend-item-btn legend-tree-filter" data-tree-filter="needs_water"><span class="legend-badge b-green">🌳</span><span><?= htmlspecialchars(t('legend.trees_needs_water') ?? 'Öntözést igénylő', ENT_QUOTES, 'UTF-8') ?></span></button>
                    <button type="button" class="legend-item legend-item-btn legend-tree-filter" data-tree-filter="dangerous"><span class="legend-badge b-green">🌳</span><span><?= htmlspecialchars(t('legend.trees_dangerous') ?? 'Veszélyes', ENT_QUOTES, 'UTF-8') ?></span></button>
                    <div class="legend-tree-add-wrap" id="legendTreeAddWrap" style="display:<?= $uid > 0 ? 'block' : 'none' ?>">
                      <button type="button" class="legend-item legend-item-btn legend-add-tree" id="btnAddTree"><span class="legend-badge b-green">➕</span><span><?= htmlspecialchars(t('legend.tree_add') ?? 'Új fa felvitele', ENT_QUOTES, 'UTF-8') ?></span></button>
                    </div>
                  </div>
                </div>
                <div class="legend-foot muted"><?= htmlspecialchars(t('legend.foot'), ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>
          </div>
        </div>
        <?php if (function_exists('participatory_budget_enabled') && participatory_budget_enabled()): ?>
        <a class="topbtn" href="<?= htmlspecialchars(app_url('/budget.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.budget'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endif; ?>
        <a class="topbtn" href="<?= htmlspecialchars(app_url('/faq.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.faq'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php if ($role === 'govuser'): ?>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn primary" href="<?= htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.gov'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/logout.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php elseif ($uid > 0): ?>
          <?php if ($rankAll && isset($rankAll['rank'], $rankAll['points'])): ?>
            <span class="topbtn"><?= htmlspecialchars(t('nav.rank'), ENT_QUOTES, 'UTF-8') ?>: <b>#<?= (int)$rankAll['rank'] ?></b> (<?= (int)$rankAll['points'] ?> XP)</span>
          <?php endif; ?>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/leaderboard.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.leaderboard'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/my.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.my_reports'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/friends.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.friends'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8') ?></a>
          <?php if ($role === 'admin' || $role === 'superadmin'): ?>
            <a class="topbtn" href="<?= htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.gov'), ENT_QUOTES, 'UTF-8') ?></a>
          <?php endif; ?>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/logout.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php else: ?>
          <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.login'), ENT_QUOTES, 'UTF-8') ?></a>
          <a class="topbtn primary" href="<?= htmlspecialchars(app_url('/user/register.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.register'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
