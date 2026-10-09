<?php
require dirname(__DIR__) . '/dashboard-auth.php';
$account = require_account('user');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); exit('This alerts page is read-only.');
}

$readings = []; $dataError = null; $thresholds = [];
function read_alert_rows(string $query, string $token): array {
    [$status, $rows] = supabase('/rest/v1/' . $query, null, $token);
    if ($status !== 200 || !is_array($rows) || !array_is_list($rows)) {
        throw new RuntimeException('Sensor readings could not be loaded. Ask your administrator to check database read permissions, then refresh.');
    }
    return $rows;
}
try {
    $thresholds = aqm_thresholds($account['token']);
    $readings = read_alert_rows(
        'air_quality_readings?select=reading_id,device_id,sensor_value:mq135_value,recorded_at&mq135_value=not.is.null&order=recorded_at.desc.nullslast,reading_id.desc&limit=500',
        $account['token']
    );
} catch (RuntimeException $error) {
    $dataError = $error->getMessage();
    $readings = [];
}

function alert_level($value, array $thresholds): string {
    $status = aqm_quality_status($value, $thresholds);
    return $status === 'No data' ? 'Unknown' : $status;
}
function alert_status_class(string $level): string {
    return match ($level) {
        'Good' => 'status-good',
        'Moderate' => 'status-moderate',
        'Hazardous' => 'status-hazard',
        'Very Hazardous' => 'status-very-hazardous',
        default => 'status-neutral',
    };
}
function alert_state(string $level): string {
    return match ($level) {
        'Hazardous' => 'Triggered',
        'Very Hazardous' => 'Critical',
        'Moderate' => 'Monitor',
        'Good' => 'Normal',
        default => 'Unknown',
    };
}
function alert_recorded_at($value): string {
    if ($value === null || $value === '') return '&mdash;';
    try { return e((new DateTimeImmutable((string)$value))->format('M j, Y · g:i A')); }
    catch (Exception) { return e((string)$value); }
}
function alert_display($value): string {
    return $value === null || $value === '' ? '&mdash;' : e((string)$value);
}

$counts = ['Good' => 0, 'Moderate' => 0, 'Hazardous' => 0, 'Very Hazardous' => 0, 'Unknown' => 0];
foreach ($readings as $reading) {
    $counts[alert_level($reading['sensor_value'] ?? null, $thresholds)]++;
}
$timeline = $readings;
usort($timeline, static function (array $left, array $right): int {
    $leftTime = isset($left['recorded_at']) ? strtotime((string)$left['recorded_at']) : false;
    $rightTime = isset($right['recorded_at']) ? strtotime((string)$right['recorded_at']) : false;
    $timeOrder = ($leftTime === false ? 0 : $leftTime) <=> ($rightTime === false ? 0 : $rightTime);
    if ($timeOrder !== 0) return $timeOrder;
    return (int)($left['reading_id'] ?? 0) <=> (int)($right['reading_id'] ?? 0);
});
$activeAlerts = []; $resolvedAlerts = []; $activeByDevice = [];
foreach ($timeline as $reading) {
    $deviceId = $reading['device_id'] ?? null;
    $eventKey = $deviceId === null
        ? 'reading:' . (string)($reading['reading_id'] ?? count($activeAlerts))
        : 'device:' . (string)$deviceId;
    $level = alert_level($reading['sensor_value'] ?? null, $thresholds);
    if (in_array($level, ['Hazardous', 'Very Hazardous'], true)) {
        if (!isset($activeByDevice[$eventKey])) {
            $activeByDevice[$eventKey] = $reading;
        }
    } elseif (in_array($level, ['Good', 'Moderate'], true) && isset($activeByDevice[$eventKey])) {
        $resolvedAlerts[] = [
            'trigger' => $activeByDevice[$eventKey],
            'resolved_at' => $reading['recorded_at'] ?? null,
        ];
        unset($activeByDevice[$eventKey]);
    }
}
$activeAlerts = array_reverse(array_values($activeByDevice));
$resolvedAlerts = array_reverse($resolvedAlerts);
if ($dataError) $counts = ['Good' => null, 'Moderate' => null, 'Hazardous' => null, 'Very Hazardous' => null, 'Unknown' => null];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#102a43">
<title>Alerts | AirSense IoT monitoring</title>
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
        <a class="nav-link" href="readings.php"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4.5h14v15H5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Readings</a>
        <a class="nav-link" href="alerts.php" aria-current="page"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Alerts</a>
        <a class="nav-link" href="dashboard.php#status-guide"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>Status guide</a>
    </nav>
    <div class="sidebar-spacer"></div>
    <div class="access-card"><div class="access-label"><span class="access-dot" aria-hidden="true"></span>Readings access</div><p>Viewing the latest readings shared with your account.</p></div>
    <div class="profile"><span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($account['email'], 0, 1))) ?></span><div class="profile-copy"><strong><?= e($account['email']) ?></strong><span>Read-only account</span></div></div>
