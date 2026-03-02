<?php
require_once __DIR__ . '/../util.php';
start_secure_session();
unset($_SESSION['user_id']);
header('Location: ' . app_url('/'));
exit;