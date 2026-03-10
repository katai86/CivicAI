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
