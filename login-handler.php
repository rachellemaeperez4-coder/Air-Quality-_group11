<?php
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
function respond(int $status, string $message, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['message' => $message], $extra));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); respond(405, 'Please submit the login form.'); }
if (!csrf_valid()) respond(403, 'Your page has expired. Refresh it and try again.');
$role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
if (!in_array($role, ['user', 'staff'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') respond(422, 'Choose an account type and enter a valid email and password.');
if (time() - ($_SESSION['last_attempt'] ?? 0) < 2) respond(429, 'Please wait a moment before trying again.');
$_SESSION['last_attempt'] = time();
try {
    [$status, $result] = supabase('/auth/v1/token?grant_type=password', ['email' => $email, 'password' => $password]);
    if ($status === 429) respond(429, 'Too many login attempts. Please try again later.');
    if ($status !== 200) respond($status >= 500 ? 503 : 401, $status >= 500 ? 'Login service is temporarily unavailable.' : 'Unable to sign in. Check your email, password, and email confirmation.');
    if (empty($result['access_token']) || empty($result['user']['id'])) respond(503, 'Unexpected response from the login service.');
    $profile = account_profile($result['access_token'], $result['user']['id']);
    if ($profile['account_status'] !== 'Active') respond(403, 'This account is disabled. Contact your staff administrator.');
    if ($profile['role'] !== $role) respond(403, 'This account does not have access to the selected login type.');
    session_regenerate_id(true);
    $_SESSION['account'] = ['id' => $result['user']['id'], 'email' => $result['user']['email'] ?? $email, 'role' => $profile['role'], 'token' => $result['access_token'], 'expires' => time() + (int)($result['expires_in'] ?? 3600)];
    respond(200, 'Signed in successfully.', ['redirect' => ($profile['role'] === 'user' ? 'user/dashboard.php' : 'staff/dashboard.php')]);
} catch (RuntimeException $error) { respond(503, $error->getMessage()); }
