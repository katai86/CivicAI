<?php
require_once __DIR__ . '/../util.php';
start_secure_session();
$fromGov = !empty($_GET['from_gov']);
unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['admin_logged_in']);
if ($fromGov) {
  $_SESSION['flash'] = t('auth.logout_gov_flash');
}
header('Location: ' . app_url('/'));
exit;