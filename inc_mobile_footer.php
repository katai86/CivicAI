<?php
/**
 * Mobilekit webapp – appCapsule záró + appBottomMenu
 * Változók: $mobileActiveTab, $role, $uid
 */
$uid = isset($uid) ? (int)$uid : 0;
$role = isset($role) ? (string)$role : '';
?>
    </div>
  </div>

  <div class="appBottomMenu">
    <a href="<?= htmlspecialchars(app_url('/mobile/index.php'), ENT_QUOTES, 'UTF-8') ?>" class="item<?= ($mobileActiveTab ?? '') === 'map' ? ' active' : '' ?>">
      <div class="col">
        <i class="bi bi-map"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <a href="<?= htmlspecialchars(app_url('/user/my.php'), ENT_QUOTES, 'UTF-8') ?>" class="item<?= ($mobileActiveTab ?? '') === 'my' ? ' active' : '' ?>">
      <div class="col">
        <i class="bi bi-flag"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.my_reports'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <a href="<?= htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8') ?>" class="item<?= ($mobileActiveTab ?? '') === 'settings' ? ' active' : '' ?>">
      <div class="col">
        <i class="bi bi-gear"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <?php if (in_array($role ?? '', ['govuser', 'admin', 'superadmin'], true)): ?>
    <a href="<?= htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8') ?>" class="item<?= ($mobileActiveTab ?? '') === 'gov' ? ' active' : '' ?>">
      <div class="col">
        <i class="bi bi-building"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.gov'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <?php else: ?>
    <a href="<?= htmlspecialchars(($uid ?? 0) > 0 ? app_url('/user/profile.php?id=' . (int)($uid ?? 0)) : app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>" class="item<?= ($mobileActiveTab ?? '') === 'profile' ? ' active' : '' ?>">
      <div class="col">
        <i class="bi bi-person"></i>
        <strong class="name"><?= htmlspecialchars(($uid ?? 0) > 0 ? t('nav.profile') : t('nav.login'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <?php endif; ?>
  </div>
