<?php
require dirname(__DIR__) . '/dashboard-auth.php';
$account = require_account('staff');
$staffModule = $staffModule ?? 'overview';
$modulePages = [
    'overview' => 'dashboard.php',
    'alerts' => 'alerts.php',
    'devices' => 'devices.php',
    'sensors' => 'sensors.php',
    'users' => 'accounts.php',
    'settings' => 'thresholds.php',
    'readings' => 'readings.php',
    'audit' => 'audit.php',
];
if (!isset($modulePages[$staffModule])) {
    http_response_code(404);
    exit('Staff module not found.');
}
$currentPage = $modulePages[$staffModule];

function staff_api(string $path, string $method, string $token, ?array $body = null, string $accept = 'application/json', ?string $range = null, string $prefer = 'return=representation', ?array &$responseHeaders = null): array {
    $handle = curl_init(SUPABASE_URL . $path);
    $responseHeaders = [];
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: ' . $accept,
    ];
    if ($range !== null) {
        $headers[] = 'Range-Unit: items';
        $headers[] = 'Range: ' . $range;
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
        $headers[] = 'Prefer: ' . $prefer;
        $options[CURLOPT_HTTPHEADER] = $headers;
    } elseif ($method === 'GET' && $prefer === 'count=exact') {
        $headers[] = 'Prefer: count=exact';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $headerLine) use (&$responseHeaders): int {
        $separator = strpos($headerLine, ':');
        if ($separator !== false) {
            $name = strtolower(trim(substr($headerLine, 0, $separator)));
            $responseHeaders[$name] = trim(substr($headerLine, $separator + 1));
        }
        return strlen($headerLine);
    };
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $failed = $raw === false;
    $transportError = $failed ? curl_error($handle) : '';
    curl_close($handle);
    if ($failed) throw new RuntimeException('Unable to reach the database: ' . $transportError);
    $decoded = $raw === '' ? [] : json_decode($raw, true);
    return [$status, is_array($decoded) ? $decoded : []];
}

function staff_read(string $path, string $token): array {
    [$status, $rows] = staff_api('/rest/v1/' . $path, 'GET', $token);
    if (!in_array($status, [200, 206], true) || !array_is_list($rows)) {
        $reason = is_string($rows['message'] ?? null) ? $rows['message'] : '';
        $details = is_string($rows['details'] ?? null) ? $rows['details'] : '';
        throw new RuntimeException(
            'Database read failed for ' . strtok($path, '?') . ' (HTTP ' . $status . '). '
            . ($reason !== '' ? $reason : 'Check that the required Supabase migrations and Staff permissions are applied.')
            . ($details !== '' ? ' ' . $details : '')
        );
    }
    return $rows;
}

function staff_read_all(string $path, string $token): array {
    $rows = [];
    for ($offset = 0; ; $offset += 1000) {
        $separator = str_contains($path, '?') ? '&' : '?';
        [$status, $page] = staff_api('/rest/v1/' . $path . $separator . 'limit=1000&offset=' . $offset, 'GET', $token);
        if ($status !== 200 || !array_is_list($page)) {
            $reason = is_string($page['message'] ?? null) ? $page['message'] : '';
            throw new RuntimeException(
                'Database read failed for ' . strtok($path, '?') . ' (HTTP ' . $status . '). '
                . ($reason !== '' ? $reason : 'Check that the required Supabase migrations and Staff permissions are applied.')
            );
        }
        array_push($rows, ...$page);
        if (count($page) < 1000) return $rows;
    }
}

function staff_read_page(string $path, string $token, int $offset, int $limit): array {
    $headers = [];
    [$status, $rows] = staff_api(
        '/rest/v1/' . $path,
        'GET',
        $token,
        null,
        'application/json',
        $offset . '-' . ($offset + $limit - 1),
        'count=exact',
        $headers
    );
    if (!in_array($status, [200, 206], true) || !array_is_list($rows)) {
        $reason = is_string($rows['message'] ?? null) ? $rows['message'] : '';
        throw new RuntimeException(
            'Database read failed for ' . strtok($path, '?') . ' (HTTP ' . $status . '). '
            . ($reason !== '' ? $reason : 'Check that the required Supabase migrations and Staff permissions are applied.')
        );
    }
    if (!preg_match('~/(\d+|\*)$~', (string)($headers['content-range'] ?? ''), $matches) || $matches[1] === '*') {
        throw new RuntimeException('The database did not return the total count needed to paginate readings.');
    }
    return [$rows, (int)$matches[1]];
}

function staff_count(string $path, string $token): int {
    $headers = [];
    [$status, $rows] = staff_api(
        '/rest/v1/' . $path,
        'GET',
        $token,
        null,
        'application/json',
        '0-0',
        'count=exact',
        $headers
    );
    if (!in_array($status, [200, 206], true) || !array_is_list($rows)
        || !preg_match('~/(\d+|\*)$~', (string)($headers['content-range'] ?? ''), $matches)
        || $matches[1] === '*') {
        $reason = is_string($rows['message'] ?? null) ? $rows['message'] : '';
        throw new RuntimeException(
            'Database count failed for ' . strtok($path, '?') . ' (HTTP ' . $status . '). '
            . ($reason !== '' ? $reason : 'Check that Staff has permission to read this table.')
        );
    }
    return (int)$matches[1];
}

function staff_action_error(array $response, int $status): string {
    $message = $response['message'] ?? $response['details'] ?? $response['hint'] ?? null;
    if (!is_string($message) || $message === '') {
        return 'The database rejected the change (HTTP ' . $status . '). Check the target table columns, foreign keys, and Staff permissions.';
    }
    return 'The database rejected the change: ' . $message;
}

function staff_sensor_status($value, array $thresholds): string {
    return aqm_quality_status($value, $thresholds);
}

function staff_sensor_field_type(array $definition): string {
    $type = $definition['type'] ?? 'string';
    if (isset($definition['enum']) && is_array($definition['enum'])) return 'select';
    if ($type === 'boolean') return 'checkbox';
    if (in_array($type, ['integer', 'number'], true)) return 'number';
    return 'text';
}

function staff_manage_fields(string $table, array $rows, array $definitions): array {
    $tableDefinition = $definitions[$table] ?? $definitions['public.' . $table] ?? [];
    $properties = is_array($tableDefinition['properties'] ?? null) ? $tableDefinition['properties'] : [];
    if (!$properties && $rows) {
        foreach ($rows[0] as $column => $value) {
            $properties[$column] = ['type' => is_int($value) ? 'integer' : (is_float($value) ? 'number' : (is_bool($value) ? 'boolean' : 'string'))];
        }
    }
    if ($table === 'devices' && !$properties) {
        $properties = [
            'device_id' => ['type' => 'integer', 'readOnly' => true],
            'zone_id' => ['type' => 'integer'],
            'device_name' => ['type' => 'string'],
            'device_code' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ];
        $tableDefinition['required'] = ['zone_id', 'device_name', 'device_code'];
    }
    if ($table === 'sensors' && !$properties) {
        $properties = [
            'sensor_id' => ['type' => 'integer', 'readOnly' => true],
            'device_id' => ['type' => 'integer'],
            'sensor_name' => ['type' => 'string'],
            'sensor_type' => ['type' => 'string'],
            'unit' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ];
        $tableDefinition['required'] = ['device_id', 'sensor_name', 'sensor_type'];
    }
    $idCandidates = ['zones' => ['zone_id'], 'devices' => ['device_id'], 'sensors' => ['sensor_id', 'id']];
    $idColumn = null;
    foreach ($idCandidates[$table] ?? [] as $candidate) {
        if (isset($properties[$candidate])) { $idColumn = $candidate; break; }
    }
    if ($idColumn === null) throw new RuntimeException('The ' . $table . ' table schema or primary key could not be discovered.');
    $fields = [];
    foreach ($properties as $column => $definition) {
        if ($column === $idColumn || !is_array($definition) || !empty($definition['readOnly'])) continue;
        if (in_array(strtolower((string)$column), ['created_at', 'updated_at'], true)) continue;
        if (preg_match('/password|secret|token|api.?key/i', (string)$column)) continue;
        $fields[$column] = $definition;
    }
    return ['id' => $idColumn, 'fields' => $fields, 'properties' => $properties, 'required' => $tableDefinition['required'] ?? []];
}

function staff_posted_fields(array $fields, array $definitions, bool $isUpdate): array {
    $submitted = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
    $body = [];
    foreach ($fields as $column => $definition) {
        $type = staff_sensor_field_type($definition);
        if ($type === 'checkbox') {
            $body[$column] = isset($submitted[$column]) && $submitted[$column] === '1';
            continue;
        }
        if (!array_key_exists($column, $submitted)) continue;
        $value = is_string($submitted[$column]) ? trim($submitted[$column]) : '';
        if ($value === '') {
            if ($isUpdate && !empty($definition['nullable'])) $body[$column] = null;
            continue;
        }
        if ($type === 'number') {
            if (!is_numeric($value) || !is_finite((float)$value) || (($definition['type'] ?? '') === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false)) {
                throw new InvalidArgumentException('Enter a valid ' . (($definition['type'] ?? '') === 'integer' ? 'whole number' : 'number') . ' for ' . $column . '.');
            }
            $body[$column] = ($definition['type'] ?? '') === 'integer' ? (int)$value : (float)$value;
        } elseif ($type === 'select' && !in_array($value, $definition['enum'], true)) {
            throw new InvalidArgumentException('Choose a valid value for ' . $column . '.');
        } else {
            $body[$column] = $value;
        }
    }
    return $body;
}

