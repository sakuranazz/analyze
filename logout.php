<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
$_SESSION = [];
session_destroy();
redirect('login.php');
