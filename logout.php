<?php
require __DIR__ . '/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) { http_response_code(403); exit('Invalid sign-out request.'); }
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: index.php');
exit;