</aside>
<div class="workspace">
    <header class="topbar">
        <div class="breadcrumb">Workspace <span aria-hidden="true">/</span> <strong>Alerts</strong></div>
        <div class="top-actions"><span class="view-label"><span class="top-dot" aria-hidden="true"></span>Read-only access</span><form action="../logout.php" method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="signout" type="submit">Sign out</button></form></div>
    </header>
    <main class="content">
        <section class="page-heading" aria-labelledby="page-title">
            <div><span class="eyebrow">Kitchen Zone · MQ-2 sensor</span><h1 id="page-title">Alerts</h1><p>Sensor alerts and recent status changes.</p></div>
            <a class="refresh" href="alerts.php">Refresh alerts</a>
        </section>
        <section class="summary-cards" aria-label="Recent reading status counts">
            <article class="summary-card"><h2>Good readings</h2><div class="summary-value good-color"><?= $counts['Good'] ?? '&mdash;' ?></div></article>
            <article class="summary-card"><h2>Moderate readings</h2><div class="summary-value moderate-color"><?= $counts['Moderate'] ?? '&mdash;' ?></div></article>
            <article class="summary-card"><h2>Hazardous alerts</h2><div class="summary-value hazard-color"><?= $counts['Hazardous'] ?? '&mdash;' ?></div></article>
            <article class="summary-card"><h2>Very hazardous alerts</h2><div class="summary-value very-hazard-color"><?= $counts['Very Hazardous'] ?? '&mdash;' ?></div></article>
        </section>
        <?php if ($dataError): ?><p class="error" role="alert"><?= e($dataError) ?></p><?php endif; ?>
        <section class="alert-section" aria-labelledby="active-alerts-title">
            <div class="section-heading"><h2 id="active-alerts-title">Active Alerts</h2><span class="section-count"><?= $dataError ? '&mdash;' : count($activeAlerts) ?> active</span></div>
            <?php if ($activeAlerts): ?>
            <div class="active-list">
                <?php foreach ($activeAlerts as $reading):
                ?>
                <article class="alert-card">
                    <div class="alert-card-title"><h3><?= e(aqm_quality_status($reading['sensor_value'] ?? null, $thresholds)) ?> sensor reading</h3><span class="state-badge state-active">Active</span></div>
                    <div class="mq-value"><?= alert_display($reading['sensor_value'] ?? null) ?><small>MQ-2</small></div>
                    <div class="alert-details">
                        <div class="alert-detail"><span>Zone</span><strong>Kitchen Zone</strong></div>
                        <div class="alert-detail"><span>Sensor</span><strong>MQ-2</strong></div>
                        <div class="alert-detail"><span>Date / time</span><strong><?= alert_recorded_at($reading['recorded_at'] ?? null) ?></strong></div>
                        <div class="alert-detail"><span>Alert ID</span><strong>#<?= alert_display($reading['reading_id'] ?? null) ?></strong></div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php elseif (!$dataError): ?>
            <div class="alert-card"><div class="alert-card-title"><h3>No active hazardous alerts</h3><span class="state-badge state-normal">All clear</span></div></div>
            <?php endif; ?>
        </section>
        <section class="resolved-panel" aria-labelledby="resolved-alerts-title">
            <div class="panel-heading"><div><h2 id="resolved-alerts-title">Resolved Alerts</h2><p>Elevated readings followed by a lower reading</p></div><span class="section-count resolved-count"><?= $dataError ? '&mdash;' : count($resolvedAlerts) ?> resolved</span></div>
            <div class="table-scroll"><table>
                <thead><tr><th scope="col">Alert ID</th><th scope="col">MQ-2 value</th><th scope="col">Triggered</th><th scope="col">Resolved at</th><th scope="col">Alert state</th></tr></thead>
                <tbody>
                <?php foreach ($resolvedAlerts as $alert):
                    $reading = $alert['trigger'];
                ?>
                <tr>
                    <td class="alert-id">#<?= alert_display($reading['reading_id'] ?? null) ?></td>
                    <td><span class="status-badge <?= e(alert_status_class(alert_level($reading['sensor_value'] ?? null, $thresholds))) ?>"><?= alert_display($reading['sensor_value'] ?? null) ?></span></td>
                    <td><?= alert_recorded_at($reading['recorded_at'] ?? null) ?></td>
                    <td><?= alert_recorded_at($alert['resolved_at']) ?></td>
                    <td><span class="resolved-state">Resolved</span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$resolvedAlerts): ?><tr><td class="empty-cell" colspan="5"><?= $dataError ? 'Resolved alerts are unavailable until readings can be loaded.' : 'No resolved alerts were found in the recent readings.' ?></td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
        <section class="table-panel" aria-labelledby="alerts-title">
            <div class="panel-heading"><div><h2 id="alerts-title">Recent sensor readings</h2><p>Automatic status from each MQ-2 sensor value</p></div><span class="panel-meta">Latest 500 readings</span></div>
            <div class="table-scroll"><table>
                <thead><tr><th scope="col">Reading ID</th><th scope="col">MQ-2 value</th><th scope="col">Status</th><th scope="col">Date and time</th><th scope="col">Alert state</th></tr></thead>
                <tbody>
                <?php foreach ($readings as $reading):
                    $level = alert_level($reading['sensor_value'] ?? null, $thresholds);
                    $statusClass = alert_status_class($level);
                    $stateClass = match ($level) {
                        'Hazardous', 'Very Hazardous' => 'state-active',
                        'Moderate' => 'state-monitor',
                        'Good' => 'state-normal',
                        default => 'status-neutral',
                    };
                ?>
                <tr>
                    <td class="alert-id">#<?= alert_display($reading['reading_id'] ?? null) ?></td>
                    <td><?= alert_display($reading['sensor_value'] ?? null) ?></td>
                    <td><span class="status-badge <?= e($statusClass) ?>"><?= e($level) ?></span></td>
                    <td><?= alert_recorded_at($reading['recorded_at'] ?? null) ?></td>
                    <td><span class="state-badge <?= e($stateClass) ?>"><?= e(alert_state($level)) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$readings): ?><tr><td class="empty-cell" colspan="5"><?= $dataError ? 'Readings are temporarily unavailable. Please refresh in a moment.' : 'No sensor readings are available yet.' ?></td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
        <p class="footnote">Sensor values are raw readings, not ppm. Configured status limits: Good through <?= e((string)($thresholds['good_max']??'—')) ?> · Moderate through <?= e((string)($thresholds['moderate_max']??'—')) ?> · Hazardous through <?= e((string)($thresholds['hazardous_max']??'—')) ?> · Very Hazardous above that. Alert episodes are inferred from actual recent readings.</p>
    </main>
</div>
</div>
<dialog class="reading-alert-dialog" id="reading-alert-dialog" aria-labelledby="reading-alert-title">
    <div class="reading-alert-content">
        <span class="reading-alert-kicker">Kitchen Zone · MQ-2</span>
        <h2 id="reading-alert-title">Hazardous sensor reading</h2>
        <p><strong id="reading-alert-status"></strong> reading: <strong id="reading-alert-value"></strong></p>
        <p id="reading-alert-time" class="reading-alert-time"></p>
        <p class="reading-alert-note">This raw sensor value is not a calibrated gas concentration. Check your surroundings and follow your household safety procedures.</p>
        <form method="dialog"><button class="reading-alert-dismiss">Acknowledge</button></form>
    </div>
</dialog>
<script src="reading-alerts.js" defer></script>
</body>
</html>
