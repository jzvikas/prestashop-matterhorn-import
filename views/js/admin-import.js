(() => {
    'use strict';

    const app = document.getElementById('matterhorn-import-app');
    if (!app) {
        return;
    }

    const startButton = document.getElementById('matterhorn-start');
    const cancelButton = document.getElementById('matterhorn-cancel');
    const batchSizeInput = document.getElementById('matterhorn-batch-size');
    const progressBar = document.getElementById('matterhorn-progress-bar');
    const statusBox = document.getElementById('matterhorn-status');
    const errorBox = document.getElementById('matterhorn-error');

    let runId = Number(app.dataset.activeJob || 0);
    let running = false;
    let batchInFlight = false;
    let cancelRequested = false;
    let transientBatchFailures = 0;
    const startAllowed = !startButton.disabled;
    const maxTransientBatchRetries = 3;

    class MatterhornHttpError extends Error {
        constructor(message, status = 0, retryable = false) {
            super(message);
            this.name = 'MatterhornHttpError';
            this.status = status;
            this.retryable = retryable;
        }
    }

    const isTransientDatabaseDisconnect = (status, raw, payload = null) => {
        if (status !== 500) {
            return false;
        }

        const details = [
            payload && payload.detail,
            payload && payload.message,
            payload && payload.class,
            raw,
        ].filter((value) => typeof value === 'string' && value !== '').join('\n');

        return /MySQL server has gone away|Lost connection to MySQL server|Doctrine\\DBAL\\Exception\\ConnectionLost|SQLSTATE\[HY000\].*(?:2006|2013)/i.test(details);
    };

    const parseResponse = async (response) => {
        const raw = await response.text();
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch (error) {
            const retryable = (response.status >= 502 && response.status <= 504)
                || isTransientDatabaseDisconnect(response.status, raw);
            throw new MatterhornHttpError(
                retryable
                    ? `Temporary server/database connection failure (${response.status}). The same crash-safe batch will be retried automatically.`
                    : `Server returned a non-JSON response (${response.status}). Reload the page before continuing.`,
                response.status,
                retryable
            );
        }

        if (!response.ok || !payload.success) {
            const retryable = (response.status >= 502 && response.status <= 504)
                || isTransientDatabaseDisconnect(response.status, raw, payload);
            throw new MatterhornHttpError(
                retryable
                    ? `Temporary server/database connection failure (${response.status}). The same crash-safe batch will be retried automatically.`
                    : (payload.message || payload.detail || `Request failed (${response.status}).`),
                response.status,
                retryable
            );
        }

        return payload;
    };

    const post = async (url, values) => {
        const body = new URLSearchParams(values);
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
        } catch (error) {
            throw new MatterhornHttpError('Network error while communicating with the Matterhorn import endpoint.', 0, true);
        }

        return parseResponse(response);
    };

    const getBatchSize = () => Math.max(1, Math.min(1000, Number(batchSizeInput.value || 250)));

    const showError = (message) => {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    };

    const clearError = () => {
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    };

    const normalizePercent = (value) => Math.max(0, Math.min(100, Number.isFinite(value) ? value : 0));

    const formatStatus = (job, displayedRunId) => {
        const progress = job && typeof job.progress === 'object' ? job.progress : null;
        const prefix = `Run #${displayedRunId}: ${job.status}.`;
        if (!progress) {
            return prefix;
        }

        const phaseIndex = Math.max(1, Number(progress.phase_index || 1));
        const phaseCount = Math.max(1, Number(progress.phase_count || 4));
        const label = String(progress.label || progress.phase || 'Import');
        const phaseStatus = String(progress.phase_status || 'pending');
        const stats = String(progress.stats || '').trim();

        return [
            prefix,
            `Phase ${phaseIndex}/${phaseCount} — ${label}: ${phaseStatus}.`,
            stats,
        ].filter(Boolean).join(' ');
    };

    const updateJob = (job) => {
        const displayedRunId = Number(job.id_run || runId);
        if (displayedRunId > 0) {
            runId = displayedRunId;
        }

        const active = ['running', 'paused'].includes(String(job.status || ''));
        const progress = job && typeof job.progress === 'object' ? job.progress : null;
        const percent = normalizePercent(Number(progress && progress.overall_percent || (job.status === 'completed' ? 100 : 0)));
        const indeterminate = Boolean(progress && progress.indeterminate && active);

        progressBar.setAttribute('aria-valuemin', '0');
        progressBar.setAttribute('aria-valuemax', '100');
        progressBar.setAttribute('aria-valuenow', String(percent));

        if (indeterminate) {
            progressBar.classList.add('progress-bar-striped', 'progress-bar-animated');
            progressBar.style.width = '100%';
            progressBar.textContent = `${progress.label || 'Import'} ${progress.phase_index || 1}/${progress.phase_count || 4}`;
        } else {
            progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            progressBar.style.width = `${percent}%`;
            progressBar.textContent = `${percent}%`;
        }

        statusBox.textContent = formatStatus(job, displayedRunId);
        startButton.disabled = !startAllowed || (active && running);
        cancelButton.disabled = !active || cancelRequested;

        if (!active) {
            runId = 0;
        }

        return active;
    };

    const refreshStatus = async () => {
        if (runId <= 0) {
            return;
        }

        try {
            const payload = await post(app.dataset.statusUrl, {
                _token: app.dataset.token,
                job_id: String(runId),
            });
            updateJob(payload.job);
        } catch (error) {
            showError(error instanceof Error ? error.message : String(error));
        }
    };

    const performCancel = async () => {
        if (runId <= 0 || batchInFlight) {
            return;
        }

        const cancellingRunId = runId;
        try {
            const payload = await post(app.dataset.cancelUrl, {
                _token: app.dataset.token,
                job_id: String(cancellingRunId),
            });
            cancelRequested = false;
            updateJob(payload.job);
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            cancelRequested = false;
            cancelButton.disabled = runId <= 0;
            showError(error instanceof Error ? error.message : String(error));
        }
    };

    const runNextBatch = async () => {
        if (!running || runId <= 0) {
            if (cancelRequested) {
                await performCancel();
            }
            return;
        }

        batchInFlight = true;
        try {
            const payload = await post(app.dataset.batchUrl, {
                _token: app.dataset.token,
                job_id: String(runId),
                batch_size: String(getBatchSize()),
            });

            transientBatchFailures = 0;
            clearError();
            const active = updateJob(payload.job);
            batchInFlight = false;

            if (cancelRequested) {
                await performCancel();
                return;
            }

            if (active && running) {
                window.setTimeout(runNextBatch, 100);
            } else {
                running = false;
                window.setTimeout(() => window.location.reload(), 800);
            }
        } catch (error) {
            batchInFlight = false;

            if (cancelRequested) {
                running = false;
                await performCancel();
                return;
            }

            if (running
                && error instanceof MatterhornHttpError
                && error.retryable
                && transientBatchFailures < maxTransientBatchRetries
            ) {
                ++transientBatchFailures;
                showError(`${error.message} Retry ${transientBatchFailures}/${maxTransientBatchRetries}...`);
                window.setTimeout(runNextBatch, 1500 * transientBatchFailures);
                return;
            }

            running = false;
            startButton.disabled = !startAllowed;
            showError(error instanceof Error ? error.message : String(error));
            await refreshStatus();
        }
    };

    startButton.addEventListener('click', async () => {
        clearError();
        cancelRequested = false;
        startButton.disabled = true;

        try {
            if (runId <= 0) {
                const payload = await post(app.dataset.startUrl, {
                    _token: app.dataset.token,
                    batch_size: String(getBatchSize()),
                });
                updateJob(payload.job);
            }

            running = true;
            startButton.disabled = true;
            await runNextBatch();
        } catch (error) {
            running = false;
            startButton.disabled = !startAllowed;
            showError(error instanceof Error ? error.message : String(error));
        }
    });

    cancelButton.addEventListener('click', async () => {
        if (runId <= 0) {
            return;
        }

        clearError();
        running = false;
        cancelRequested = true;
        cancelButton.disabled = true;

        if (!batchInFlight) {
            await performCancel();
        }
    });

    // Restore the persisted active run after a Back Office page reload.
    if (runId > 0) {
        void refreshStatus();
    }
})();
