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
    const imageProgressBar = document.getElementById('matterhorn-image-progress-bar');
    const imageStatusBox = document.getElementById('matterhorn-image-status');
    const errorBox = document.getElementById('matterhorn-error');

    let runId = Number(app.dataset.activeJob || 0);
    let running = false;
    let batchInFlight = false;
    let cancelRequested = false;
    let transientBatchFailures = 0;
    let imageLoopsRunning = false;
    let lastJob = null;
    let reloadScheduled = false;
    const imageTransientFailures = {1: 0, 2: 0};
    const startAllowed = !startButton.disabled;
    const maxTransientBatchRetries = 3;
    const imageWorkerSlots = [1, 2];
    let lastState = {catalogActive: false, imageActive: false, imageStatus: 'waiting'};

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

    const formatImageStatus = (images) => {
        if (!images || typeof images !== 'object') {
            return 'Images will start automatically when IMPORT begins.';
        }

        return [
            `${String(images.status || 'waiting')}.`,
            `Run queue ${Number(images.done || 0)}/${Number(images.total || 0)} done;`,
            `${Number(images.pending || 0)} pending, ${Number(images.processing || 0)} processing, ${Number(images.failed || 0)} failed.`,
            `Active source backlog: ${Number(images.source_unresolved || 0)} `
                + `(${Number(images.source_pending || 0)} pending, ${Number(images.source_processing || 0)} processing, ${Number(images.source_failed || 0)} failed).`,
            `Reconcile: ${String(images.reconcile_status || 'pending')} (${Number(images.reconcile_done || 0)} products).`,
        ].join(' ');
    };

    const updateButtons = () => {
        startButton.disabled = !startAllowed
            || (lastState.catalogActive ? running : (lastState.imageActive && imageLoopsRunning));
        cancelButton.disabled = !lastState.catalogActive || cancelRequested;
    };

    const updateImages = (job) => {
        const images = job && typeof job.images === 'object' ? job.images : null;
        if (!images) {
            imageProgressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            imageProgressBar.setAttribute('aria-valuenow', '0');
            imageProgressBar.style.width = '0%';
            imageProgressBar.textContent = '0%';
            imageStatusBox.classList.remove('text-danger');
            imageStatusBox.textContent = 'Images will start automatically when IMPORT begins.';
            return false;
        }

        const active = Boolean(images.active);
        const percent = normalizePercent(Number(images.percent || 0));
        const indeterminate = Boolean(images.indeterminate && active);
        imageProgressBar.setAttribute('aria-valuemin', '0');
        imageProgressBar.setAttribute('aria-valuemax', '100');
        imageProgressBar.setAttribute('aria-valuenow', String(percent));

        if (indeterminate) {
            imageProgressBar.classList.add('progress-bar-striped', 'progress-bar-animated');
            imageProgressBar.style.width = '100%';
            imageProgressBar.textContent = `IMAGES — ${String(images.stage || 'download')}`;
        } else {
            imageProgressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            imageProgressBar.style.width = `${percent}%`;
            imageProgressBar.textContent = `${percent}%`;
        }

        imageStatusBox.textContent = formatImageStatus(images);
        imageStatusBox.classList.toggle('text-danger', String(images.status || '') === 'failed');
        return active;
    };

    const updateJob = (job) => {
        lastJob = job;
        const displayedRunId = Number(job.id_run || runId);
        if (displayedRunId > 0) {
            runId = displayedRunId;
        }

        const catalogActive = Boolean(job.active)
            || ['running', 'paused'].includes(String(job.status || ''));
        const imageActive = updateImages(job);
        const progress = job && typeof job.progress === 'object' ? job.progress : null;
        const percent = normalizePercent(Number(progress && progress.overall_percent || (job.status === 'completed' ? 100 : 0)));
        const indeterminate = Boolean(progress && progress.indeterminate && catalogActive);

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
        lastState = {
            catalogActive,
            imageActive,
            imageStatus: String(job.images && job.images.status || 'waiting'),
        };
        updateButtons();

        if (!catalogActive && !imageActive) {
            runId = 0;
        }

        return lastState;
    };

    const scheduleReload = () => {
        if (reloadScheduled) {
            return;
        }
        reloadScheduled = true;
        window.setTimeout(() => window.location.reload(), 900);
    };

    const startImageWorkers = () => {
        if (imageLoopsRunning || runId <= 0 || !lastState.imageActive) {
            return;
        }

        imageLoopsRunning = true;
        updateButtons();
        for (const slot of imageWorkerSlots) {
            void runImageLoop(slot);
        }
    };

    const refreshStatus = async () => {
        if (runId <= 0) {
            return lastState;
        }

        try {
            const payload = await post(app.dataset.statusUrl, {
                _token: app.dataset.token,
                job_id: String(runId),
            });
            const state = updateJob(payload.job);
            if (state.imageActive) {
                startImageWorkers();
            }
            return state;
        } catch (error) {
            showError(error instanceof Error ? error.message : String(error));
            return lastState;
        }
    };

    const performCancel = async () => {
        if (runId <= 0 || batchInFlight) {
            return;
        }

        const cancellingRunId = runId;
        imageLoopsRunning = false;
        try {
            const payload = await post(app.dataset.cancelUrl, {
                _token: app.dataset.token,
                job_id: String(cancellingRunId),
            });
            cancelRequested = false;
            updateJob(payload.job);
            scheduleReload();
        } catch (error) {
            cancelRequested = false;
            updateButtons();
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
            const state = updateJob(payload.job);
            batchInFlight = false;

            if (state.imageActive) {
                startImageWorkers();
            }

            if (cancelRequested) {
                await performCancel();
                return;
            }

            if (state.catalogActive && running) {
                window.setTimeout(runNextBatch, 100);
            } else {
                running = false;
                updateButtons();
                if (!state.imageActive) {
                    scheduleReload();
                }
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
            updateButtons();
            showError(error instanceof Error ? error.message : String(error));
            await refreshStatus();
        }
    };

    async function runImageLoop(slot) {
        if (!imageLoopsRunning || runId <= 0) {
            return;
        }

        const imageRunId = runId;
        try {
            const payload = await post(app.dataset.imagesBatchUrl, {
                _token: app.dataset.token,
                job_id: String(imageRunId),
                worker_slot: String(slot),
            });

            if (!imageLoopsRunning) {
                return;
            }

            imageTransientFailures[slot] = 0;
            const state = updateJob(payload.job);
            if (!state.imageActive) {
                imageLoopsRunning = false;
                updateButtons();
                if (!state.catalogActive) {
                    if (state.imageStatus === 'failed') {
                        const failed = Number(payload.job.images && payload.job.images.source_failed || 0);
                        showError(`Image AJAX phase stopped with ${failed} failed active queue item(s). Review image errors before retrying.`);
                    } else {
                        scheduleReload();
                    }
                }
                return;
            }

            const processed = Number(payload.image_batch && payload.image_batch.processed || 0);
            const reconciled = Number(payload.image_reconcile && payload.image_reconcile.products || 0);
            const delay = processed > 0 || reconciled > 0 ? 75 : 650;
            window.setTimeout(() => void runImageLoop(slot), delay);
        } catch (error) {
            if (!imageLoopsRunning) {
                return;
            }

            if (error instanceof MatterhornHttpError
                && error.retryable
                && imageTransientFailures[slot] < maxTransientBatchRetries
            ) {
                ++imageTransientFailures[slot];
                showError(`${error.message} Image worker ${slot} retry ${imageTransientFailures[slot]}/${maxTransientBatchRetries}...`);
                window.setTimeout(
                    () => void runImageLoop(slot),
                    1500 * imageTransientFailures[slot]
                );
                return;
            }

            imageLoopsRunning = false;
            updateButtons();
            showError(error instanceof Error ? error.message : String(error));
        }
    }

    startButton.addEventListener('click', async () => {
        clearError();
        cancelRequested = false;
        startButton.disabled = true;

        try {
            if (runId > 0 && lastJob === null) {
                await refreshStatus();
            }

            if (runId <= 0) {
                const payload = await post(app.dataset.startUrl, {
                    _token: app.dataset.token,
                    batch_size: String(getBatchSize()),
                });
                updateJob(payload.job);
            }

            if (lastState.imageActive) {
                startImageWorkers();
            }

            if (lastState.catalogActive) {
                running = true;
                updateButtons();
                await runNextBatch();
            } else {
                running = false;
                updateButtons();
            }
        } catch (error) {
            running = false;
            updateButtons();
            showError(error instanceof Error ? error.message : String(error));
        }
    });

    cancelButton.addEventListener('click', async () => {
        if (runId <= 0) {
            return;
        }

        clearError();
        running = false;
        imageLoopsRunning = false;
        cancelRequested = true;
        updateButtons();

        if (!batchInFlight) {
            await performCancel();
        }
    });

    // Restore durable work after a Back Office page reload. Catalogue execution remains manual,
    // while the image queue resumes automatically because each AJAX image request is independently fenced.
    if (runId > 0) {
        void refreshStatus();
    }
})();
