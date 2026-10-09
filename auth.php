<?php
require_once __DIR__ . '/config.php';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('Cache-Control: no-store');
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function csrf_valid(): bool {
    return is_string($_POST['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}
function supabase(string $path, ?array $body = null, ?string $token = null): array {
    $ch = curl_init(SUPABASE_URL . $path);
    $headers = ['apikey: ' . SUPABASE_KEY, 'Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) {
        $payload = $body === [] ? (object)[] : $body;
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload)]);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failed = $raw === false;
    curl_close($ch);
    if ($failed) throw new RuntimeException('Unable to reach authentication service. Please try again.');
    return [$status, json_decode($raw, true) ?? []];
}
function supabase_many(array $paths, ?string $token = null): array {
    $results = [];
    foreach (array_chunk($paths, 12, true) as $batch) {
        $multi = curl_multi_init();
        $handles = [];
        try {
            foreach ($batch as $key => $path) {
                $headers = ['apikey: ' . SUPABASE_KEY, 'Content-Type: application/json'];
                if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
                $handle = curl_init(SUPABASE_URL . $path);
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 20,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[$key] = $handle;
            }

            do {
                $multiStatus = curl_multi_exec($multi, $active);
                if ($multiStatus !== CURLM_OK) {
                    throw new RuntimeException('Unable to complete parallel database requests.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) usleep(10000);
            } while ($active > 0);

            foreach ($handles as $key => $handle) {
                $raw = curl_multi_getcontent($handle);
                if ($raw === false) {
                    throw new RuntimeException('Unable to reach the database: ' . curl_error($handle));
                }
                $results[$key] = [
                    curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    json_decode($raw, true) ?? [],
                ];
            }
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }
    return $results;
}
function account_profile(string $token, string $id): array {
    [$status, $rows] = supabase('/rest/v1/users?select=auth_user_id,role,account_status&auth_user_id=eq.' . rawurlencode($id), null, $token);
    return validate_account_profile($status, $rows);
}
function validate_account_profile(int $status, array $rows): array {
    if ($status !== 200) {
        $detail = is_string($rows['message'] ?? null) ? trim($rows['message']) : '';
        $statusHint = match ($status) {
            400 => 'Check that supabase-admin-core.sql was run and account_status is present in public.users.',
            401, 403 => 'Check the users table SELECT grant and row-level security policies.',
            default => 'Check the Supabase REST API and database setup.',
        };
        throw new RuntimeException('Account profile lookup failed (HTTP ' . $status . '). ' . $statusHint . ($detail !== '' ? ' Supabase: ' . $detail : ''));
    }
    if (count($rows) === 1 && is_string($rows[0]['role'] ?? null)) $rows[0]['role'] = strtolower(trim($rows[0]['role']));
    if (count($rows) !== 1 || !in_array($rows[0]['role'] ?? '', ['user', 'staff'], true) || !in_array($rows[0]['account_status'] ?? '', ['Active', 'Disabled'], true)) throw new RuntimeException('Your account does not have an assigned role or status. Contact your administrator.');
    return $rows[0];
}
