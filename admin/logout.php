<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
$_SESSION = [];
session_destroy();

header('Location: ' . app_url('/admin/login.php'));
exit;