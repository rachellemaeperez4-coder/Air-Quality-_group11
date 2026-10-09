<?php
require dirname(__DIR__) . '/dashboard-auth.php';
$account = require_account('user');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); exit('This readings page is read-only.');
}

function read_reading_rows(string $query, string $token): array {
    [$status, $rows] = supabase('/rest/v1/' . $query, null, $token);
    if ($status !== 200 || !is_array($rows) || !array_is_list($rows)) {
        throw reading_query_error($status, $rows);
    }
    return $rows;
}
function reading_query_error(int $status, array $response): RuntimeException {
    $hint = match ($status) {
        401, 403 => 'Check that supabase-readings-access.sql and supabase-admin-core.sql were run in the Supabase SQL Editor, then sign out and back in.',
        400, 404 => 'Check the readings table schema and run any required Supabase migrations.',
        default => 'Check the Supabase database connection and API status.',
    };
    $message = is_string($response['message'] ?? null) ? trim($response['message']) : '';
    $detail = $message !== '' ? ' Supabase: ' . $message : '';
    return new RuntimeException('Readings could not be loaded (HTTP ' . $status . '). ' . $hint . $detail);
}
function query_date(string $value, bool $endOfDay = false): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        return null;
    }
    if ($endOfDay) $date = $date->modify('+1 day');
    return $date->format('Y-m-d\TH:i:s\Z');
}
function readings_url(array $filters, int $page): string {
    $params = array_filter($filters, static fn($value) => $value !== '');
    if ($page > 1) $params['page'] = $page;
    return 'readings.php' . ($params ? '?' . http_build_query($params) : '');
}
function reading_value($value): string {
    return $value === null || $value === '' ? '&mdash;' : e((string)$value);
}
function reading_status($value, array $thresholds): string {
    if (!isset($thresholds['good_max'], $thresholds['moderate_max'], $thresholds['hazardous_max'])) return 'Unavailable';
    return aqm_quality_status($value, $thresholds);
}
function reading_status_class(string $status): string {
    return match ($status) {
        'Good' => 'status-good',
        'Moderate' => 'status-moderate',
        'Hazardous' => 'status-hazard',
        'Very Hazardous' => 'status-very-hazardous',
        default => 'status-neutral',
    };
}
function reading_time($value): string {
    if ($value === null || $value === '') return '&mdash;';
    try { return e((new DateTimeImmutable((string)$value))->format('M j, Y · g:i A')); }
    catch (Exception) { return e((string)$value); }
}

$filters = [
    'search' => trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : ''),
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    'date_from' => is_string($_GET['date_from'] ?? null) ? $_GET['date_from'] : '',
    'date_to' => is_string($_GET['date_to'] ?? null) ? $_GET['date_to'] : '',
];
$filters['search'] = substr($filters['search'], 0, 80);
$from = query_date($filters['date_from']);
$toExclusive = query_date($filters['date_to'], true);
$invalidDateRange = ($filters['date_from'] !== '' && $from === null)
    || ($filters['date_to'] !== '' && $toExclusive === null)
    || ($from !== null && $toExclusive !== null && $filters['date_from'] > $filters['date_to']);
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = is_int($requestedPage) && $requestedPage > 0 ? min($requestedPage, 100000) : 1;
$pageSize = 10;
$readings = []; $dataError = null; $thresholdError = null; $hasNext = false; $thresholds = [];
try {
    $thresholds = aqm_thresholds($account['token']);
} catch (RuntimeException $error) {
    $thresholdError = $error->getMessage();
}