function staff_valid_sensor_id(string $id, array $definition): bool {
    if (($definition['type'] ?? '') === 'integer') return preg_match('/^\d+$/', $id) === 1;
    if (($definition['format'] ?? '') === 'uuid') {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) === 1;
    }
    return preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $id) === 1;
}

function staff_value(array $row, string $key): string {
    $value = $row[$key] ?? null;
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $value === null ? '' : e((string)$value);
}

function staff_time($value): string {
    if ($value === null || $value === '') return '&mdash;';
    try { return e((new DateTimeImmutable((string)$value))->format('M j, Y · g:i A')); }
    catch (Exception) { return e((string)$value); }
}

$message = $_SESSION['staff_flash'] ?? null;
unset($_SESSION['staff_flash']);
$actionError = null;
$tableTitles = match ($staffModule) {
    'zones' => ['zones' => 'Zone'],
    'devices' => ['devices' => 'Device'],
    'sensors' => ['sensors' => 'Sensor'],
    default => [],
};
$managedTables = [];
$tableErrors = [];
$readings = []; $readingsTotal = 0; $readingsPage = 1; $readingsPages = 1; $readingsOffset = 0; $zones = []; $devices = []; $sensors = []; $settings = ['good_max' => 300, 'moderate_max' => 350, 'hazardous_max' => 500]; $settingsError = null; $alerts = []; $resolvedAlerts = []; $readingValues = []; $users = []; $auditRows = []; $deviceTokens = [];
$overviewReadings = []; $todayAlertsCount = null; $accountsCount = null; $overviewErrors = [];
$dataError = null;
try {
    $settings = aqm_thresholds($account['token']);
} catch (RuntimeException $error) {
    $settingsError = $error->getMessage();
}
$deviceZoneError = null;
$sensorDeviceError = null;
if ($tableTitles) {
    try {
        [$schemaStatus, $openApi] = staff_api('/rest/v1/', 'GET', $account['token'], null, 'application/openapi+json');
        $schemaDefinitions = $schemaStatus >= 200 && $schemaStatus < 300
            ? ($openApi['definitions'] ?? $openApi['components']['schemas'] ?? [])
            : [];
    } catch (RuntimeException $error) {
        $schemaDefinitions = [];
    }
} else {
    $schemaDefinitions = [];
}
foreach ($tableTitles as $table => $title) {
    try {
        $rows = staff_read_all($table . '?select=*', $account['token']);
        $tableConfig = staff_manage_fields($table, $rows, $schemaDefinitions);
        if ($table === 'devices') unset($tableConfig['fields']['zone_id']);
        if ($table === 'sensors') unset($tableConfig['fields']['device_id']);
        $managedTables[$table] = ['title' => $title, 'rows' => $rows] + $tableConfig;
        if ($rows) {
            usort($managedTables[$table]['rows'], static fn(array $a, array $b): int =>
                strnatcmp((string)($b[$managedTables[$table]['id']] ?? ''), (string)($a[$managedTables[$table]['id']] ?? ''))
            );
        }
    } catch (RuntimeException $error) {
        $tableErrors[$table] = $error->getMessage();
    }
}
if ($staffModule === 'devices') {
    try {
        $zoneRows = staff_read('zones?select=zone_id,zone_name&order=zone_id.asc', $account['token']);
        foreach ($zoneRows as $zone) {
            $zones[(string)$zone['zone_id']] = $zone['zone_name'] ?? ('Zone ' . $zone['zone_id']);
        }
    } catch (RuntimeException $error) {
        $deviceZoneError = $error->getMessage();
    }
}
$singleZoneId = count($zones) === 1 ? (int)array_key_first($zones) : null;
if ($staffModule === 'sensors') {
    try {
        $deviceRows = staff_read('devices?select=device_id,device_name,device_code&order=device_id.asc', $account['token']);
        foreach ($deviceRows as $device) {
            $id = (string)$device['device_id'];
            $devices[$id] = (string)$device['device_name']
                . (!empty($device['device_code']) ? ' (' . $device['device_code'] . ')' : '');
        }
    } catch (RuntimeException $error) {
        $sensorDeviceError = $error->getMessage();
    }
}
$singleDeviceId = count($devices) === 1 ? (int)array_key_first($devices) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $actionError = 'Your page has expired. Refresh and try again.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $apiPath = null; $method = ''; $body = null; $successMessage = '';
        $table = is_string($_POST['table'] ?? null) ? $_POST['table'] : '';
        $recordId = is_string($_POST['record_id'] ?? null) ? trim($_POST['record_id']) : '';
        $readingId = is_string($_POST['reading_id'] ?? null) ? trim($_POST['reading_id']) : '';

        if (in_array($action, ['create_record', 'update_record', 'delete_record'], true) && isset($managedTables[$table])) {
            $config = $managedTables[$table];
            $idDefinition = $config['properties'][$config['id']] ?? [];
            if ($action === 'create_record' || $action === 'update_record') {
                try {
                    if ($table === 'devices' && $action === 'create_record' && $deviceZoneError !== null) {
                        throw new InvalidArgumentException('Unable to load existing zones. ' . $deviceZoneError);
                    }
                    $body = staff_posted_fields($config['fields'], $config['properties'], $action === 'update_record');
                    if ($table === 'devices' && $action === 'create_record') {
                        if ($singleZoneId === null) {
                            throw new InvalidArgumentException($zones
                                ? 'More than one zone exists. Keep only the zone used by this single-zone monitor before adding a device.'
                                : 'Add the single zone used by this monitor before adding a device.');
                        }
                        $body['zone_id'] = $singleZoneId;
                    }
                    if ($table === 'devices' && ($action === 'create_record' || array_key_exists('zone_id', $body))) {
                        $zoneId = $body['zone_id'] ?? null;
                        if (!is_int($zoneId) || !array_key_exists((string)$zoneId, $zones)) {
                            throw new InvalidArgumentException('Choose an existing zone before saving this device.');
                        }
                    }
                    if ($table === 'sensors' && $action === 'create_record' && $sensorDeviceError !== null) {
                        throw new InvalidArgumentException('Unable to load existing devices. ' . $sensorDeviceError);
                    }
                    if ($table === 'sensors' && $action === 'create_record') {
                        if ($singleDeviceId === null) {
                            throw new InvalidArgumentException($devices
                                ? 'More than one device exists. Keep only the ESP32 used by this monitor before adding a sensor.'
                                : 'Add the ESP32 monitor in Device Management before adding its sensor.');
                        }
                        $body['device_id'] = $singleDeviceId;
                    }
                    if ($table === 'sensors' && ($action === 'create_record' || array_key_exists('device_id', $body))) {
                        $deviceId = $body['device_id'] ?? null;
                        if (!is_int($deviceId) || !array_key_exists((string)$deviceId, $devices)) {
                            throw new InvalidArgumentException('Choose an existing ESP32 device for this sensor.');
                        }
                    }
                    if ($action === 'create_record') {
                        foreach ($config['required'] as $requiredColumn) {
                            if (!array_key_exists($requiredColumn, $body) || $body[$requiredColumn] === '') {
                                throw new InvalidArgumentException('The ' . $requiredColumn . ' field is required.');
                            }
                        }
                    } elseif (!$body) {
                        throw new InvalidArgumentException('Enter at least one field to update.');
                    }
                    if ($action === 'create_record') {
                        $apiPath = '/rest/v1/' . $table;
                        $method = 'POST';
                        $successMessage = $config['title'] . ' added.';
                    } elseif (staff_valid_sensor_id($recordId, $idDefinition)) {
                        $apiPath = '/rest/v1/' . $table . '?' . rawurlencode($config['id']) . '=eq.' . rawurlencode($recordId);
                        $method = 'PATCH';
                        $successMessage = $config['title'] . ' updated.';
                    } else {
                        throw new InvalidArgumentException('Select a valid ' . strtolower($config['title']) . ' ID.');
                    }
                } catch (InvalidArgumentException $error) {
                    $actionError = $error->getMessage();
                }
            } elseif (staff_valid_sensor_id($recordId, $idDefinition) && ($_POST['confirm_word'] ?? '') === 'DELETE') {
                $apiPath = '/rest/v1/' . $table . '?' . rawurlencode($config['id']) . '=eq.' . rawurlencode($recordId);
                $method = 'DELETE';
                $successMessage = $config['title'] . ' deleted.';
            } else {
                $actionError = 'Enter DELETE in the confirmation field and select a valid record ID.';
            }
        } elseif ($action === 'delete_reading' && preg_match('/^\d+$/', $readingId)) {
            $apiPath = '/rest/v1/air_quality_readings?reading_id=eq.' . rawurlencode($readingId);
            $method = 'DELETE';
            $successMessage = 'Reading deleted.';
        } elseif ($action === 'set_alert_state' && $staffModule === 'alerts' && is_string($_POST['alert_id'] ?? null) && preg_match('/^\d+$/', $_POST['alert_id']) && is_string($_POST['state'] ?? null) && in_array($_POST['state'], ['Active', 'Acknowledged', 'Resolved'], true)) {
            $alertId = (string)$_POST['alert_id'];
            $newState = (string)$_POST['state'];
            $body = ['status' => $newState];
            $apiPath = '/rest/v1/alerts?alert_id=eq.' . rawurlencode($alertId);
            $method = 'PATCH';
            $successMessage = 'Alert marked ' . strtolower($newState) . '.';
        } elseif ($action === 'save_thresholds' && $staffModule === 'settings') {
            $goodMax = filter_var($_POST['good_max'] ?? null, FILTER_VALIDATE_INT);
            $moderateMax = filter_var($_POST['moderate_max'] ?? null, FILTER_VALIDATE_INT);
            $hazardousMax = filter_var($_POST['hazardous_max'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($goodMax) || !is_int($moderateMax) || !is_int($hazardousMax)
                || $goodMax < 0 || $goodMax >= $moderateMax || $moderateMax >= $hazardousMax || $hazardousMax > 600) {
                $actionError = 'Enter whole-number thresholds from 0 to 600 in order: Good maximum, Moderate maximum, then Hazardous maximum.';
            } else {
                $apiPath = '/rest/v1/rpc/aqm_staff_update_thresholds';
                $method = 'POST';
                $body = ['p_good_max' => $goodMax, 'p_moderate_max' => $moderateMax, 'p_hazardous_max' => $hazardousMax];
                $successMessage = 'Air-quality thresholds updated.';
            }
        } elseif ($action === 'update_user' && is_string($_POST['user_id'] ?? null) && ctype_digit($_POST['user_id']) && is_string($_POST['role'] ?? null) && in_array($_POST['role'], ['User', 'Staff'], true) && is_string($_POST['account_status'] ?? null) && in_array($_POST['account_status'], ['Active', 'Disabled'], true)) {
            $apiPath = '/rest/v1/rpc/aqm_staff_update_user';
            $method = 'POST';
            $body = ['p_user_id' => (int)$_POST['user_id'], 'p_role' => (string)$_POST['role'], 'p_account_status' => (string)$_POST['account_status']];
            $successMessage = 'Account role and status updated.';
        } elseif ($action === 'send_password_reset' && is_string($_POST['email'] ?? null) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) && in_array(strtolower($_POST['email']), array_map(static fn(array $user): string => strtolower((string)($user['email'] ?? '')), $users), true)) {
            try {
                [$status] = supabase('/auth/v1/recover', ['email' => (string)$_POST['email']]);
                if (!in_array($status, [200, 204], true)) throw new RuntimeException('Password reset email could not be sent (HTTP ' . $status . '). Check Supabase Auth email settings.');
                $_SESSION['staff_flash'] = 'Password reset email requested.';
                header('Location: ' . $currentPage . '#users', true, 303);
                exit;
            } catch (RuntimeException $error) {
                $actionError = $error->getMessage();
            }
        } else {
            $actionError = 'Invalid action or record ID.';
        }

        if ($actionError === null && $apiPath !== null) {
            try {
                [$status, $response] = staff_api($apiPath, $method, $account['token'], $body);
                if ($status < 200 || $status >= 300) {
                    $actionError = staff_action_error($response, $status);
                } elseif (in_array($method, ['PATCH', 'DELETE'], true) && array_is_list($response) && !$response) {
                    $actionError = 'No matching record was changed. It may already have been removed.';
                } else {
                    $_SESSION['staff_flash'] = $successMessage;
                    $target = match (true) {
                        in_array($action, ['create_record', 'update_record', 'delete_record'], true) => $table,
                        str_contains($action, 'reading') => 'readings',
                        str_contains($action, 'alert') => 'alerts',
                        str_contains($action, 'user'), str_contains($action, 'password') => 'users',
                        str_contains($action, 'threshold') => 'settings',
                        default => 'data',
                    };
                    $pageParams = [];
                    if ($staffModule === 'readings' && isset($_GET['date']) && is_string($_GET['date'])) $pageParams['date'] = $_GET['date'];
                    if ($staffModule === 'readings' && isset($_GET['page'])) $pageParams['page'] = (string)$_GET['page'];
                    $pageQuery = $pageParams ? '?' . http_build_query($pageParams) : '';
                    header('Location: ' . $currentPage . $pageQuery . '#' . $target, true, 303);
                    exit;
                }
            } catch (RuntimeException $error) {
                $actionError = $error->getMessage();
            }
        }
    }
}

