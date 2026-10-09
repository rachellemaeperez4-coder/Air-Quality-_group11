<?php
require dirname(__DIR__) . '/dashboard-auth.php';
require_account('user');
header('Location: dashboard.php#overview');
exit;
