(() => {
    const dialog = document.getElementById('reading-alert-dialog');
    if (!(dialog instanceof HTMLDialogElement)) return;

    const statusLabel = document.getElementById('reading-alert-status');
    const valueLabel = document.getElementById('reading-alert-value');
    const timeLabel = document.getElementById('reading-alert-time');
    const storageKey = 'airsense-last-alert-reading';
    let lastAlertReadingId = '';

    try {
        lastAlertReadingId = sessionStorage.getItem(storageKey) || '';
    } catch (error) {
        console.warn('Could not read alert dismissal state from session storage.', error);
    }

    function showAlert(reading) {
        if (!reading || !['Hazardous', 'Very Hazardous'].includes(reading.status)) return;

        const readingId = String(reading.reading_id ?? '');
        if (!readingId || readingId === lastAlertReadingId) return;

        lastAlertReadingId = readingId;
        try {
            sessionStorage.setItem(storageKey, readingId);
        } catch (error) {
            console.warn('Could not save alert dismissal state to session storage.', error);
        }

        statusLabel.textContent = reading.status;
        valueLabel.textContent = reading.value === null ? '—' : String(reading.value);
        const recordedAt = reading.recorded_at ? new Date(reading.recorded_at) : null;
        timeLabel.textContent = recordedAt && Number.isFinite(recordedAt.getTime())
            ? `Recorded ${recordedAt.toLocaleString()}`
            : '';
        dialog.dataset.level = reading.status.toLowerCase().replaceAll(' ', '-');

        if (!dialog.open) dialog.showModal();
    }

    showAlert({
        reading_id: dialog.dataset.readingId,
        status: dialog.dataset.status,
        value: dialog.dataset.value,
        recorded_at: dialog.dataset.recordedAt,
    });
    document.addEventListener('airsense:reading-update', event => showAlert(event.detail));

    async function checkLatestReading() {
        if (document.hidden) return;
        try {
            const response = await fetch('dashboard.php?latest_reading=1', {
                headers: {Accept: 'application/json'},
                cache: 'no-store',
                credentials: 'same-origin',
            });
            const reading = await response.json();
            if (!response.ok || reading.error) {
                throw new Error(reading.error || `Request failed with HTTP ${response.status}.`);
            }
            showAlert(reading);
        } catch (error) {
            console.error('Could not check for a new sensor alert.', error);
        }
    }

    // Check immediately when any user page with the alert dialog is opened,
    // then keep watching for new readings while the page remains open.
    checkLatestReading();
    window.setInterval(checkLatestReading, 3000);
})();