if ($staffModule === 'readings' && $dataError === null) {
    try {
        $readingsPerPage = 10;
        $readingsDate = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
        if ($readingsDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $readingsDate)) {
            $readingsDate = '';
        }
        if ($readingsDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $readingsDate, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $readingsDate) {
                $readingsDate = '';
            }
        }
        $readingsPage = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
        $readingsOffset = ($readingsPage - 1) * $readingsPerPage;
        $readingsPath = 'air_quality_readings?select=reading_id,device_id,sensor_id,mq135_value,air_quality_status,recorded_at';
        if ($readingsDate !== '') {
            $start = new DateTimeImmutable($readingsDate . ' 00:00:00', new DateTimeZone('Asia/Manila'));
            $end = $start->modify('+1 day');
            $utc = new DateTimeZone('UTC');
            $readingsPath .= '&and=(recorded_at.gte.' . rawurlencode($start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'))
                . ',recorded_at.lt.' . rawurlencode($end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')) . ')';
        }
        $readingsPath .= '&order=recorded_at.desc.nullslast,reading_id.desc';
        [$readings, $readingsTotal] = staff_read_page(
            $readingsPath,
            $account['token'],
            $readingsOffset,
            $readingsPerPage
        );
        $readingsPages = max(1, (int)ceil($readingsTotal / $readingsPerPage));
        if (!$readings && $readingsPage > 1 && $readingsOffset >= $readingsTotal) {
            $readingsPage = $readingsPages;
            $readingsOffset = ($readingsPage - 1) * $readingsPerPage;
            [$readings, $readingsTotal] = staff_read_page(
                $readingsPath,
                $account['token'],
                $readingsOffset,
                $readingsPerPage
            );
        }
    } catch (RuntimeException $error) {
        $dataError = $error->getMessage();
        $readings = [];
        $readingsTotal = 0;
        $readingsPage = 1;
        $readingsPages = 1;
    }
}
foreach ($managedTables['zones']['rows'] ?? [] as $zone) {
    $id = (string)($zone['zone_id'] ?? '');
    $zones[$id] = $zone['zone_name'] ?? ('Zone ' . $id);
}
foreach ($managedTables['devices']['rows'] ?? [] as $device) {
    $id = (string)($device['device_id'] ?? '');
    $devices[$id] = $device['device_name'] ?? ('Device ' . $id);
}
if (in_array($staffModule, ['alerts', 'readings', 'audit'], true)) {
    try {
        $zoneRows = staff_read('zones?select=zone_id,zone_name&order=zone_id.asc', $account['token']);
        $deviceRows = staff_read('devices?select=device_id,device_name&order=device_id.asc', $account['token']);
        foreach ($zoneRows as $zone) $zones[(string)$zone['zone_id']] = $zone['zone_name'];
        foreach ($deviceRows as $device) $devices[(string)$device['device_id']] = $device['device_name'];
    } catch (RuntimeException $error) {
        $dataError = $error->getMessage();
    }
    if ($staffModule === 'readings') {
        try {
            $sensorRows = staff_read('sensors?select=sensor_id,sensor_name&order=sensor_id.asc', $account['token']);
            foreach ($sensorRows as $sensor) $sensors[(string)$sensor['sensor_id']] = (string)$sensor['sensor_name'];
        } catch (RuntimeException $error) {
            $dataError = $error->getMessage();
        }
    }
}
if ($staffModule === 'alerts') {
    try {
        $allAlerts = staff_read('alerts?select=alert_id,zone_id,device_id,reading_id,alert_type,severity,message,status,created_at&order=created_at.desc&limit=500', $account['token']);
        foreach ($allAlerts as $alert) {
            if (in_array(strtolower((string)($alert['status'] ?? '')), ['resolved', 'closed'], true)) $resolvedAlerts[] = $alert;
            else $alerts[] = $alert;
        }
        $readingIds = array_values(array_unique(array_filter(array_column($allAlerts, 'reading_id'), static fn($id) => $id !== null)));
        if ($readingIds) {
            $filter = rawurlencode('(' . implode(',', array_map('intval', $readingIds)) . ')');
            $readingRows = staff_read('air_quality_readings?select=reading_id,mq135_value&reading_id=in.' . $filter, $account['token']);
            foreach ($readingRows as $row) $readingValues[(string)$row['reading_id']] = $row['mq135_value'] ?? null;
        }
    } catch (RuntimeException $error) {
        $alertError = $error->getMessage();
    }
}
if ($staffModule === 'users') {
    try {
        $users = staff_read_all('users?select=user_id,auth_user_id,name,email,role,account_status,created_at&order=user_id.desc', $account['token']);
    } catch (RuntimeException $error) {
        $userError = $error->getMessage();
    }
}
if ($staffModule === 'audit') {
    try {
        $auditRows = staff_read('alerts?select=alert_id,zone_id,device_id,reading_id,alert_type,severity,message,status,created_at&order=created_at.desc&limit=100', $account['token']);
    } catch (RuntimeException $error) {
        $auditError = $error->getMessage();
    }
}
if ($staffModule === 'overview') {
    try {
        $overviewReadings = staff_read(
            'air_quality_readings?select=reading_id,zone_id,device_id,mq135_value,air_quality_status,recorded_at&order=recorded_at.desc.nullslast,reading_id.desc&limit=10',
            $account['token']
        );
    } catch (RuntimeException $error) {
        $overviewErrors[] = 'Latest readings: ' . $error->getMessage();
    }

    try {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $tomorrow = $today->modify('+1 day');
        $start = $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $end = $tomorrow->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $todayAlertsCount = staff_count(
            'alerts?select=alert_id&created_at=gte.' . rawurlencode($start) . '&created_at=lt.' . rawurlencode($end),
            $account['token']
        );
    } catch (RuntimeException $error) {
        $overviewErrors[] = 'Today’s alerts: ' . $error->getMessage();
    }

    try {
        $accountsCount = staff_count('users?select=user_id', $account['token']);
    } catch (RuntimeException $error) {
        $overviewErrors[] = 'Accounts: ' . $error->getMessage();
    }
}
if ($staffModule === 'readings' && isset($_GET['export']) && $_GET['export'] === 'readings') {
    try {
        $exportRows = staff_read_all('air_quality_readings?select=reading_id,zone_id,device_id,sensor_id,mq135_value,air_quality_status,recorded_at&order=recorded_at.desc.nullslast,reading_id.desc', $account['token']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="airsense-readings-' . gmdate('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'wb');
        if ($output === false) throw new RuntimeException('Unable to create the CSV download.');
        fputcsv($output, ['Reading ID', 'Zone ID', 'Device ID', 'Sensor ID', 'MQ-2 value', 'Stored status', 'Recorded at']);
        foreach ($exportRows as $row) {
            $values = array_map(static fn(string $key) => $row[$key] ?? '', ['reading_id', 'zone_id', 'device_id', 'sensor_id', 'mq135_value']);
            fputcsv($output, array_merge($values, array_map(static fn(string $key) => $row[$key] ?? '', ['air_quality_status', 'recorded_at'])));
        }
        fclose($output);
        exit;
    } catch (RuntimeException $error) {
        http_response_code(503);
        exit(e($error->getMessage()));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#102a43">
<title>Admin Dashboard | AirSense IoT monitoring</title>
<script>
(() => {
    try {
        if (localStorage.getItem('airsense-staff-theme') === 'dark') {
            document.documentElement.dataset.theme = 'dark';
        }
    } catch (error) {
        console.error('Could not load the saved theme preference:', error);
    }
})();
</script>
<style>
:root{color-scheme:light;--ink:#152d3d;--muted:#738493;--green:#167b67;--green-dark:#116653;--paper:#f3f6f9;--line:#e4eaf0;--card:#fff;--nav:#102a43;--nav-muted:#9bb0c1;--red:#b44444;--shadow:0 10px 28px rgba(24,49,70,.055)}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,"Segoe UI",system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit}
button,input,select{font:inherit}
.app-shell{min-height:100vh;display:grid;grid-template-columns:252px minmax(0,1fr)}
.sidebar{position:sticky;top:0;height:100vh;padding:25px 17px 18px;background:var(--nav);color:#fff;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:12px;padding:3px 10px 29px;text-decoration:none}
.brand-mark{width:39px;height:39px;display:grid;place-items:center;border-radius:12px;background:#1a806d}
.brand-mark svg{width:23px;height:23px}
.brand-name{font-size:14px;font-weight:750}
.brand-name small{display:block;margin-top:4px;color:var(--nav-muted);font-size:10px;font-weight:500;letter-spacing:1px;text-transform:uppercase}
.side-label{padding:0 12px;margin:0 0 10px;color:#8097a9;font-size:10px;font-weight:750;letter-spacing:1.45px;text-transform:uppercase}
.sidebar-nav{display:grid;gap:5px}
.nav-link{min-height:43px;padding:0 12px;display:flex;align-items:center;gap:12px;border-radius:9px;color:#c4d2dd;text-decoration:none;font-size:13px;font-weight:550}
.nav-link svg{width:17px;height:17px;flex:none;opacity:.8}
.nav-link:hover,.nav-link[aria-current=page]{background:#1d3c54;color:#fff}
.nav-link[aria-current=page]{box-shadow:inset 3px 0 #50c5a2}
.sidebar-spacer{flex:1}
.access-card{margin:12px 3px 17px;padding:13px;border:1px solid #29465c;border-radius:11px;background:#17344b}
.access-label{display:flex;align-items:center;gap:8px;color:#dce8ee;font-size:11px;font-weight:650}
.access-dot,.top-dot{width:7px;height:7px;border-radius:50%;background:#54c495;box-shadow:0 0 0 3px rgba(84,196,149,.14)}
.access-card p{margin:8px 0 0;color:#9fb3c0;font-size:11px;line-height:1.5}
.profile{display:flex;align-items:center;gap:10px;padding:14px 5px 2px;border-top:1px solid #294257}
.avatar{width:34px;height:34px;display:grid;place-items:center;border-radius:50%;background:#d8f0e6;color:#176b59;font-size:12px;font-weight:750}
.profile-copy{min-width:0}
.profile-copy strong{display:block;max-width:165px;overflow:hidden;color:#f2f6f8;font-size:11px;text-overflow:ellipsis;white-space:nowrap}
.profile-copy span{display:block;margin-top:3px;color:#9bb0c1;font-size:10px}
.workspace{min-width:0}
.topbar{min-height:69px;padding:0 clamp(22px,4vw,54px);display:flex;align-items:center;justify-content:space-between;gap:18px;background:#fff;border-bottom:1px solid var(--line)}
.breadcrumb{color:var(--muted);font-size:12px}.breadcrumb strong{color:var(--ink);font-weight:650}
.top-actions{display:flex;align-items:center;gap:16px}
.view-label{display:flex;align-items:center;gap:8px;color:#5a6e7b;font-size:11px;font-weight:600}
.top-dot{width:6px;height:6px}
.signout{padding:9px 13px;border:1px solid var(--line);border-radius:8px;background:#fff;color:#314a5b;font-size:12px;font-weight:650;cursor:pointer}
.signout:hover{background:#f8fafb}
.content{width:min(1440px,100%);margin:0 auto;padding:34px clamp(22px,4vw,54px) 46px}
.page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:23px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;color:var(--green);font-size:10px;font-weight:750;letter-spacing:1.35px;text-transform:uppercase}
.eyebrow:before{content:"";width:6px;height:6px;border-radius:50%;background:#45af87}
h1{margin:10px 0 7px;font-size:clamp(27px,3vw,35px);line-height:1.15;letter-spacing:-1.1px}
.page-heading p{margin:0;color:var(--muted);font-size:13px;line-height:1.6}
.section{margin-top:19px;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--card);box-shadow:var(--shadow)}
.section-head{min-height:69px;padding:15px 19px;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid var(--line)}
.section-head h2{margin:0;font-size:14px;font-weight:720}
.section-head p{margin:5px 0 0;color:var(--muted);font-size:11px}
.section-body{padding:17px 19px}
.sensor-add{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;align-items:end}
.field label{display:block;margin:0 0 6px;color:#647988;font-size:10px;font-weight:700;overflow-wrap:anywhere}
.field input,.field select{width:100%;height:37px;padding:0 10px;border:1px solid #dce5eb;border-radius:7px;background:#fff;color:#304a5b;font-size:11px}
.field input:focus,.field select:focus{outline:3px solid rgba(82,195,159,.2);border-color:#52a98c}
.check-field{min-height:37px;display:flex;align-items:center;gap:8px;color:#526977;font-size:11px}
.check-field input{width:16px;height:16px}
.button{min-height:36px;padding:0 13px;border:1px solid var(--green);border-radius:7px;background:var(--green);color:#fff;font-size:11px;font-weight:700;cursor:pointer}
.button:hover{background:var(--green-dark)}
.threshold-form{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;margin:20px 0}
.threshold-form .field{width:min(220px,100%)}
.button:disabled{opacity:.55;cursor:not-allowed}
.button.secondary{border-color:var(--line);background:#fff;color:#405969}
.button.secondary:hover{background:#f8fafb}
.button.danger{border-color:#e7c8c6;background:#fff;color:var(--red)}
.button.danger:hover{background:#fff4f3}
.table-scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;text-align:left}
.sensor-table{min-width:760px}.reading-table{min-width:950px}
th{padding:12px 14px;background:#f8fafb;color:#738592;font-size:9px;font-weight:750;letter-spacing:.7px;text-transform:uppercase}
td{padding:12px 14px;border-top:1px solid #edf1f4;color:#304a5b;font-size:11px;vertical-align:middle}
td small{display:block;margin-top:4px;color:#91a0a9;font-size:9px}
.edit-grid{display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:7px;min-width:240px}
.edit-grid input,.edit-grid select{height:32px;padding:0 8px;border:1px solid #dce5eb;border-radius:6px;color:#304a5b;font-size:10px}
.edit-actions{display:flex;align-items:center;gap:6px}
.empty-cell{padding:38px 20px!important;color:var(--muted);text-align:center}
.notice{margin:0 0 16px;padding:13px 15px;border:1px solid #cde7dc;border-radius:9px;background:#eff9f4;color:#246347;font-size:12px;line-height:1.6}
.error{margin:0 0 16px;padding:13px 15px;border:1px solid #f1d2ba;border-radius:9px;background:#fff5ed;color:#8b471e;font-size:12px;line-height:1.6}
.footnote{margin:13px 0 0;color:#84939e;font-size:10px;line-height:1.6}
html[data-theme="dark"]{color-scheme:dark;--ink:#e5efec;--muted:#a0b3ae;--green:#57c69a;--green-dark:#25866a;--paper:#101a18;--line:#30423d;--card:#192521;--nav:#0c1d1a;--nav-muted:#9cb2aa;--red:#f28d87;--shadow:0 10px 28px rgba(0,0,0,.22)}
html[data-theme="dark"] body{background:var(--paper);color:var(--ink)}
html[data-theme="dark"] .topbar{background:#17231f;border-color:var(--line)}
html[data-theme="dark"] .breadcrumb strong{color:var(--ink)}
html[data-theme="dark"] .view-label{color:#c0d0ca}
html[data-theme="dark"] .signout,html[data-theme="dark"] .button.secondary{background:#1e302a;border-color:#3a5148;color:#dbe8e2}
html[data-theme="dark"] .signout:hover,html[data-theme="dark"] .button.secondary:hover{background:#294138}
html[data-theme="dark"] .section,html[data-theme="dark"] .overview-stat,html[data-theme="dark"] .module-card,html[data-theme="dark"] .alert-card{background:var(--card);border-color:var(--line)}
html[data-theme="dark"] .section-head{border-color:var(--line)}
html[data-theme="dark"] .field label,html[data-theme="dark"] .check-field{color:#b5c6c0}
html[data-theme="dark"] .field input,html[data-theme="dark"] .field select,html[data-theme="dark"] .edit-grid input,html[data-theme="dark"] .edit-grid select{background:#101a17;border-color:#40564d;color:var(--ink)}
html[data-theme="dark"] th{background:#202f2a;color:#b0c1bb}
html[data-theme="dark"] td{border-color:#2d3d37;color:#d1ded8}
html[data-theme="dark"] td small,html[data-theme="dark"] .footnote{color:#91a69e}
html[data-theme="dark"] .notice{background:#17372b;border-color:#2d6249;color:#b4e5c7}
html[data-theme="dark"] .error{background:#392622;border-color:#75463d;color:#ffc4ae}
html[data-theme="dark"] .status-pill{background:#263b32;color:#c7e2d2}
.theme-toggle{width:42px;height:36px;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:0 8px;border:1px solid var(--line);border-radius:8px;background:#fff;color:#405969;cursor:pointer}
.theme-toggle:hover{background:#f1f6f4}
.theme-toggle svg{width:17px;height:17px;flex:none}
.theme-toggle-track{width:24px;height:14px;position:relative;border-radius:999px;background:#bdc9c4;transition:background .2s ease}
.theme-toggle-track:after{content:"";position:absolute;top:2px;left:2px;width:10px;height:10px;border-radius:50%;background:#fff;transition:transform .2s ease}
html[data-theme="dark"] .theme-toggle{background:#1e302a;border-color:#3a5148;color:#e0ece6}
html[data-theme="dark"] .theme-toggle:hover{background:#294138}
html[data-theme="dark"] .theme-toggle-track{background:#25866a}
html[data-theme="dark"] .theme-toggle-track:after{transform:translateX(10px)}
.theme-toggle:focus-visible{outline:3px solid rgba(82,195,159,.55);outline-offset:3px}
@media(max-width:1050px){.app-shell{grid-template-columns:220px minmax(0,1fr)}.sidebar{padding-right:13px;padding-left:13px}.sensor-add{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.app-shell{display:block}.sidebar{position:static;height:auto;padding:13px 18px 10px;display:grid;grid-template-columns:1fr auto;gap:10px 16px}.brand{padding:0;align-self:center}.brand-mark{width:35px;height:35px}.side-label,.sidebar-spacer,.access-card,.profile{display:none}.sidebar-nav{grid-column:1/-1;display:flex;gap:5px;overflow-x:auto;padding:2px 0 3px;scrollbar-width:thin}.nav-link{min-height:37px;padding:0 10px;gap:8px;font-size:11px;white-space:nowrap}.nav-link[aria-current=page]{box-shadow:inset 0 -2px #50c5a2}.topbar{min-height:55px;padding:0 18px}.content{padding:26px 18px 35px}}
@media(max-width:500px){.topbar{padding:0 12px;gap:8px}.top-actions{gap:7px}.view-label{display:none}.theme-toggle{width:38px;height:34px}.signout{padding:8px 10px}}
@media(max-width:500px){.brand-name{font-size:12px}.brand-name small{font-size:9px}.top-actions{gap:9px}.view-label{font-size:0}.view-label .top-dot{width:7px;height:7px}.signout{padding:8px 10px;font-size:11px}.content{padding:22px 14px 30px}.page-heading{align-items:flex-start;flex-direction:column;gap:12px}.sensor-add{grid-template-columns:1fr}.section-body{padding:13px}.section-head{padding:13px}.edit-grid{min-width:200px;grid-template-columns:1fr}.edit-actions{flex-wrap:wrap}}
 .management-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;align-items:end}
.status-pill{display:inline-flex;padding:5px 9px;border-radius:99px;background:#eef2f4;color:#566c78;font-size:10px;font-weight:700}
.status-pill.good,.status-pill.resolved{background:#e7f6ef;color:#18704d}
.status-pill.moderate,.status-pill.acknowledged{background:#fff4d9;color:#8d6511}
.status-pill.hazardous,.status-pill.active{background:#ffebea;color:#aa3835}
.alert-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:17px 19px}
.alert-card{padding:15px;border:1px solid #f0cfcd;border-left:4px solid #c74a45;border-radius:9px;background:#fffafa}
.alert-card h3{margin:0;color:#a53d39;font-size:13px}
.alert-card p{margin:8px 0 0;color:#526977;font-size:11px;line-height:1.6}
.inline-form{display:flex;align-items:end;gap:9px;flex-wrap:wrap}
.inline-form .field{min-width:150px}
.audit-details{max-width:360px;white-space:normal}
.audit-details pre{max-height:180px;overflow:auto;white-space:pre-wrap;font-size:10px}
.wide-table{min-width:900px}
.module-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.overview-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0 0 19px}
.overview-stat{min-height:120px;padding:19px;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:var(--shadow)}
.overview-stat span{display:block;color:var(--muted);font-size:11px;font-weight:700}
.overview-stat strong{display:block;margin-top:12px;color:var(--ink);font-size:29px;line-height:1;font-variant-numeric:tabular-nums}
.overview-stat small{display:block;margin-top:9px;color:#84939e;font-size:10px}
.overview-reading-table{min-width:700px}
.module-card{min-height:145px;padding:19px;display:flex;flex-direction:column;align-items:flex-start;gap:9px;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:var(--shadow);text-decoration:none}
.module-card span{font-size:15px;font-weight:750}
.module-card small{color:var(--muted);font-size:11px;line-height:1.55}
.module-card strong{margin-top:auto;color:var(--green);font-size:10px}
.module-card:hover{border-color:#a8d5c5;transform:translateY(-1px)}
.setup-steps{margin:0 0 18px;padding:18px 20px;border:1px solid #cde7dc;border-radius:12px;background:#eff9f4;color:#305a4d;font-size:12px;line-height:1.65}
.setup-steps h2{margin:0 0 10px;color:var(--ink);font-size:14px}
.setup-steps ol{margin:0;padding-left:20px}
.setup-steps li+li{margin-top:5px}
.setup-steps a{color:var(--green-dark);font-weight:700}
@media(max-width:1050px){.management-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.alert-grid{grid-template-columns:1fr}}
@media(max-width:900px){.module-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.overview-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:500px){.management-grid,.module-grid,.overview-stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app-shell">
<aside class="sidebar" aria-label="Staff navigation">
    <a class="brand" href="dashboard.php" aria-label="AirSense staff dashboard"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 14.5a4 4 0 0 1 3.5-3.97A5.5 5.5 0 0 1 18 9.5a3.5 3.5 0 1 1 .5 7H7.5A3.5 3.5 0 0 1 4 14.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 19h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span class="brand-name">AirSense<small>Staff console</small></span></a>
    <div class="side-label">Management</div>
    <nav class="sidebar-nav" aria-label="Staff dashboard navigation">
        <?php foreach (['overview'=>'Overview','alerts'=>'Alerts','devices'=>'ESP32 device','sensors'=>'Sensor setup','users'=>'Accounts','settings'=>'Thresholds','readings'=>'Readings','audit'=>'Alert history'] as $id=>$label): ?>
        <a class="nav-link" href="<?= e($modulePages[$id]) ?>" <?= $staffModule === $id ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-spacer"></div>
    <div class="access-card"><div class="access-label"><span class="access-dot" aria-hidden="true"></span>Staff access</div><p>Manage the ESP32 monitor, air-quality alerts, thresholds, accounts, and readings.</p></div>
    <div class="profile"><span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($account['email'], 0, 1))) ?></span><div class="profile-copy"><strong><?= e($account['email']) ?></strong><span>Staff administrator</span></div></div>
</aside>
<div class="workspace">
    <header class="topbar"><div class="breadcrumb">Workspace <span aria-hidden="true">/</span> <strong>Staff management</strong></div><div class="top-actions"><span class="view-label"><span class="top-dot" aria-hidden="true"></span>Staff access</span><button class="theme-toggle" type="button" id="theme-toggle" role="switch" aria-checked="false" aria-label="Dark mode" title="Toggle dark mode"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.3 15.4A8.5 8.5 0 0 1 8.6 3.7 8.5 8.5 0 1 0 20.3 15.4Z"/></svg><span class="theme-toggle-track" aria-hidden="true"></span></button><form action="../logout.php" method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="signout" type="submit">Sign out</button></form></div></header>
    <main class="content">
        <?php $moduleHeadings = ['overview'=>['Admin Dashboard','Monitor the single-zone AirSense setup.'],'alerts'=>['Air quality alerts','Review and manage hazardous alerts from the MQ-2 sensor.'],'devices'=>['ESP32 device','Manage the registered ESP32 monitor.'],'sensors'=>['Sensor setup','Register the single MQ-2 sensor to the ESP32 device.'],'users'=>['Account management','Manage linked account roles and access status.'],'settings'=>['Threshold settings','View the shared air-quality classification limits.'],'readings'=>['Sensor readings','Review, export, or clean up stored readings.'],'audit'=>['Alert history','Review records stored in the existing alerts table.']]; ?>
        <section class="page-heading"><div><span class="eyebrow">System administration</span><h1><?= e($moduleHeadings[$staffModule][0]) ?></h1><p><?= $staffModule === 'overview' ? 'Latest readings, alerts today, and registered accounts.' : ($staffModule === 'settings' ? 'Adjust the shared Good, Moderate, Hazardous, and Very Hazardous status limits.' : e($moduleHeadings[$staffModule][1])) ?></p></div></section>
        <?php if ($message): ?><p class="notice" role="status"><?= e((string)$message) ?></p><?php endif; ?>
        <?php if ($actionError): ?><p class="error" role="alert"><?= e($actionError) ?></p><?php endif; ?>
        <?php if ($staffModule === 'overview'): ?>
        <?php
            $currentReading = $overviewReadings[0] ?? null;
            $currentStatus = $currentReading ? staff_sensor_status($currentReading['mq135_value'] ?? null, $settings) : null;
            $currentStatusClass = $currentStatus !== null ? strtolower(str_replace(' ', '-', $currentStatus)) : '';
        ?>
        <p class="footnote" id="overview-refresh-status" role="status" aria-live="polite">Live updates every 3 seconds.</p>
        <section class="overview-stats" id="overview-stats" aria-label="Admin dashboard summary">
            <article class="overview-stat"><span>Alerts today</span><strong><?= $todayAlertsCount === null ? '&mdash;' : e((string)$todayAlertsCount) ?></strong><small><?= e((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M j, Y')) ?> · Philippine time</small></article>
            <article class="overview-stat"><span>Current MQ-2 reading</span><strong><?= $currentReading ? staff_value($currentReading, 'mq135_value') : '&mdash;' ?></strong><small><?php if ($currentReading): ?><span class="status-pill <?= e($currentStatusClass) ?>"><?= e($currentStatus) ?></span> · <?= staff_time($currentReading['recorded_at'] ?? null) ?><?php else: ?><?= $overviewErrors ? 'Reading unavailable' : 'No readings received yet' ?><?php endif; ?></small></article>
            <article class="overview-stat"><span>Total accounts</span><strong><?= $accountsCount === null ? '&mdash;' : e((string)$accountsCount) ?></strong><small>Registered user and staff accounts</small></article>
        </section>
        <?php foreach ($overviewErrors as $overviewError): ?><p class="error" role="alert"><?= e($overviewError) ?></p><?php endforeach; ?>
        <section class="section" id="latest-readings" aria-labelledby="latest-readings-title">
            <div class="section-head"><div><h2 id="latest-readings-title">Latest readings</h2><p>Newest sensor readings first · up to 10 records</p></div><a class="button secondary" href="readings.php">View all readings</a></div>
            <div class="table-scroll"><table class="overview-reading-table">
                <thead><tr><th scope="col">Reading ID</th><th scope="col">MQ-2 value</th><th scope="col">Status</th><th scope="col">Recorded</th></tr></thead>
                <tbody>
                <?php foreach ($overviewReadings as $reading): ?>
                <tr>
                    <td>#<?= staff_value($reading, 'reading_id') ?></td>
                    <td><?= staff_value($reading, 'mq135_value') ?></td>
                    <td><?= staff_value($reading, 'air_quality_status') ?></td>
                    <td><?= staff_time($reading['recorded_at'] ?? null) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$overviewReadings): ?><tr><td colspan="6" class="empty-cell"><?= $overviewErrors ? 'Latest readings could not be loaded. See the error above.' : 'No sensor readings are available yet.' ?></td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
        <?php endif; ?>

        <?php if ($staffModule === 'alerts'): ?>
        <section class="section" id="alerts" aria-labelledby="alerts-title">
            <div class="section-head"><div><h2 id="alerts-title">Air quality alerts</h2><p>Moderate and hazardous alerts are created from actual sensor readings when they exceed the previous alert peak; up to 500 latest alerts per state are shown.</p><p class="footnote" id="alerts-refresh-status" role="status" aria-live="polite">Auto-refreshing every 15 seconds.</p></div></div>
            <?php if (!empty($alertError)): ?><div class="section-body"><p class="error"><?= e($alertError) ?></p></div><?php endif; ?>
            <?php foreach ([['Active Alerts',$alerts],['Resolved Alerts',$resolvedAlerts]] as [$groupTitle,$groupAlerts]): ?>
            <div class="section-body"><h3><?= e($groupTitle) ?> <span class="status-pill"><?= count($groupAlerts) ?></span></h3>
                <?php if ($groupAlerts): ?><div class="alert-grid" style="padding-left:0;padding-right:0">
                <?php foreach ($groupAlerts as $alert):
                    $zoneId=(string)($alert['zone_id']??''); $deviceId=(string)($alert['device_id']??'');
                    $state=trim((string)($alert['status']??''));
                    if ($state === '') $state='Active';
                    $normalizedState=strtolower($state);
                    $nextState=match($normalizedState){'active'=>'Acknowledged','acknowledged'=>'Resolved',default=>'Active'};
                    $buttonText=match($normalizedState){'active'=>'Acknowledge','acknowledged'=>'Resolve',default=>'Reopen'};
                    $readingId=(string)($alert['reading_id']??'');
                    $mq135=$readingValues[$readingId]??null;
                ?>
                <article class="alert-card"><h3><?= staff_value($alert,'alert_type') ?: 'Air Quality Alert' ?> <span class="status-pill <?= e($normalizedState) ?>"><?= e($state) ?></span></h3>
                    <p><strong>Severity:</strong> <?= staff_value($alert,'severity') ?><br><strong>MQ-2:</strong> <?= $mq135 === null ? 'Not linked' : e((string)$mq135) ?><br><strong>Zone:</strong> <?= e((string)($zones[$zoneId]??('Zone '.$zoneId))) ?><br><strong>Device:</strong> <?= e((string)($devices[$deviceId]??('Device '.$deviceId))) ?><br><strong>Date/time:</strong> <?= staff_time($alert['created_at']??null) ?><br><?= staff_value($alert,'message') ?></p>
                    <?php if (in_array($normalizedState, ['active','acknowledged','resolved','closed'], true)): ?><form method="post" class="inline-form" style="margin-top:11px"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="set_alert_state"><input type="hidden" name="alert_id" value="<?= staff_value($alert,'alert_id') ?>"><input type="hidden" name="state" value="<?= e($nextState) ?>"><button class="button secondary" type="submit"><?= e($buttonText) ?></button></form><?php endif; ?>
                </article>
                <?php endforeach; ?></div>
                <?php else: ?><p class="footnote">No <?= e(strtolower($groupTitle)) ?>.</p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php foreach (['zones'=>'Zones','devices'=>'Devices','sensors'=>'Sensors'] as $table=>$title):
            $config=$managedTables[$table]??null; $idColumn=$config['id']??''; $fields=$config['fields']??[];
        ?>
        <?php if ($staffModule === $table): ?>
        <section class="section" id="<?= e($table) ?>" aria-labelledby="<?= e($table) ?>-title">
            <div class="section-head"><div><h2 id="<?= e($table) ?>-title"><?= e($title) ?> management</h2><p><?= $table === 'devices' ? 'Manage the ESP32 monitor assigned automatically to the single registered zone.' : ($table === 'sensors' ? 'Manage the MQ-2 sensor assigned automatically to the single ESP32 device.' : 'Add, edit, or remove records using the existing ' . e($table) . ' table.') ?></p></div></div>
            <?php if (isset($tableErrors[$table])): ?><div class="section-body"><p class="error"><?= e($tableErrors[$table]) ?></p></div>
            <?php elseif ($config && $fields): ?>
            <?php if ($table === 'devices' && $deviceZoneError !== null): ?><div class="section-body"><p class="error"><?= e($deviceZoneError) ?></p></div><?php endif; ?>
            <?php if ($table === 'sensors' && $sensorDeviceError !== null): ?><div class="section-body"><p class="error"><?= e($sensorDeviceError) ?></p></div><?php endif; ?>
            <?php if (($table !== 'devices' || ($singleZoneId !== null && $deviceZoneError === null)) && ($table !== 'sensors' || ($singleDeviceId !== null && $sensorDeviceError === null))): ?>
            <div class="section-body"><form method="post" class="management-grid">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="create_record"><input type="hidden" name="table" value="<?= e($table) ?>">
                <?php foreach ($fields as $column=>$definition): $type=staff_sensor_field_type($definition); ?>
                <div class="field">
                    <?php if ($type==='checkbox'): ?><label class="check-field"><input type="checkbox" name="fields[<?= e($column) ?>]" value="1"><?= e(ucwords(str_replace('_',' ',$column))) ?></label>
                    <?php else: ?><label for="new-<?= e($table) ?>-<?= e($column) ?>"><?= e($table==='devices' && $column==='zone_id' ? 'Zone name' : ucwords(str_replace('_',' ',$column))) ?></label>
                    <?php if ($table==='devices' && $column==='zone_id'): ?><input id="new-<?= e($table) ?>-<?= e($column) ?>" name="fields[<?= e($column) ?>]" type="text" list="existing-device-zones" required autocomplete="off" placeholder="Enter an existing zone name">
                    <?php elseif ($type==='select'): ?><select id="new-<?= e($table) ?>-<?= e($column) ?>" name="fields[<?= e($column) ?>]"><option value="">Choose…</option><?php foreach ($definition['enum'] as $option): ?><option value="<?= e((string)$option) ?>"><?= e((string)$option) ?></option><?php endforeach; ?></select>
                    <?php else: ?><input id="new-<?= e($table) ?>-<?= e($column) ?>" name="fields[<?= e($column) ?>]" type="<?= e($type) ?>" <?= $type==='number'&&($definition['type']??'')!=='integer'?'step="any"':'' ?> <?= in_array($column,$config['required']??[],true)?'required':'' ?>><?php endif; ?><?php endif; ?>
                </div><?php endforeach; ?>
                <div><button class="button" type="submit">Add <?= e(strtolower($title)) ?></button></div>
            </form></div>
            <?php elseif ($table === 'devices' && $deviceZoneError === null): ?><div class="section-body"><p class="error"><?= $zones ? 'More than one zone exists. Keep only the zone used by this single-zone monitor before adding a device.' : 'No zone is available. Add the single zone used by this monitor before adding a device.' ?></p></div>
            <?php elseif ($table === 'sensors' && $sensorDeviceError === null): ?><div class="section-body"><p class="error"><?= $devices ? 'More than one device exists. Keep only the ESP32 used by this monitor before adding a sensor.' : 'No device is registered. Add the ESP32 monitor in Device Management before registering its MQ-2 sensor.' ?></p></div><?php endif; ?>
            <div class="table-scroll"><table class="wide-table"><thead><tr><th><?= e(ucwords(str_replace('_',' ',$idColumn))) ?></th><?php foreach($fields as $column=>$definition): ?><th><?= e(ucwords(str_replace('_',' ',$column))) ?></th><?php endforeach; ?><th>Actions</th></tr></thead><tbody>
                <?php foreach($config['rows'] as $row): $id=(string)($row[$idColumn]??''); $formId=$table.'-'.$id; ?>
                <tr><td>#<?= e($id) ?><form method="post" id="<?= e($formId) ?>"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="update_record"><input type="hidden" name="table" value="<?= e($table) ?>"><input type="hidden" name="record_id" value="<?= e($id) ?>"></form></td>
                    <?php foreach($fields as $column=>$definition): $type=staff_sensor_field_type($definition); ?><td>
                    <?php if($type==='checkbox'): ?><label class="check-field"><input form="<?= e($formId) ?>" type="checkbox" name="fields[<?= e($column) ?>]" value="1" <?= !empty($row[$column])?'checked':'' ?>><?= e(ucwords(str_replace('_',' ',$column))) ?></label>
                    <?php elseif($type==='select'): ?><select form="<?= e($formId) ?>" aria-label="<?= e($column) ?>" name="fields[<?= e($column) ?>]"><?php foreach($definition['enum'] as $option): ?><option value="<?= e((string)$option) ?>" <?= (string)($row[$column]??'')===(string)$option?'selected':'' ?>><?= e((string)$option) ?></option><?php endforeach; ?></select>
                    <?php else: ?><input form="<?= e($formId) ?>" aria-label="<?= e(ucwords(str_replace('_',' ',$column))) ?>" name="fields[<?= e($column) ?>]" type="<?= e($type) ?>" value="<?= staff_value($row,$column) ?>" <?= $type==='number'&&($definition['type']??'')!=='integer'?'step="any"':'' ?>><?php endif; ?>
                    </td><?php endforeach; ?><td><div class="edit-actions"><button class="button secondary" type="submit" form="<?= e($formId) ?>">Save</button>
                    <form method="post" class="inline-form" onsubmit="return confirm('This delete cannot be undone. Type DELETE in the confirmation field to continue.');"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_record"><input type="hidden" name="table" value="<?= e($table) ?>"><input type="hidden" name="record_id" value="<?= e($id) ?>"><input aria-label="Type DELETE to confirm" name="confirm_word" placeholder="Type DELETE" required><button class="button danger" type="submit">Delete</button></form></div></td></tr>
                <?php endforeach; if(!$config['rows']): ?><tr><td class="empty-cell" colspan="<?= count($fields)+2 ?>">No <?= e(strtolower($title)) ?> records found.</td></tr><?php endif; ?>
            </tbody></table></div>
            <?php else: ?><div class="section-body"><p class="footnote">No editable fields were discovered for this table. Confirm it is exposed in the Supabase REST schema.</p></div><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($staffModule === 'users'): ?>
        <section class="section" id="users" aria-labelledby="users-title"><div class="section-head"><div><h2 id="users-title">Account and role management</h2><p>Promote or demote linked accounts, disable access, and request Auth password recovery.</p></div></div>
            <?php if (!empty($userError)): ?><div class="section-body"><p class="error"><?= e($userError) ?></p></div><?php else: ?>
            <div class="table-scroll"><table class="wide-table"><thead><tr><th>User ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
                <?php foreach($users as $user): $userForm='user-'.(string)($user['user_id']??''); ?><tr><td>#<?= staff_value($user,'user_id') ?><form id="<?= e($userForm) ?>" method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= staff_value($user,'user_id') ?>"></form></td><td><?= staff_value($user,'name') ?></td><td><?= staff_value($user,'email') ?></td><td><select form="<?= e($userForm) ?>" name="role" aria-label="Role for <?= staff_value($user,'email') ?>"><option value="User" <?= strtolower((string)($user['role']??''))==='user'?'selected':'' ?>>User</option><option value="Staff" <?= strtolower((string)($user['role']??''))==='staff'?'selected':'' ?>>Staff</option></select></td><td><select form="<?= e($userForm) ?>" name="account_status" aria-label="Status for <?= staff_value($user,'email') ?>"><option value="Active" <?= ($user['account_status']??'')==='Active'?'selected':'' ?>>Active</option><option value="Disabled" <?= ($user['account_status']??'')==='Disabled'?'selected':'' ?>>Disabled</option></select></td><td><?= staff_time($user['created_at']??null) ?></td><td><button class="button secondary" type="submit" form="<?= e($userForm) ?>">Save</button><form method="post" style="margin-top:7px"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="send_password_reset"><input type="hidden" name="email" value="<?= staff_value($user,'email') ?>"><button class="button secondary" type="submit">Send reset email</button></form></td></tr><?php endforeach; ?>
                <?php if(!$users): ?><tr><td colspan="7" class="empty-cell">No linked user accounts are available.</td></tr><?php endif; ?>
            </tbody></table></div><?php endif; ?>
            <div class="section-body"><p class="footnote">New Auth accounts remain User by default; Staff only changes access for existing linked accounts. Password reset sends an email through Supabase Auth.</p></div>
        </section>
        <?php endif; ?>

        <?php if ($staffModule === 'settings'): ?>
        <section class="section" id="settings" aria-labelledby="settings-title"><div class="section-head"><div><h2 id="settings-title">Air-quality classification</h2><p>Adjust the shared reading limits used by the website, Supabase alert rules, and connected ESP32 monitors.</p></div></div>
            <?php if ($settingsError): ?><div class="section-body"><p class="error" role="alert"><?= e($settingsError) ?></p></div><?php endif; ?>
            <div class="section-body">
                <p><span class="status-pill good">GOOD</span> 0–<?= staff_value($settings,'good_max') ?></p>
                <p><span class="status-pill moderate">MODERATE</span> <?= e((string)((int)$settings['good_max'] + 1)) ?>–<?= staff_value($settings,'moderate_max') ?></p>
                <p><span class="status-pill hazardous">HAZARDOUS</span> <?= e((string)((int)$settings['moderate_max'] + 1)) ?>–<?= staff_value($settings,'hazardous_max') ?></p>
                <p><span class="status-pill very-hazardous">VERY HAZARDOUS</span> <?= e((string)((int)$settings['hazardous_max'] + 1)) ?>–600</p>
                <form method="post" class="threshold-form">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="save_thresholds">
                    <div class="field"><label for="good_max">Good maximum</label><input id="good_max" name="good_max" type="number" min="0" max="598" step="1" value="<?= staff_value($settings,'good_max') ?>" required></div>
                    <div class="field"><label for="moderate_max">Moderate maximum</label><input id="moderate_max" name="moderate_max" type="number" min="1" max="599" step="1" value="<?= staff_value($settings,'moderate_max') ?>" required></div>
                    <div class="field"><label for="hazardous_max">Hazardous maximum</label><input id="hazardous_max" name="hazardous_max" type="number" min="2" max="600" step="1" value="<?= staff_value($settings,'hazardous_max') ?>" required></div>
                    <button class="button" type="submit" <?= $settingsError ? 'disabled' : '' ?>>Adjust thresholds</button>
                </form>
                <p class="footnote">Each status includes its maximum value. Very Hazardous starts one above the Hazardous maximum. Set the three limits in increasing order; valid sensor values range from 0 to 600.</p>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($staffModule === 'readings'): ?>
        <section class="section" id="readings" aria-labelledby="readings-title"><div class="section-head"><div><h2 id="readings-title">Recent sensor readings</h2><p>Search readings by date, delete an individual record, or export actual readings as CSV.</p></div><a class="button secondary" href="readings.php?export=readings">Export all readings (CSV)</a></div>
            <?php if ($dataError): ?><div class="section-body"><p class="error" role="alert"><?= e($dataError) ?></p></div><?php endif; ?>
            <div class="section-body"><form method="get" action="readings.php" class="inline-form"><div class="field"><label for="reading-date">Search readings by date</label><input id="reading-date" name="date" type="date" value="<?= e($readingsDate ?? '') ?>"></div><button class="button" type="submit">Search</button><?php if (!empty($readingsDate)): ?><a class="button secondary" href="readings.php#readings">Clear date</a><?php endif; ?></form></div>
            <div class="section-body" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <p class="footnote">Showing <?= $readingsTotal === 0 ? '0' : e((string)($readingsOffset + 1)) . '–' . e((string)($readingsOffset + count($readings))) ?> of <?= e((string)$readingsTotal) ?> readings (10 per page).</p>
                <nav aria-label="Readings pagination" style="display:flex;align-items:center;gap:10px">
                    <?php if ($readingsPage > 1): ?><a class="button secondary" href="readings.php?<?= !empty($readingsDate) ? 'date=' . rawurlencode($readingsDate) . '&amp;' : '' ?>page=<?= e((string)($readingsPage - 1)) ?>#readings">Previous</a><?php else: ?><span class="button secondary" aria-disabled="true">Previous</span><?php endif; ?>
                    <span class="footnote">Page <?= e((string)$readingsPage) ?> of <?= e((string)$readingsPages) ?></span>
                    <?php if ($readingsPage < $readingsPages): ?><a class="button secondary" href="readings.php?<?= !empty($readingsDate) ? 'date=' . rawurlencode($readingsDate) . '&amp;' : '' ?>page=<?= e((string)($readingsPage + 1)) ?>#readings">Next</a><?php else: ?><span class="button secondary" aria-disabled="true">Next</span><?php endif; ?>
                </nav>
            </div>
            <div class="table-scroll"><table class="reading-table"><thead><tr><th>Reading ID</th><th>Device</th><th>Sensor</th><th>MQ-2 value</th><th>Recorded</th><th>Action</th></tr></thead><tbody>
                <?php foreach($readings as $reading): $deviceId=(string)($reading['device_id']??''); $readingId=(string)($reading['reading_id']??''); ?>
                <tr><td>#<?= staff_value($reading,'reading_id') ?></td><td><?= e((string)($devices[$deviceId]??('Device '.$deviceId))) ?><small>ID: <?= e($deviceId) ?></small></td><td><?= e((string)($sensors[(string)($reading['sensor_id']??'')]??(!empty($reading['sensor_id'])?'Sensor '.$reading['sensor_id']:'Legacy reading'))) ?><small>ID: <?= staff_value($reading,'sensor_id') ?></small></td><td><?= staff_value($reading,'mq135_value') ?><small><?= e(staff_sensor_status($reading['mq135_value']??null,$settings)) ?></small></td><td><?= staff_time($reading['recorded_at']??null) ?></td><td><form method="post" onsubmit="return confirm('Permanently delete reading #<?= e($readingId) ?>? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_reading"><input type="hidden" name="reading_id" value="<?= e($readingId) ?>"><button class="button danger" type="submit">Delete</button></form></td></tr>
                <?php endforeach; if(!$readings): ?><tr><td class="empty-cell" colspan="6">No readings are available.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>


        <?php if ($staffModule === 'audit'): ?>
        <section class="section" id="audit" aria-labelledby="audit-title"><div class="section-head"><div><h2 id="audit-title">Alert history</h2><p>Latest 100 alert records from the existing alerts table. General staff activity auditing needs a separate table.</p></div></div>
            <?php if(!empty($auditError)): ?><div class="section-body"><p class="error"><?= e($auditError) ?></p></div><?php else: ?>
            <div class="table-scroll"><table class="wide-table"><thead><tr><th>When</th><th>Type</th><th>Severity</th><th>Status</th><th>Zone</th><th>Device</th><th>Message</th></tr></thead><tbody>
                <?php foreach($auditRows as $entry): ?><tr><td><?= staff_time($entry['created_at']??null) ?></td><td><?= staff_value($entry,'alert_type') ?></td><td><?= staff_value($entry,'severity') ?></td><td><?= staff_value($entry,'status') ?></td><td><?= e((string)($zones[(string)($entry['zone_id']??'')]??('Zone '.($entry['zone_id']??'')))) ?></td><td><?= e((string)($devices[(string)($entry['device_id']??'')]??('Device '.($entry['device_id']??'')))) ?></td><td><?= staff_value($entry,'message') ?></td></tr><?php endforeach; ?>
                <?php if(!$auditRows): ?><tr><td colspan="7" class="empty-cell">No alert records are available.</td></tr><?php endif; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (in_array($staffModule, ['zones','devices','sensors','users','readings','audit','alerts'], true)): ?><p class="footnote">Staff changes are enforced by Supabase row-level security.</p><?php endif; ?>
    </main>
</div>
</div>
<script>
(() => {
    const toggle = document.getElementById('theme-toggle');
    if (!toggle) return;

    function syncToggle() {
        toggle.setAttribute('aria-checked', String(document.documentElement.dataset.theme === 'dark'));
    }

    toggle.addEventListener('click', () => {
        const useDarkTheme = document.documentElement.dataset.theme !== 'dark';
        if (useDarkTheme) {
            document.documentElement.dataset.theme = 'dark';
        } else {
            delete document.documentElement.dataset.theme;
        }
        syncToggle();
        try {
            localStorage.setItem('airsense-staff-theme', useDarkTheme ? 'dark' : 'light');
        } catch (error) {
            console.error('Could not save the theme preference:', error);
        }
    });

    syncToggle();
})();
</script>
<?php if ($staffModule === 'overview'): ?>
<script>
(() => {
    const refreshInterval = 3000;
    let refreshing = false;

    async function refreshOverview() {
        if (document.hidden || refreshing) {
            window.setTimeout(refreshOverview, refreshInterval);
            return;
        }

        refreshing = true;
        const status = document.getElementById('overview-refresh-status');
        try {
            const response = await fetch(window.location.href, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const html = await response.text();
            const updatedDocument = new DOMParser().parseFromString(html, 'text/html');
            for (const sectionId of ['overview-stats', 'latest-readings']) {
                const updatedSection = updatedDocument.getElementById(sectionId);
                const currentSection = document.getElementById(sectionId);
                if (!updatedSection || !currentSection) throw new Error(`Missing ${sectionId} section.`);
                currentSection.replaceWith(updatedSection);
            }

            const updatedAt = new Date().toLocaleTimeString();
            if (status) status.textContent = `Updated at ${updatedAt}. Refreshing every 3 seconds.`;
        } catch (error) {
            if (status) status.textContent = 'Could not refresh readings. Retrying in 3 seconds.';
            console.error('Overview auto-refresh failed:', error);
        } finally {
            refreshing = false;
            window.setTimeout(refreshOverview, refreshInterval);
        }
    }

    window.setTimeout(refreshOverview, refreshInterval);
})();
</script>
<?php endif; ?>
<?php if ($staffModule === 'alerts'): ?>
<script>
(() => {
    const refreshInterval = 15000;
    let refreshing = false;

    async function refreshAlerts() {
        if (document.hidden || refreshing || document.querySelector('#alerts form :focus')) {
            return;
        }

        refreshing = true;
        const status = document.getElementById('alerts-refresh-status');

        try {
            const response = await fetch(window.location.href, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();
            const updatedDocument = new DOMParser().parseFromString(html, 'text/html');
            const updatedAlerts = updatedDocument.getElementById('alerts');
            const currentAlerts = document.getElementById('alerts');
            if (!updatedAlerts || !currentAlerts) {
                throw new Error('Alert section was not returned.');
            }

            currentAlerts.replaceWith(updatedAlerts);
            const updatedStatus = document.getElementById('alerts-refresh-status');
            updatedStatus.textContent = `Updated at ${new Date().toLocaleTimeString()}. Auto-refreshing every 15 seconds.`;
        } catch (error) {
            if (status) {
                status.textContent = 'Could not refresh alerts. Check your connection; retrying in 15 seconds.';
            }
            console.error('Alert auto-refresh failed:', error);
        } finally {
            refreshing = false;
            window.setTimeout(refreshAlerts, refreshInterval);
        }
    }

    window.setTimeout(refreshAlerts, refreshInterval);
})();
</script>
<?php endif; ?>
</body>
</html>
