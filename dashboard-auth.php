<?php
require_once __DIR__ . '/auth.php';
function require_account(?string $requiredRole = null): array {
    $account = $_SESSION['account'] ?? null;
    if (!$account || $account['expires'] <= time()) {
        unset($_SESSION['account']); header('Location: /index.php'); exit;
    }
    try {
        $responses = supabase_many([
            '/auth/v1/user',
            '/rest/v1/users?select=auth_user_id,role,account_status&auth_user_id=eq.' . rawurlencode($account['id']),
        ], $account['token']);
        [$status, $identity] = $responses[0];
        if ($status !== 200 || ($identity['id'] ?? '') !== $account['id']) {
            unset($_SESSION['account']); header('Location: /index.php'); exit;
        }
        [$profileStatus, $profileRows] = $responses[1];
        $profile = validate_account_profile($profileStatus, $profileRows);
    } catch (RuntimeException $error) {
        http_response_code(503); exit('Account verification is temporarily unavailable. Please try again later.');
    }
    if ($profile['account_status'] !== 'Active') {
        unset($_SESSION['account']);
        header('Location: /index.php?account=disabled'); exit;
    }
    if ($requiredRole !== null && $profile['role'] !== $requiredRole) {
        http_response_code(403); exit('You do not have permission to access this page.');
    }
    $account['role'] = $profile['role'];
    return $account;
}
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function aqm_thresholds(string $token): array {
    [$status, $rows] = supabase(
        '/rest/v1/aqm_threshold_settings?select=good_max,moderate_max,hazardous_max&setting_id=eq.true&limit=1',
        null,
        $token
    );
    if ($status !== 200 || !is_array($rows) || count($rows) !== 1
        || !is_int($rows[0]['good_max'] ?? null) || !is_int($rows[0]['moderate_max'] ?? null)
        || !is_int($rows[0]['hazardous_max'] ?? null)) {
        throw new RuntimeException('Air-quality thresholds could not be loaded. Run supabase-admin-hazardous-threshold.sql in the Supabase SQL Editor, then refresh.');
    }
    $goodMax = $rows[0]['good_max'];
    $moderateMax = $rows[0]['moderate_max'];
    $hazardousMax = $rows[0]['hazardous_max'];
    if ($goodMax < 0 || $goodMax >= $moderateMax || $moderateMax >= $hazardousMax || $hazardousMax > 600) {
        throw new RuntimeException('The stored air-quality thresholds are invalid. Ask Staff to correct the settings.');
    }
    return ['good_max' => $goodMax, 'moderate_max' => $moderateMax, 'hazardous_max' => $hazardousMax];
}
function aqm_quality_status($value, array $thresholds): string {
    if (!is_numeric($value) || (float)$value < 0) return 'No data';
    if ((float)$value <= $thresholds['good_max']) return 'Good';
    if ((float)$value <= $thresholds['moderate_max']) return 'Moderate';
    if ((float)$value <= $thresholds['hazardous_max']) return 'Hazardous';
    return 'Very Hazardous';
}