try {
    $query = [
        'air_quality_readings?select=reading_id,sensor_value:mq135_value,air_quality_status,recorded_at',
    ];
    $conditions = [];
    if (!$thresholds && $filters['status'] !== '') {
        $conditions[] = 'reading_id=eq.-1';
    } elseif ($filters['status'] === 'good') {
        $conditions[] = 'mq135_value=gte.0';
        $conditions[] = 'mq135_value=lte.' . rawurlencode((string)$thresholds['good_max']);
    } elseif ($filters['status'] === 'moderate') {
        $conditions[] = 'mq135_value=gt.' . rawurlencode((string)$thresholds['good_max']);
        $conditions[] = 'mq135_value=lte.' . rawurlencode((string)$thresholds['moderate_max']);
    } elseif ($filters['status'] === 'hazardous') {
        $conditions[] = 'mq135_value=gt.' . rawurlencode((string)$thresholds['moderate_max']);
        $conditions[] = 'mq135_value=lte.' . rawurlencode((string)$thresholds['hazardous_max']);
    } elseif ($filters['status'] === 'very_hazardous') {
        $conditions[] = 'mq135_value=gt.' . rawurlencode((string)$thresholds['hazardous_max']);
    } elseif ($filters['status'] !== '') {
        $conditions[] = 'mq135_value=is.null';
    }

    if ($invalidDateRange) {
        $conditions[] = 'reading_id=eq.-1';
    } else {
        if ($from !== null) $conditions[] = 'recorded_at=gte.' . rawurlencode($from);
        if ($toExclusive !== null) $conditions[] = 'recorded_at=lt.' . rawurlencode($toExclusive);
    }

    if ($filters['search'] !== '') {
        $term = preg_replace('/[^a-zA-Z0-9 _-]/', '', $filters['search']) ?? '';
        $term = trim($term);
        $searchParts = [];
        if ($term !== '') {
            if (ctype_digit($term)) {
                $numericId = (string)(int)$term;
                $searchParts[] = 'reading_id.eq.' . $numericId;
            }
            if ($thresholds && stripos('Good', $term) !== false) {
                $searchParts[] = 'and(mq135_value.gte.0,mq135_value.lte.' . $thresholds['good_max'] . ')';
            }
            if ($thresholds && stripos('Moderate', $term) !== false) {
                $searchParts[] = 'and(mq135_value.gt.' . $thresholds['good_max'] . ',mq135_value.lte.' . $thresholds['moderate_max'] . ')';
            }
            if ($thresholds && stripos('Very Hazardous', $term) !== false) {
                $searchParts[] = 'mq135_value.gt.' . $thresholds['hazardous_max'];
            } elseif ($thresholds && stripos('Hazardous', $term) !== false) {
                $searchParts[] = 'and(mq135_value.gt.' . $thresholds['moderate_max'] . ',mq135_value.lte.' . $thresholds['hazardous_max'] . ')';
            }
        }
        if ($searchParts) $conditions[] = 'or=' . rawurlencode('(' . implode(',', $searchParts) . ')');
        else $conditions[] = 'reading_id=eq.-1';
    }

    $query = array_merge($query, $conditions, [
        'order=recorded_at.desc.nullslast,reading_id.desc',
        'limit=' . ($pageSize + 1),
        'offset=' . (($page - 1) * $pageSize),
    ]);
    $rows = read_reading_rows(implode('&', $query), $account['token']);
    $hasNext = count($rows) > $pageSize;
    $readings = array_slice($rows, 0, $pageSize);
} catch (RuntimeException $error) {
    $dataError = $error->getMessage();
    $readings = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#102a43">
<title>Readings | AirSense IoT monitoring</title>
<link rel="stylesheet" href="../style.css">
</head>
<body>
<div class="app-shell">
<aside class="sidebar" aria-label="Dashboard sidebar">
    <a class="brand" href="dashboard.php#overview" aria-label="Air quality monitoring home">
        <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 14.5a4 4 0 0 1 3.5-3.97A5.5 5.5 0 0 1 18 9.5a3.5 3.5 0 1 1 .5 7H7.5A3.5 3.5 0 0 1 4 14.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 19h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
        <span class="brand-name">AirSense<small>IoT monitoring</small></span>
    </a>
    <div class="side-label">Workspace</div>
    <nav class="sidebar-nav" aria-label="Dashboard navigation">
        <a class="nav-link" href="dashboard.php#overview"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>Overview</a>
        <a class="nav-link" href="readings.php" aria-current="page"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4.5h14v15H5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Readings</a>
        <a class="nav-link" href="alerts.php"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Alerts</a>
        <a class="nav-link" href="dashboard.php#status-guide"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>Status guide</a>
    </nav>
    <div class="sidebar-spacer"></div>
    <div class="access-card"><div class="access-label"><span class="access-dot" aria-hidden="true"></span>Readings access</div><p>Viewing the latest readings shared with your account.</p></div>
    <div class="profile"><span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($account['email'], 0, 1))) ?></span><div class="profile-copy"><strong><?= e($account['email']) ?></strong><span>Read-only account</span></div></div>
