<?php
require_once __DIR__ . '/auth.php';
logoutClient();
header('Location: /login.php');
exit;
