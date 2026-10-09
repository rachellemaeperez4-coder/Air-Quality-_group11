<?php
require __DIR__ . '/dashboard-auth.php';
$account = require_account();
header('Location: ' . ($account['role'] === 'staff' ? 'staff/dashboard.php' : 'user/dashboard.php'));
exit;
