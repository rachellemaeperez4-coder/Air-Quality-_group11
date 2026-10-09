<?php
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
function signup_response(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['message' => $message]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); signup_response(405, 'Please submit the create account form.'); }
if (!csrf_valid()) signup_response(403, 'Your page has expired. Refresh and try again.');
if (isset($_POST['role']) && $_POST['role'] !== 'user') signup_response(403, 'Only User accounts can be created here.');
$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
if ($name === '' || mb_strlen($name) > 100) signup_response(422, 'Enter your full name (up to 100 characters).');
$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) signup_response(422, 'Enter a valid email address.');
if (strlen($password) < 8) signup_response(422, 'Use a password with at least 8 characters.');
if ($password !== $confirm) signup_response(422, 'Passwords do not match.');
if (time() - ($_SESSION['last_signup'] ?? 0) < 10) signup_response(429, 'Please wait before trying again.');
$_SESSION['last_signup'] = time();
try {
    // Roles are assigned by the database trigger, never from public signup input.
    [$status, $result] = supabase('/auth/v1/signup', ['email' => $email, 'password' => $password, 'data' => ['name' => $name]]);

    if ($status >= 200 && $status < 300) {
        signup_response(200, !empty($result['access_token'])
            ? 'Account created. Choose Back to sign in and log in as User.'
            : 'Signup request received. Check your email for a confirmation link, then sign in as User. If you already have an account, sign in instead.');
    }
    $code = $result['error_code'] ?? $result['code'] ?? 'unknown_error';
    $code = is_string($code) ? preg_replace('/[^a-zA-Z0-9_]/', '', substr($code, 0, 80)) : 'unknown_error';
    // Log only status/code: never passwords, tokens, names, or email addresses.
    error_log('AQM signup failed: HTTP ' . $status . ' code=' . $code);
    $errors = [
        'email_address_not_authorized' => 'Supabase cannot send confirmation email to this address. The administrator must configure custom SMTP for signup emails.',
        'over_email_send_rate_limit' => 'The confirmation email limit has been reached. Please wait before retrying; the administrator can configure custom SMTP.',
        'over_request_rate_limit' => 'Too many requests. Please wait before trying again.',
        'weak_password' => 'Choose a stronger password that meets the project password requirements.',
        'email_address_invalid' => 'Supabase rejected this email address. Please check it and use a valid email.',
        'validation_failed' => 'Supabase could not validate the signup details. Check your email and password.',
        'signup_disabled' => 'Account creation is disabled in Supabase. Contact your administrator.',
        'email_provider_disabled' => 'Email signup is disabled in Supabase. Contact your administrator.',
        'captcha_failed' => 'The project requires CAPTCHA verification. The administrator must connect CAPTCHA to this form.',
        'user_already_exists' => 'An account already exists for these details. Please sign in.',
        'email_exists' => 'An account already exists for these details. Please sign in.',
        'unexpected_failure' => 'Supabase could not complete signup. The administrator must check Authentication logs for database trigger or email delivery errors.',
    ];
    $message = $errors[$code] ?? ($status === 429 ? 'Too many signup requests. Please wait before trying again.' : 'Supabase rejected the signup request. Share the error code below with your administrator.');
    signup_response($status === 429 ? 429 : ($status >= 500 ? 503 : 422), $message . ' [Code: ' . $code . '; HTTP ' . $status . ']');
} catch (RuntimeException $error) { signup_response(503, $error->getMessage()); }