</aside>
<div class="workspace">
    <header class="topbar">
        <div class="breadcrumb">Workspace <span aria-hidden="true">/</span> <strong>Readings</strong></div>
        <div class="top-actions"><span class="view-label"><span class="top-dot" aria-hidden="true"></span>Read-only access</span><form action="../logout.php" method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="signout" type="submit">Sign out</button></form></div>
    </header>
    <main class="content">
        <section class="page-heading" aria-labelledby="page-title">
            <div><span class="eyebrow">Kitchen Zone · MQ-2 sensor</span><h1 id="page-title">Readings</h1><p>Recent sensor values and status.</p></div>
        </section>
        <form class="filters" method="get" action="readings.php">
            <div class="filter-grid">
                <div class="field"><label for="search">Reading ID or status</label><input id="search" name="search" type="search" maxlength="80" value="<?= e($filters['search']) ?>" placeholder="Reading ID or status"></div>
                <div class="field"><label for="status">Status</label><select id="status" name="status" <?= $thresholds ? '' : 'disabled' ?>><option value="">All statuses</option><?php if ($thresholds): ?><option value="good" <?= $filters['status'] === 'good' ? 'selected' : '' ?>>Good (0–<?= e((string)$thresholds['good_max']) ?>)</option><option value="moderate" <?= $filters['status'] === 'moderate' ? 'selected' : '' ?>>Moderate (&gt;<?= e((string)$thresholds['good_max']) ?>–<?= e((string)$thresholds['moderate_max']) ?>)</option><option value="hazardous" <?= $filters['status'] === 'hazardous' ? 'selected' : '' ?>>Hazardous (&gt;<?= e((string)$thresholds['moderate_max']) ?>–<?= e((string)$thresholds['hazardous_max']) ?>)</option><option value="very_hazardous" <?= $filters['status'] === 'very_hazardous' ? 'selected' : '' ?>>Very Hazardous (&gt;<?= e((string)$thresholds['hazardous_max']) ?>)</option><?php endif; ?></select></div>
                <div class="field"><label for="date_from">From date</label><input id="date_from" name="date_from" type="date" value="<?= e($filters['date_from']) ?>"></div>
                <div class="field"><label for="date_to">To date</label><input id="date_to" name="date_to" type="date" value="<?= e($filters['date_to']) ?>"></div>
            </div>
            <div class="filter-actions"><button class="apply-filter" type="submit">Apply filters</button><a class="clear-filter" href="readings.php">Clear all</a></div>
        </form>
        <?php if ($invalidDateRange): ?><p class="error" role="alert">Check the date filters: enter valid dates and make sure the From date is not later than the To date.</p><?php endif; ?>
        <?php if ($dataError): ?><p class="error" role="alert"><?= e($dataError) ?></p><?php endif; ?>
        <?php if ($thresholdError): ?><p class="error" role="alert">Reading status and status filters are unavailable; sensor values will still be shown. <?= e($thresholdError) ?></p><?php endif; ?>
        <section class="table-panel" aria-labelledby="readings-title">
            <div class="panel-heading"><div><h2 id="readings-title">MQ-2 readings</h2><p>Kitchen Zone · newest first</p></div><span class="panel-meta">Page <?= $page ?> · <?= count($readings) ?> shown</span></div>
            <div class="table-scroll"><table>
                <thead><tr><th scope="col">Reading ID</th><th scope="col">Sensor value</th><th scope="col">Status</th><th scope="col">Recorded date/time</th></tr></thead>
                <tbody>
                <?php foreach ($readings as $reading):
                    $storedStatus = trim((string)($reading['air_quality_status'] ?? ''));
                    $status = $storedStatus !== '' ? $storedStatus : reading_status($reading['sensor_value'] ?? null, $thresholds);
                ?>
                <tr>
                    <td>#<?= reading_value($reading['reading_id'] ?? null) ?></td>
                    <td><?= reading_value($reading['sensor_value'] ?? null) ?></td>
                    <td><span class="status-badge <?= e(reading_status_class($status)) ?>"><?= e($status) ?></span></td>
                    <td><?= reading_time($reading['recorded_at'] ?? null) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$readings): ?><tr><td class="empty-cell" colspan="4"><?= $dataError ? 'Readings are temporarily unavailable. Please refresh in a moment.' : 'No readings match these filters.' ?></td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <nav class="pagination" aria-label="Readings pagination">
                <span class="page-info"><?= $hasNext ? 'More readings available.' : 'End of matching readings.' ?> Page <?= $page ?>.</span>
                <div class="page-links">
                    <?php if ($page > 1): ?><a class="page-link" href="<?= e(readings_url($filters, $page - 1)) ?>">Previous</a><?php else: ?><span class="page-link" aria-disabled="true">Previous</span><?php endif; ?>
                    <?php if ($hasNext): ?><a class="page-link" href="<?= e(readings_url($filters, $page + 1)) ?>">Next</a><?php else: ?><span class="page-link" aria-disabled="true">Next</span><?php endif; ?>
                </div>
            </nav>
        </section>
        <p class="footnote">Showing 10 records per page. Sensor values are raw readings, not ppm. Status limits: Good through <?= e((string)($thresholds['good_max']??'—')) ?> · Moderate through <?= e((string)($thresholds['moderate_max']??'—')) ?> · Hazardous through <?= e((string)($thresholds['hazardous_max']??'—')) ?> · Very Hazardous above that.</p>
    </main>
</div>
</div>
<dialog class="reading-alert-dialog" id="reading-alert-dialog" aria-labelledby="reading-alert-title">
    <div class="reading-alert-content">
        <span class="reading-alert-kicker">Kitchen Zone · MQ-2</span>
        <h2 id="reading-alert-title">Elevated sensor reading</h2>
        <p><strong id="reading-alert-status"></strong> reading: <strong id="reading-alert-value"></strong></p>
        <p id="reading-alert-time" class="reading-alert-time"></p>
        <p class="reading-alert-note">The reading crossed a configured threshold. This raw sensor value is not a calibrated gas concentration. Check your surroundings and follow your household safety procedures.</p>
        <form method="dialog"><button class="reading-alert-dismiss">Acknowledge</button></form>
    </div>
</dialog>
<script src="reading-alerts.js" defer></script>
</body>
</html>
