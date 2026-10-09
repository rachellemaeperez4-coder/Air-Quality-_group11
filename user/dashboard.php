<?php
require dirname(__DIR__) . '/dashboard-auth.php';
$account = require_account('user');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); exit('This dashboard is read-only.');
}
$readings = []; $trendReadings = []; $dataError = null; $thresholds = [];
function read_rows_batch(array $queries, string $token): array {
    $responses = supabase_many(array_map(
        static fn(string $query): string => '/rest/v1/' . $query,
        $queries
    ), $token);
    $rowsByName = [];
    foreach ($responses as $name => [$status, $rows]) {
        if ($status !== 200 || !is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Readings could not be loaded. Ask your administrator to check database read permissions, then refresh.');
        }
        $rowsByName[$name] = $rows;
    }
    return $rowsByName;
}
function latest_reading_alert(string $token): array {
    $thresholds = aqm_thresholds($token);
    [$status, $rows] = supabase(
        '/rest/v1/air_quality_readings?select=reading_id,sensor_value:mq135_value,recorded_at&order=recorded_at.desc.nullslast,reading_id.desc&limit=1',
        null,
        $token
    );
    if ($status !== 200 || !is_array($rows) || !array_is_list($rows)) {
        throw new RuntimeException('Could not check the latest sensor reading (HTTP ' . $status . ').');
    }
    $reading = $rows[0] ?? null;
    return [
        'reading_id' => $reading['reading_id'] ?? null,
        'value' => $reading['sensor_value'] ?? null,
        'status' => $reading === null ? null : aqm_quality_status($reading['sensor_value'] ?? null, $thresholds),
        'recorded_at' => $reading['recorded_at'] ?? null,
    ];
}
if (($_GET['latest_reading'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode(latest_reading_alert($account['token']), JSON_THROW_ON_ERROR);
    } catch (RuntimeException $error) {
        http_response_code(502);
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
if (($_GET['dashboard_data'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $thresholds = aqm_thresholds($account['token']);
        $afterId = is_string($_GET['after_id'] ?? null) && ctype_digit($_GET['after_id'])
            ? $_GET['after_id']
            : '0';
        $newRowsQuery = 'air_quality_readings?select=reading_id,sensor_value:mq135_value,recorded_at&reading_id=gt.'
            . rawurlencode($afterId) . '&order=reading_id.asc&limit=100';
        $rows = read_rows_batch([
            'latest' => 'air_quality_readings?select=reading_id,sensor_value:mq135_value,recorded_at&order=recorded_at.desc.nullslast,reading_id.desc&limit=1',
            'new' => $newRowsQuery,
        ], $account['token']);
        $latestReading = $rows['latest'][0] ?? null;
        $formatRows = static fn(array $items): array => array_map(static function (array $reading) use ($thresholds): array {
            $status = aqm_quality_status($reading['sensor_value'] ?? null, $thresholds);
            return [
                'reading_id' => (string)($reading['reading_id'] ?? ''),
                'sensor_value' => $reading['sensor_value'] ?? null,
                'recorded_at' => $reading['recorded_at'] ?? null,
                'status' => $status,
                'status_class' => status_class($status),
            ];
        }, $items);
        $latest = $latestReading === null ? null : $formatRows([$latestReading])[0];
        echo json_encode([
            'latest' => $latest,
            'new_readings' => $formatRows($rows['new']),
            'trend' => $formatRows($rows['new']),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    } catch (RuntimeException $error) {
        http_response_code(502);
        echo json_encode(['error' => $error->getMessage()], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
    exit;
}
try {
    $thresholds = aqm_thresholds($account['token']);
    $trendSince = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-32 days')->format('Y-m-d\TH:i:s\Z');
    $initialRows = read_rows_batch([
        'readings' => 'air_quality_readings?select=reading_id,sensor_value:mq135_value,recorded_at&order=recorded_at.desc.nullslast,reading_id.desc&limit=100',
        'trend' => 'air_quality_readings?select=reading_id,sensor_value:mq135_value,recorded_at&mq135_value=not.is.null&recorded_at=gte.' . rawurlencode($trendSince) . '&order=recorded_at.desc&limit=1000',
    ], $account['token']);
    $readings = $initialRows['readings'];
    $trendReadings = $initialRows['trend'];
} catch (RuntimeException $error) { $dataError = $error->getMessage(); $readings = []; $trendReadings = []; }
function display_value($value): string { return $value === null || $value === '' ? '&mdash;' : e((string)$value); }
function status_class($status): string {
    $status = strtolower(trim((string)$status));
    return match (true) {
        str_contains($status, 'good') || str_contains($status, 'safe') => 'status-good',
        str_contains($status, 'moderate') || str_contains($status, 'fair') => 'status-moderate',
        str_contains($status, 'very hazardous') => 'status-very-hazardous',
        str_contains($status, 'hazard') || str_contains($status, 'unhealthy') || str_contains($status, 'poor') || str_contains($status, 'danger') || str_contains($status, 'bad') => 'status-alert',
        default => 'status-neutral',
    };
}
function status_badge($status): string {
    if ($status === null || $status === '') return '<span class="status-badge status-neutral">No data</span>';
    return '<span class="status-badge ' . status_class((string)$status) . '">' . e((string)$status) . '</span>';
}
function display_recorded_at($value): string {
    if ($value === null || $value === '') return '&mdash;';
    try { return e((new DateTimeImmutable((string)$value))->format('M j, Y · g:i A')); }
    catch (Exception) { return e((string)$value); }
}
$latest = $readings[0] ?? [];
$latestStatus = $dataError ? null : aqm_quality_status($latest['sensor_value'] ?? null, $thresholds);
$initialAlertStatus = in_array($latestStatus, ['Moderate', 'Hazardous', 'Very Hazardous'], true) ? $latestStatus : '';
// Numeric limits handed to the chart so it can draw the Good / Moderate guide lines.
$chartLimits = [
    'good_max' => isset($thresholds['good_max']) && is_numeric($thresholds['good_max']) ? (float)$thresholds['good_max'] : null,
    'moderate_max' => isset($thresholds['moderate_max']) && is_numeric($thresholds['moderate_max']) ? (float)$thresholds['moderate_max'] : null,
    'hazardous_max' => isset($thresholds['hazardous_max']) && is_numeric($thresholds['hazardous_max']) ? (float)$thresholds['hazardous_max'] : null,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#0a1f33">
<title>Kitchen air monitor | AirSense</title>
<link rel="stylesheet" href="../style.css">
</head>
<body>
<a class="skip-link" href="#overview">Skip to main content</a>
<div class="app-shell">
<aside class="sidebar" aria-label="Dashboard sidebar">
    <a class="brand" href="#overview" aria-label="Air quality monitoring home">
        <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 14.5a4 4 0 0 1 3.5-3.97A5.5 5.5 0 0 1 18 9.5a3.5 3.5 0 1 1 .5 7H7.5A3.5 3.5 0 0 1 4 14.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 19h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
        <span class="brand-name">AirSense<small>IoT monitoring</small></span>
    </a>
    <div class="side-label">Workspace</div>
    <nav class="sidebar-nav" aria-label="Dashboard navigation">
        <a class="nav-link" href="#overview" aria-current="page"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>Overview</a>
        <a class="nav-link" href="readings.php"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4.5h14v15H5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Readings</a>
        <a class="nav-link" href="alerts.php"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Alerts</a>
        <a class="nav-link" href="#status-guide"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>Status guide</a>
    </nav>
    <div class="sidebar-spacer"></div>
    <div class="access-card"><div class="access-label"><span class="access-dot" aria-hidden="true"></span>Readings access</div><p>Viewing the latest readings shared with your account.</p></div>
    <div class="profile"><span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($account['email'], 0, 1))) ?></span><div class="profile-copy"><strong><?= e($account['email']) ?></strong><span>Read-only account</span></div></div>
</aside>
<div class="workspace">
    <header class="topbar">
        <div class="breadcrumb">Workspace <span aria-hidden="true">/</span> <strong>Overview</strong></div>
        <div class="top-actions"><span class="view-label"><span class="top-dot" aria-hidden="true"></span>Read-only access</span><form action="../logout.php" method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="signout" type="submit">Sign out</button></form></div>
    </header>
    <main class="content">
        <section class="page-heading" id="overview" aria-labelledby="page-title">
            <div><span class="eyebrow">Kitchen Zone · MQ-2 sensor</span><h1 id="page-title">Kitchen air monitor</h1><p>Latest gas sensor reading and recent history.</p></div>
            <a class="refresh" href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 7v5h-5M4.8 9A7.5 7.5 0 0 1 18 6l2 2M4 17v-5h5m10.2 3A7.5 7.5 0 0 1 6 18l-2-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Refresh readings</a>
        </section>
        <p class="data-help"><strong>Read-only account:</strong> readings are sent automatically from the ESP32 and MQ-2 sensor in the Kitchen Zone. The displayed value is the sensor reading, not a calibrated gas concentration. If readings do not appear, ask Staff to check the device and token setup.</p>
        <section class="cards" aria-label="Air quality summary">
            <article class="card"><div class="card-top"><h2>Zone</h2><span class="card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="9" r="2.2" stroke="currentColor" stroke-width="1.7"/></svg></span></div><div class="value">Kitchen</div><p class="card-note">Single monitoring location</p></article>
            <article class="card"><div class="card-top"><h2>Latest MQ-2 reading</h2><span class="card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 13h4l2.2-7 4 12 2.2-7H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div><div class="value"><?= $dataError ? '&mdash;' : display_value($latest['sensor_value'] ?? null) ?></div><p class="card-note">Raw sensor value, not ppm</p></article>
            <article class="card"><div class="card-top"><h2>Reading status</h2><span class="card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 13h4l2.2-7 4 12 2.2-7H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div><div class="value"><?= $dataError ? '&mdash;' : status_badge($latestStatus) ?></div><p class="card-note">Based on configured sensor limits</p></article>
            <article class="card"><div class="card-top"><h2>Last recorded</h2><span class="card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div><div class="value time"><?= $dataError ? '&mdash;' : display_recorded_at($latest['recorded_at'] ?? null) ?></div><p class="card-note">Time of the newest available reading</p></article>
        </section>
        <section class="trend-panel" aria-labelledby="trend-title">
            <div class="trend-heading">
                <div><h2 id="trend-title">MQ-2 reading history</h2><p>Kitchen Zone sensor values over time · up to 1,000 recent readings</p></div>
                <div class="trend-filters" role="group" aria-label="Filter air quality trend">
                    <button class="trend-filter" type="button" data-range="today" aria-pressed="false">Today</button>
                    <button class="trend-filter" type="button" data-range="7days" aria-pressed="true">7 Days</button>
                    <button class="trend-filter" type="button" data-range="30days" aria-pressed="false">30 Days</button>
                </div>
            </div>
            <div class="trend-chart-wrap">
                <canvas class="trend-chart" id="air-quality-chart" tabindex="0" role="img" aria-label="MQ-2 sensor readings over the last 7 days. The horizontal axis is date and time; the vertical axis is raw sensor value. Use the left and right arrow keys to step through readings."></canvas>
                <div class="trend-tooltip" id="trend-tooltip" role="status"></div>
            </div>
            <div class="trend-stats" id="trend-stats" hidden></div>
            <p class="trend-summary" id="trend-summary" aria-live="polite">Loading sensor readings…</p>
        </section>
        <?php if ($dataError): ?><p class="error" role="alert"><?= e($dataError) ?></p><?php endif; ?>
        <section class="readings" id="readings" aria-labelledby="readings-title">
            <div class="panel-title"><div><h2 id="readings-title">Recent MQ-2 readings</h2><span class="panel-subtitle">Kitchen Zone · latest records</span></div><a class="panel-subtitle" href="readings.php">View all readings</a></div>
            <div class="table-scroll" tabindex="0" role="region" aria-labelledby="readings-title"><table><caption class="sr-only">Most recent Kitchen Zone MQ-2 sensor readings</caption><thead><tr><th scope="col">Reading ID</th><th scope="col">Sensor value</th><th scope="col">Status</th><th scope="col">Recorded</th></tr></thead><tbody>
            <?php foreach ($readings as $reading): ?>
            <tr><td>#<?= display_value($reading['reading_id'] ?? null) ?></td><td><?= display_value($reading['sensor_value'] ?? null) ?></td><td><?= status_badge(aqm_quality_status($reading['sensor_value'] ?? null, $thresholds)) ?></td><td><?= display_recorded_at($reading['recorded_at'] ?? null) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$readings): ?><tr><td class="empty-cell" colspan="4"><?= $dataError ? 'Readings are temporarily unavailable. Please refresh in a moment.' : 'No readings have been received yet. Ask Staff to check the MQ-2 device setup and ESP32 connection.' ?></td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <section class="guide" id="status-guide" aria-labelledby="guide-title">
            <div class="guide-head"><h2 id="guide-title">Sensor reading status</h2><p>Uses the configured sensor limits; values are not ppm</p></div>
            <div class="guide-items">
                <div class="guide-item"><span class="guide-swatch good" aria-hidden="true"></span><div><strong>Good</strong><span>0–<?= e((string)($thresholds['good_max']??'—')) ?> · Normal conditions</span></div></div>
                <div class="guide-item"><span class="guide-swatch moderate" aria-hidden="true"></span><div><strong>Moderate</strong><span>&gt;<?= e((string)($thresholds['good_max']??'—')) ?>–<?= e((string)($thresholds['moderate_max']??'—')) ?> · Keep monitoring</span></div></div>
                <div class="guide-item"><span class="guide-swatch hazard" aria-hidden="true"></span><div><strong>Hazardous</strong><span>&gt;<?= e((string)($thresholds['moderate_max']??'—')) ?> · Elevated reading</span></div></div>
                <div class="guide-item"><span class="guide-swatch very-hazardous" aria-hidden="true"></span><div><strong>Very Hazardous</strong><span>&gt;<?= e((string)($thresholds['hazardous_max']??'—')) ?> · Highest status</span></div></div>
            </div>
        </section>
        <p class="footnote" id="dashboard-refresh-status" role="status" aria-live="polite">Readings update automatically every 3 seconds. Management actions are reserved for authorized staff.</p>
    </main>
</div>
</div>
<dialog class="reading-alert-dialog" id="reading-alert-dialog"
    data-reading-id="<?= e((string)($latest['reading_id'] ?? '')) ?>"
    data-status="<?= e($initialAlertStatus) ?>"
    data-value="<?= e((string)($latest['sensor_value'] ?? '')) ?>"
    data-recorded-at="<?= e((string)($latest['recorded_at'] ?? '')) ?>"
    aria-labelledby="reading-alert-title">
    <div class="reading-alert-content">
        <span class="reading-alert-kicker">Kitchen Zone · MQ-2</span>
        <h2 id="reading-alert-title">Elevated sensor reading</h2>
        <p><strong id="reading-alert-status"></strong> reading: <strong id="reading-alert-value"></strong></p>
        <p id="reading-alert-time" class="reading-alert-time"></p>
        <p class="reading-alert-note">The reading crossed a configured threshold. This raw sensor value is not a calibrated gas concentration. Check your surroundings and follow your household safety procedures.</p>
        <form method="dialog"><button class="reading-alert-dismiss">Acknowledge</button></form>
    </div>
</dialog>
<script>
(() => {
    let readings = <?= json_encode($trendReadings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
    const limits = <?= json_encode($chartLimits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
    const canvas = document.getElementById('air-quality-chart');
    const summary = document.getElementById('trend-summary');
    const tooltip = document.getElementById('trend-tooltip');
    const statsBox = document.getElementById('trend-stats');
    const filterButtons = Array.from(document.querySelectorAll('.trend-filter'));
    const context = canvas.getContext('2d');
    const root = document.documentElement;
    const dayMs = 24 * 60 * 60 * 1000;

    let activeRange = '7days';
    let points = [];       // filtered readings, oldest -> newest
    let plotted = [];      // same points with pixel coordinates
    let hover = -1;
    let bounds = null;
    let scale = null;
    let frame = 0;

    // Chart colours come from the stylesheet tokens, so light / dark mode just works.
    const token = name => getComputedStyle(root).getPropertyValue(name).trim();
    const rgba = (hex, alpha) => {
        const match = /^#([0-9a-f]{6})$/i.exec(hex);
        if (!match) return hex;
        const n = parseInt(match[1], 16);
        return `rgba(${n >> 16}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
    };
    const formatValue = value => Number.isInteger(value) ? String(value) : value.toFixed(1);
    const fullDate = time => new Date(time).toLocaleString([], {dateStyle: 'medium', timeStyle: 'short'});

    function statusOf(value) {
        if (limits.good_max !== null && value <= limits.good_max) return ['good', 'Good'];
        if (limits.moderate_max !== null && value <= limits.moderate_max) return ['moderate', 'Moderate'];
        if (limits.hazardous_max !== null && value <= limits.hazardous_max) return ['hazard', 'Hazardous'];
        if (limits.hazardous_max !== null) return ['very-hazardous', 'Very Hazardous'];
        return null;
    }

    function selectedSince(now) {
        if (activeRange === 'today') {
            const start = new Date(now);
            start.setHours(0, 0, 0, 0);
            return start.getTime();
        }
        return now.getTime() - (activeRange === '7days' ? 7 : 30) * dayMs;
    }

    function formatTick(time) {
        const date = new Date(time);
        return activeRange === 'today'
            ? date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
            : date.toLocaleDateString([], {month: 'short', day: 'numeric'});
    }

    function renderStats() {
        statsBox.replaceChildren();
        if (!points.length) { statsBox.hidden = true; return; }
        const values = points.map(p => p.value);
        const average = values.reduce((a, b) => a + b, 0) / values.length;
        const items = [
            ['Latest', values[values.length - 1]],
            ['Average', average],
            ['Lowest', Math.min(...values)],
            ['Highest', Math.max(...values)],
        ];
        for (const [label, value] of items) {
            const box = document.createElement('div');
            const status = statusOf(value);
            box.className = 'trend-stat' + (status ? ' ' + status[0] : '');
            const name = document.createElement('span');
            name.textContent = label;
            const figure = document.createElement('strong');
            figure.textContent = formatValue(value);
            box.append(name, figure);
            statsBox.append(box);
        }
        statsBox.hidden = false;
    }

    // Recompute data + geometry (on range change, resize, theme change).
    function layout() {
        if (!context) {
            summary.textContent = 'This browser cannot display the sensor chart.';
            return;
        }
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        if (!width || !height) return;

        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        context.setTransform(ratio, 0, 0, ratio, 0, 0);

        const now = new Date();
        const start = selectedSince(now);
        points = readings
            .map(r => ({time: new Date(r.recorded_at).getTime(), value: Number(r.sensor_value)}))
            .filter(p => Number.isFinite(p.time) && Number.isFinite(p.value) && p.time >= start && p.time <= now.getTime())
            .sort((a, b) => a.time - b.time);

        const left = 48, right = width - 16, top = 14, bottom = height - 44;
        bounds = {width, height, left, right, top, bottom, plotWidth: Math.max(1, right - left), plotHeight: Math.max(1, bottom - top)};
        hover = -1;
        tooltip.style.display = 'none';
        plotted = [];
        scale = null;

        if (points.length) {
            const values = points.map(p => p.value);
            const rawMin = Math.min(...values);
            const rawMax = Math.max(...values);
            const pad = rawMax === rawMin ? Math.max(1, Math.abs(rawMax) * 0.1) : (rawMax - rawMin) * 0.14;
            const minValue = Math.max(0, rawMin - pad);
            const maxValue = rawMax + pad;
            const valueSpan = Math.max(1, maxValue - minValue);
            const t0 = points[0].time;
            const timeSpan = points[points.length - 1].time - t0;
            const xFor = t => timeSpan > 0 ? bounds.left + ((t - t0) / timeSpan) * bounds.plotWidth : bounds.left + bounds.plotWidth / 2;
            const yFor = v => bounds.bottom - ((v - minValue) / valueSpan) * bounds.plotHeight;
            scale = {minValue, maxValue, valueSpan, t0, timeSpan, yFor};
            plotted = points.map(p => ({...p, x: xFor(p.time), y: yFor(p.value)}));

            const newest = points[points.length - 1];
            canvas.setAttribute('aria-label', `${points.length} MQ-2 readings plotted from oldest to newest. The newest reading is ${formatValue(newest.value)} at ${fullDate(newest.time)}. Use the left and right arrow keys to step through readings.`);
            summary.textContent = `${points.length} actual sensor ${points.length === 1 ? 'reading' : 'readings'} shown, ordered from oldest to newest on the chart. Latest: ${formatValue(newest.value)} at ${fullDate(newest.time)}.`;
        } else {
            canvas.setAttribute('aria-label', 'No MQ-2 readings are available for the selected time range.');
            summary.textContent = 'No recorded MQ-2 readings for the selected time range.';
        }
        renderStats();
        paint();
    }

    function paint() {
        if (!context || !bounds) return;
        const {width, height, left, right, top, bottom, plotWidth, plotHeight} = bounds;
        const font = getComputedStyle(document.body).fontFamily;
        const cLine = token('--line'), cMuted = token('--muted'), cGreen = token('--green-600') || token('--green');
        const cSurface = token('--surface'), cGood = token('--good'), cWarn = token('--warn'), cBad = token('--bad'), cVeryBad = token('--very-bad');

        context.clearRect(0, 0, width, height);
        context.font = `10px ${font}`;
        context.lineWidth = 1;

        if (!plotted.length) {
            context.fillStyle = cMuted;
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.fillText('No sensor readings for this time range.', width / 2, height / 2);
            return;
        }

        // grid + y labels
        context.strokeStyle = cLine;
        context.fillStyle = cMuted;
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        for (let tick = 0; tick <= 4; tick++) {
            const value = scale.minValue + (scale.valueSpan * tick / 4);
            const y = bottom - (plotHeight * tick / 4);
            context.beginPath();
            context.moveTo(left, y);
            context.lineTo(right, y);
            context.stroke();
            context.fillText(formatValue(value), left - 9, y);
        }

        // status limit guide lines (only when they fall inside the visible range)
        const guides = [[limits.good_max, cGood, 'Good maximum'], [limits.moderate_max, cWarn, 'Moderate maximum'], [limits.hazardous_max, cVeryBad, 'Hazardous maximum']];
        context.save();
        context.setLineDash([5, 4]);
        for (const [value, color, label] of guides) {
            if (value === null || value <= scale.minValue || value >= scale.maxValue) continue;
            const y = scale.yFor(value);
            context.strokeStyle = rgba(color, .7);
            context.beginPath();
            context.moveTo(left, y);
            context.lineTo(right, y);
            context.stroke();
            context.setLineDash([]);
            context.fillStyle = color;
            context.textAlign = 'right';
            context.textBaseline = 'bottom';
            context.fillText(label, right - 2, y - 3);
            context.setLineDash([5, 4]);
        }
        context.restore();

        // axis titles + x ticks
        context.save();
        context.translate(12, top + plotHeight / 2);
        context.rotate(-Math.PI / 2);
        context.fillStyle = cMuted;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText('MQ-2 sensor value', 0, 0);
        context.restore();

        context.fillStyle = cMuted;
        context.textBaseline = 'top';
        const tickCount = width < 420 ? 3 : 5;
        if (scale.timeSpan > 0) {
            for (let i = 0; i < tickCount; i++) {
                const t = scale.t0 + scale.timeSpan * i / (tickCount - 1);
                const x = left + plotWidth * i / (tickCount - 1);
                context.textAlign = i === 0 ? 'left' : i === tickCount - 1 ? 'right' : 'center';
                context.fillText(formatTick(t), x, bottom + 9);
            }
        } else {
            context.textAlign = 'center';
            context.fillText(formatTick(scale.t0), left + plotWidth / 2, bottom + 9);
        }
        context.textAlign = 'center';
        context.fillText('Date / time', left + plotWidth / 2, height - 14);

        // area under the line
        const gradient = context.createLinearGradient(0, top, 0, bottom);
        gradient.addColorStop(0, rgba(cGreen, .28));
        gradient.addColorStop(1, rgba(cGreen, 0));
        const trace = () => {
            context.moveTo(plotted[0].x, plotted[0].y);
            for (let i = 1; i < plotted.length - 1; i++) {
                const mx = (plotted[i].x + plotted[i + 1].x) / 2;
                const my = (plotted[i].y + plotted[i + 1].y) / 2;
                context.quadraticCurveTo(plotted[i].x, plotted[i].y, mx, my);
            }
            const last = plotted[plotted.length - 1];
            context.lineTo(last.x, last.y);
        };
        if (plotted.length > 1) {
            context.beginPath();
            trace();
            context.lineTo(plotted[plotted.length - 1].x, bottom);
            context.lineTo(plotted[0].x, bottom);
            context.closePath();
            context.fillStyle = gradient;
            context.fill();

            context.beginPath();
            trace();
            context.strokeStyle = cGreen;
            context.lineWidth = 2.5;
            context.lineJoin = 'round';
            context.lineCap = 'round';
            context.stroke();
        }

        // markers: every point when sparse, always the newest
        const dot = (point, radius, ring) => {
            context.beginPath();
            context.arc(point.x, point.y, radius, 0, Math.PI * 2);
            context.fillStyle = cSurface;
            context.fill();
            context.lineWidth = ring;
            context.strokeStyle = cGreen;
            context.stroke();
        };
        if (plotted.length < 40) plotted.forEach(p => dot(p, 3, 1.8));
        else dot(plotted[plotted.length - 1], 3.5, 2);

        // hover crosshair
        if (hover >= 0 && plotted[hover]) {
            const point = plotted[hover];
            context.save();
            context.setLineDash([3, 3]);
            context.strokeStyle = rgba(cGreen, .6);
            context.lineWidth = 1;
            context.beginPath();
            context.moveTo(point.x, top);
            context.lineTo(point.x, bottom);
            context.stroke();
            context.restore();
            context.beginPath();
            context.arc(point.x, point.y, 9, 0, Math.PI * 2);
            context.fillStyle = rgba(cGreen, .18);
            context.fill();
            dot(point, 4.5, 2.5);
        }
    }

    function schedulePaint() {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(paint);
    }

    function showTooltip(index) {
        const point = plotted[index];
        if (!point) { tooltip.style.display = 'none'; return; }
        const value = document.createElement('strong');
        const status = statusOf(point.value);
        value.textContent = `MQ-2: ${formatValue(point.value)}`;
        if (status) {
            const badge = document.createElement('em');
            badge.className = status[0];
            badge.textContent = ` · ${status[1]}`;
            value.append(badge);
        }
        const date = document.createElement('small');
        date.textContent = fullDate(point.time);
        tooltip.replaceChildren(value, date);
        tooltip.style.display = 'block';
        const wrapWidth = canvas.clientWidth;
        const flip = point.x + 14 + tooltip.offsetWidth > wrapWidth - 6;
        tooltip.style.left = `${flip ? Math.max(4, point.x - tooltip.offsetWidth - 14) : point.x + 14}px`;
        tooltip.style.top = `${Math.max(4, point.y - tooltip.offsetHeight - 12)}px`;
    }

    function setHover(index) {
        hover = index;
        showTooltip(index);
        schedulePaint();
    }

    canvas.addEventListener('pointermove', event => {
        if (!plotted.length) return;
        const rect = canvas.getBoundingClientRect();
        const x = event.clientX - rect.left;
        if (x < bounds.left - 12 || x > bounds.right + 12) { clearHover(); return; }
        let nearest = 0, best = Infinity;
        for (let i = 0; i < plotted.length; i++) {
            const d = Math.abs(plotted[i].x - x);
            if (d < best) { best = d; nearest = i; }
        }
        if (nearest !== hover) setHover(nearest);
    });

    function clearHover() {
        if (hover === -1) return;
        hover = -1;
        tooltip.style.display = 'none';
        schedulePaint();
    }
    canvas.addEventListener('pointerleave', clearHover);
    canvas.addEventListener('pointercancel', clearHover);
    canvas.addEventListener('blur', clearHover);

    // keyboard: arrows step through readings, Home / End jump, Escape clears
    canvas.addEventListener('keydown', event => {
        if (!plotted.length) return;
        const last = plotted.length - 1;
        let next = null;
        if (event.key === 'ArrowLeft') next = hover < 0 ? last : Math.max(0, hover - 1);
        else if (event.key === 'ArrowRight') next = hover < 0 ? last : Math.min(last, hover + 1);
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = last;
        else if (event.key === 'Escape') { clearHover(); return; }
        if (next === null) return;
        event.preventDefault();
        setHover(next);
        const point = plotted[next];
        summary.textContent = `MQ-2 ${formatValue(point.value)} at ${fullDate(point.time)}.`;
    });

    filterButtons.forEach(button => button.addEventListener('click', () => {
        activeRange = button.dataset.range;
        filterButtons.forEach(filter => filter.setAttribute('aria-pressed', String(filter === button)));
        layout();
    }));

    if ('ResizeObserver' in window) new ResizeObserver(layout).observe(canvas);
    else window.addEventListener('resize', layout);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { layout(); });
    window.updateAirQualityTrend = newReadings => {
        const byId = new Map(readings.map(reading => [String(reading.reading_id), reading]));
        for (const reading of newReadings) byId.set(String(reading.reading_id), reading);
        readings = Array.from(byId.values())
            .sort((a, b) => new Date(b.recorded_at) - new Date(a.recorded_at))
            .slice(0, 1000);
        layout();
    };
    layout();
})();
</script>
<script>
(() => {
    const refreshInterval = 3000;
    const cards = Array.from(document.querySelectorAll('.cards .card'));
    const valueElement = cards[1]?.querySelector('.value');
    const statusElement = cards[2]?.querySelector('.value');
    const recordedElement = cards[3]?.querySelector('.value');
    const tableBody = document.querySelector('#readings tbody');
    const refreshStatus = document.getElementById('dashboard-refresh-status');
    let lastReadingId = Array.from(tableBody?.querySelectorAll('tr') || []).reduce((maxId, row) => {
        const id = Number.parseInt(row.cells?.[0]?.textContent?.replace('#', '').trim() || '', 10);
        return Number.isFinite(id) ? Math.max(maxId, id) : maxId;
    }, 0);
    let refreshing = false;

    const formatTime = value => {
        if (!value) return '—';
        const date = new Date(value);
        return Number.isFinite(date.getTime())
            ? date.toLocaleString([], {dateStyle: 'medium', timeStyle: 'short'})
            : String(value);
    };

    function updateCards(latest) {
        if (!latest) {
            if (valueElement) valueElement.textContent = '—';
            if (statusElement) statusElement.textContent = 'No data';
            if (recordedElement) recordedElement.textContent = '—';
            return;
        }
        if (valueElement) valueElement.textContent = latest.sensor_value ?? '—';
        if (statusElement) {
            const badge = document.createElement('span');
            badge.className = `status-badge ${latest.status_class}`;
            badge.textContent = latest.status || 'No data';
            statusElement.replaceChildren(badge);
        }
        if (recordedElement) recordedElement.textContent = formatTime(latest.recorded_at);
        document.dispatchEvent(new CustomEvent('airsense:reading-update', {detail: {
            reading_id: latest.reading_id,
            value: latest.sensor_value,
            status: latest.status,
            recorded_at: latest.recorded_at
        }}));
    }

    function addReadings(readings) {
        if (!tableBody || !readings.length) return;
        tableBody.querySelector('.empty-cell')?.closest('tr')?.remove();
        for (const reading of readings) {
            const id = Number.parseInt(reading.reading_id, 10);
            if (!Number.isFinite(id) || id <= lastReadingId) continue;
            const row = document.createElement('tr');
            const idCell = document.createElement('td');
            idCell.textContent = `#${reading.reading_id}`;
            const valueCell = document.createElement('td');
            valueCell.textContent = reading.sensor_value ?? '—';
            const statusCell = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = `status-badge ${reading.status_class}`;
            badge.textContent = reading.status || 'No data';
            statusCell.append(badge);
            const timeCell = document.createElement('td');
            timeCell.textContent = formatTime(reading.recorded_at);
            row.append(idCell, valueCell, statusCell, timeCell);
            tableBody.prepend(row);
            lastReadingId = Math.max(lastReadingId, id);
        }
        while (tableBody.rows.length > 100) tableBody.deleteRow(-1);
    }

    async function refreshDashboard() {
        if (document.hidden || refreshing) {
            window.setTimeout(refreshDashboard, refreshInterval);
            return;
        }
        refreshing = true;
        try {
            const url = new URL('dashboard.php', window.location.href);
            url.searchParams.set('dashboard_data', '1');
            url.searchParams.set('after_id', String(lastReadingId));
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {Accept: 'application/json'}
            });
            const data = await response.json();
            if (!response.ok || data.error) throw new Error(data.error || `HTTP ${response.status}`);

            updateCards(data.latest);
            addReadings(data.new_readings || []);
            if (typeof window.updateAirQualityTrend === 'function') {
                window.updateAirQualityTrend(data.trend || []);
            }
            if (refreshStatus) refreshStatus.textContent = `Updated at ${new Date().toLocaleTimeString()}. Refreshing every 3 seconds.`;
        } catch (error) {
            if (refreshStatus) refreshStatus.textContent = 'Could not refresh readings. Retrying in 3 seconds.';
            console.error('User dashboard auto-refresh failed:', error);
        } finally {
            refreshing = false;
            window.setTimeout(refreshDashboard, refreshInterval);
        }
    }

    window.setTimeout(refreshDashboard, refreshInterval);
})();
</script>
<script src="reading-alerts.js" defer></script>
</body>
</html>
