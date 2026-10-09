<?php
require __DIR__ . '/auth.php';
$zones = [];
$previewError = null;
try {
    [$status, $rows] = supabase('/rest/v1/rpc/aqm_public_latest_zone_readings', []);
    if ($status !== 200 || !is_array($rows) || !array_is_list($rows)) {
        if ($status === 404) {
            if (($rows['code'] ?? null) === '42P01') {
                throw new RuntimeException('The latest-readings function exists, but its threshold settings table is missing. Run supabase-admin-threshold-settings.sql, supabase-admin-hazardous-threshold.sql, then run supabase-public-latest-readings.sql again.');
            }
            throw new RuntimeException('The live-readings function is not installed in Supabase yet. Run supabase-public-latest-readings.sql in the Supabase SQL Editor, then refresh this page.');
        }
        $detail = is_string($rows['message'] ?? null) ? ' ' . $rows['message'] : '';
        throw new RuntimeException('Latest readings could not be loaded (HTTP ' . $status . '). Check the Supabase setup and connectivity.' . $detail);
    }
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['zone_name'] ?? null)
            || !is_numeric($row['mq135_value'] ?? null) || !is_string($row['quality_status'] ?? null)) {
            throw new RuntimeException('The latest-readings response has an unexpected format.');
        }
        $value = (float)$row['mq135_value'];
        $recordedAt = '';
        if (is_string($row['recorded_at'] ?? null) && $row['recorded_at'] !== '') {
            try {
                $recordedAt = (new DateTimeImmutable($row['recorded_at']))->format('M j, Y · g:i A');
            } catch (Exception) {
                $recordedAt = $row['recorded_at'];
            }
        }
        $class = match ($row['quality_status']) {
            'Good' => 'good',
            'Moderate' => 'moderate',
            'Hazardous' => 'hazardous',
            'Very Hazardous' => 'very-hazardous',
            default => 'unknown',
        };
        $zones[] = [
            'name' => $row['zone_name'],
            'location' => is_string($row['location'] ?? null) ? $row['location'] : '',
            'recorded_at' => $recordedAt,
            'aqi' => $row['mq135_value'],
            'status' => $row['quality_status'],
            'class' => $class,
            'width' => (int)round(max(0, min(600, $value)) / 600 * 100),
        ];
    }
    // This installation monitors one zone; show only its newest zone result.
    $zones = array_slice($zones, 0, 1);
} catch (RuntimeException $error) {
    $previewError = $error->getMessage();
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
if (isset($_GET['latest_readings']) && $_GET['latest_readings'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code($previewError === null ? 200 : 503);
    echo json_encode(
        ['zones' => $zones, 'error' => $previewError],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Air quality monitoring for one zone, with live MQ-2 readings and clear status updates.">
<title>Air Quality Monitoring</title>
<link rel="stylesheet" href="login.css">
</head>
<body>
<header><div class="container nav"><a class="brand" href="index.php" aria-label="Air Quality Monitoring home"><span class="mark" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 10h17a4 4 0 1 0-4-4M4 16h23M4 22h14a4 4 0 1 1-4 4"/></svg></span><span>AirSense<small>Air Quality Monitoring</small></span></a><nav class="nav-links" aria-label="Main navigation"><a class="overview-link" href="#overview">Overview</a><button class="button" type="button" data-login="user" aria-haspopup="dialog">Sign in &rarr;</button></nav></div></header>
<main class="container">
<section class="hero" id="overview" aria-labelledby="hero-title"><div class="hero-copy"><div class="eyebrow"><span class="dot"></span> A better view of your environment</div><h1 id="hero-title">One zone.<br>One clear view<br>of <span>your air.</span></h1><p class="lead">Monitor air quality in one place with live MQ-2 readings and clear status updates.</p><div class="actions"><button class="button" type="button" data-login="user" aria-haspopup="dialog">Access your account &rarr;</button></div><p class="caption">One monitored zone. Clear air quality updates.</p></div>
<div class="dashboard" aria-label="Latest air quality reading for the monitored zone"><div class="panel-head"><h2>Latest reading</h2><span class="pill">Live database</span></div><div class="summary"><strong id="latest-zone-count"><?= $previewError !== null ? '&mdash;' : count($zones) ?></strong><div><p>Monitored zone</p><small>Latest available MQ-2 reading</small></div></div>
<p class="preview-note error-note" id="latest-reading-error" role="status" aria-live="polite" <?= $previewError === null ? 'hidden' : '' ?>><?= $previewError === null ? '' : $escape($previewError) ?></p>
<p class="preview-note" id="latest-reading-empty" <?= $previewError !== null || $zones ? 'hidden' : '' ?>>No air-quality readings are available yet. New readings will appear here when an ESP32 uploads data.</p>
<div id="latest-reading-zones" aria-live="polite">
<?php foreach ($zones as $zone): ?>
<div class="zone <?= $escape($zone['class']) ?>"><div class="zone-row"><h3><?= $escape($zone['name']) ?><small><?= $escape($zone['location'] ?: 'Monitoring zone') ?><?= $zone['recorded_at'] !== '' ? ' · ' . $escape($zone['recorded_at']) : '' ?></small></h3><div class="reading"><?= $escape($zone['aqi']) ?><span class="status"><?= $escape($zone['status']) ?></span></div></div><div class="track" aria-hidden="true"><div class="fill" style="width:<?= $escape($zone['width']) ?>%"></div></div></div>
<?php endforeach; ?>
</div>
<p class="preview-note" id="latest-reading-note" <?= $previewError !== null || !$zones ? 'hidden' : '' ?>>Showing the latest database reading for the monitored zone.</p>
<p class="preview-note live-update-note">Checking for new readings automatically.</p>
</div></section>
</main><footer><div class="container footer-row"><span>&copy; <?= date('Y') ?> Air Quality Monitoring</span><span>Know your air. Stay aware.</span></div></footer>
<dialog class="login-modal" id="login-modal" aria-labelledby="modal-title" aria-describedby="modal-description" data-role="user">
<div class="modal-top"><h2 id="modal-title">User Login</h2><button type="button" class="close-modal" id="close-login" aria-label="Close login">&times;</button></div>
<p class="modal-subtitle" id="modal-description">Sign in with your user account.</p>
<div class="role-picker" role="group" aria-label="Account type"><button type="button" data-role-choice="user" aria-pressed="true">User</button><button type="button" data-role-choice="staff" aria-pressed="false">Administrator</button></div>
<form id="modal-login-form" method="post" action="login-handler.php">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="role" id="login-role" value="user">
<div class="login-field" id="name-field" hidden><label for="signup-name">Full name</label><input id="signup-name" name="name" type="text" autocomplete="name" maxlength="100" placeholder="Enter your full name" required disabled></div><div class="login-field"><label for="login-email">Email address</label><input id="login-email" name="email" type="email" autocomplete="username" placeholder="you@example.com" required></div>
<div class="login-field"><label for="login-password">Password</label><div class="password-field"><input id="login-password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required><button class="password-toggle" type="button" id="show-password" aria-controls="login-password" aria-pressed="false" aria-label="Show password">Show</button></div></div>
<div class="login-field" id="confirm-field" hidden><label for="confirm-password">Confirm password</label><input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" disabled></div><button class="button login-submit" id="login-submit" type="submit">Sign in as User</button>
<p class="login-message" id="login-message" role="status" aria-live="polite"></p>
<p class="login-help" id="signup-note">New here? Create a User account to get started.</p><button type="button" class="button secondary login-submit" id="toggle-signup">Create User account</button>
</form>
</dialog>
<script>
(() => {
    const zoneCount = document.getElementById('latest-zone-count');
    const zoneList = document.getElementById('latest-reading-zones');
    const errorMessage = document.getElementById('latest-reading-error');
    const emptyMessage = document.getElementById('latest-reading-empty');
    const note = document.getElementById('latest-reading-note');
    const classes = new Set(['good', 'moderate', 'hazardous', 'very-hazardous', 'unknown']);
    let refreshing = false;

    function makeZoneCard(zone) {
        const card = document.createElement('div');
        card.className = 'zone ' + (classes.has(zone.class) ? zone.class : 'unknown');
        const row = document.createElement('div');
        row.className = 'zone-row';
        const title = document.createElement('h3');
        title.textContent = zone.name;
        const details = document.createElement('small');
        details.textContent = [zone.location || 'Monitoring zone', zone.recorded_at].filter(Boolean).join(' · ');
        title.append(details);
        const reading = document.createElement('div');
        reading.className = 'reading';
        reading.append(document.createTextNode(String(zone.aqi)));
        const status = document.createElement('span');
        status.className = 'status';
        status.textContent = zone.status;
        reading.append(status);
        row.append(title, reading);

        const track = document.createElement('div');
        track.className = 'track';
        track.setAttribute('aria-hidden', 'true');
        const fill = document.createElement('div');
        fill.className = 'fill';
        const width = Number(zone.width);
        fill.style.width = `${Number.isFinite(width) ? Math.max(0, Math.min(100, width)) : 0}%`;
        track.append(fill);
        card.append(row, track);
        return card;
    }

    async function refreshLatestReadings() {
        if (document.hidden || refreshing) return;
        refreshing = true;
        try {
            const response = await fetch('index.php?latest_readings=1', {
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            const result = await response.json();
            if (!response.ok || !Array.isArray(result.zones)) {
                throw new Error(typeof result.error === 'string' ? result.error : 'Latest readings could not be refreshed.');
            }

            const cards = result.zones
                .filter(zone => zone && typeof zone.name === 'string' && typeof zone.status === 'string' && Number.isFinite(Number(zone.aqi)))
                .map(makeZoneCard);
            zoneList.replaceChildren(...cards);
            zoneCount.textContent = String(cards.length);
            errorMessage.hidden = true;
            emptyMessage.hidden = cards.length !== 0;
            note.hidden = cards.length === 0;
        } catch (error) {
            errorMessage.textContent = error instanceof Error ? error.message : 'Latest readings could not be refreshed.';
            errorMessage.hidden = false;
        } finally {
            refreshing = false;
        }
    }

    window.setInterval(refreshLatestReadings, 10000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshLatestReadings();
    });
})();
</script>
<script>
const modal = document.getElementById('login-modal');
const form = document.getElementById('modal-login-form');
const password = document.getElementById('login-password');
const showPassword = document.getElementById('show-password');
const message = document.getElementById('login-message');
const submit = document.getElementById('login-submit');
let signupMode = false;
const modeButton = document.getElementById('toggle-signup');
let opener;
let request;
function concealPassword() {
    password.type = 'password';
    showPassword.textContent = 'Show';
    showPassword.setAttribute('aria-pressed', 'false');
    showPassword.setAttribute('aria-label', 'Show password');
}
function selectRole(role) {
    if (request) { request.abort(); request = null; }
    signupMode = false;
    form.action = 'login-handler.php';
    document.getElementById('name-field').hidden = true;
    document.getElementById('signup-name').disabled = true;
    document.querySelector('.role-picker').hidden = false;
    document.getElementById('confirm-field').hidden = true;
    document.getElementById('confirm-password').disabled = true;
    password.removeAttribute('minlength');
    password.autocomplete = 'current-password';
    modeButton.textContent = 'Create User account';
    modeButton.hidden = role === 'staff';
    document.getElementById('signup-note').textContent = 'New accounts are User accounts. Administrator access is assigned by an administrator.';
    const title = role === 'staff' ? 'Administrator' : 'User';
    form.reset();
    concealPassword();
    modal.dataset.role = role;
    document.getElementById('login-role').value = role;
    document.getElementById('modal-title').textContent = title + ' Login';
    document.getElementById('modal-description').textContent = role === 'staff'
        ? 'Sign in with your administrator account.'
        : 'Sign in with your user account.';
    submit.textContent = 'Sign in as ' + title;
    submit.disabled = false;
    message.textContent = '';
    document.querySelectorAll('[data-role-choice]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.roleChoice === role)));
}
document.querySelectorAll('[data-login]').forEach(button => button.addEventListener('click', () => {
    opener = button;
    selectRole(button.dataset.login);
    modal.showModal();
    document.body.classList.add('modal-open');
    document.getElementById('login-email').focus();
}));
document.querySelectorAll('[data-role-choice]').forEach(button => button.addEventListener('click', () => selectRole(button.dataset.roleChoice)));
<?php if (($_GET['account'] ?? '') === 'disabled'): ?>
selectRole('user');
modal.showModal();
document.body.classList.add('modal-open');
message.textContent = 'This account is disabled. Contact your staff administrator.';
<?php endif; ?>
document.getElementById('close-login').addEventListener('click', () => modal.close());
modal.addEventListener('click', event => {
    const bounds = modal.getBoundingClientRect();
    if (event.target === modal && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) modal.close();
});
modal.addEventListener('close', () => {
    if (request) { request.abort(); request = null; }
    form.reset();
    concealPassword();
    document.body.classList.remove('modal-open');
    if (opener) opener.focus();
});
showPassword.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    showPassword.textContent = show ? 'Hide' : 'Show';
    showPassword.setAttribute('aria-pressed', String(show));
    showPassword.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
modeButton.addEventListener('click', () => {
    if (signupMode) { selectRole('user'); return; }
    selectRole('user');
    signupMode = true;
    form.action = 'signup-handler.php';
    document.getElementById('name-field').hidden = false;
    document.getElementById('signup-name').disabled = false;
    document.querySelector('.role-picker').hidden = true;
    document.getElementById('confirm-field').hidden = false;
    document.getElementById('confirm-password').disabled = false;
    document.getElementById('confirm-password').required = true;
    password.minLength = 8;
    password.autocomplete = 'new-password';
    document.getElementById('modal-title').textContent = 'Create User account';
    document.getElementById('modal-description').textContent = 'Create your User account with an email and a password of at least 8 characters.';
    submit.textContent = 'Create User account';
    modeButton.textContent = 'Back to sign in';
    document.getElementById('login-email').focus();
});
form.addEventListener('submit', async event => {
    event.preventDefault();
    if (signupMode && password.value !== document.getElementById('confirm-password').value) { message.textContent = 'Passwords do not match.'; return; }
    const controller = new AbortController();
    request = controller;
    submit.disabled = true;
    message.textContent = signupMode ? 'Creating account...' : 'Checking login...';
    try {
        const response = await fetch(form.action, {method: 'POST', body: new FormData(form), signal: controller.signal, headers: {'Accept': 'application/json'}});
        const result = await response.json();
        message.textContent = result.message;
        if (response.ok && signupMode) { password.value = ''; document.getElementById('confirm-password').value = ''; }
        if (response.ok && ['user/dashboard.php', 'staff/dashboard.php'].includes(result.redirect)) window.location.assign(result.redirect);
    } catch (error) {
        if (error.name !== 'AbortError') message.textContent = 'Unable to connect. Please try again.';
    } finally {
        if (request === controller) { request = null; submit.disabled = false; }
    }
});
</script>
</body>
</html>
