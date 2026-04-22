(function () {
    'use strict';

    if (typeof window.WPMS === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function () {
        const select    = document.getElementById('wpms-import-filename');
        if (!select) return;

        const startBtn  = document.getElementById('wpms-import-start');
        const loadingEl = document.getElementById('wpms-import-loading');
        const sourceUrl = document.getElementById('wpms-import-source-url');
        const progress  = document.getElementById('wpms-import-progress');
        const fill      = progress.querySelector('.wpms-progress-fill');
        const text      = progress.querySelector('.wpms-progress-text');
        const detail    = progress.querySelector('.wpms-progress-detail');
        const abortBtn  = document.getElementById('wpms-import-abort');
        const result    = document.getElementById('wpms-import-result');
        const error     = document.getElementById('wpms-import-error');

        let currentJobId    = null;
        let cancelRequested = false;

        // Load available backups.
        window.WPMS.api.getJson('backups')
            .then(function (data) {
                hide(loadingEl);
                const backups = (data && Array.isArray(data.backups)) ? data.backups : [];
                if (backups.length === 0) {
                    select.innerHTML = '<option value="">' + escapeHtml('No backups available. Upload a .wpress file first.') + '</option>';
                    return;
                }
                select.innerHTML = '<option value="">' + escapeHtml('— Select a backup —') + '</option>';
                backups.forEach(function (b) {
                    const opt = document.createElement('option');
                    opt.value = b.filename;
                    opt.textContent = b.filename + ' (' + humanSize(b.size) + ')';
                    select.appendChild(opt);
                });
            })
            .catch(function (err) {
                hide(loadingEl);
                showError('Failed to load backups: ' + (err.message || String(err)));
            });

        select.addEventListener('change', function () {
            startBtn.disabled = select.value === '';
            if (select.value !== '') {
                autoDetectSourceUrl(select.value);
            }
        });

        function autoDetectSourceUrl(filename) {
            if (!sourceUrl) return;
            // Don't overwrite a value the user already typed.
            if (sourceUrl.value.trim() !== '') return;
            window.WPMS.api.getJson('import/inspect?filename=' + encodeURIComponent(filename))
                .then(function (data) {
                    if (data && typeof data.source_url === 'string' && data.source_url !== '') {
                        sourceUrl.value = data.source_url;
                        sourceUrl.setAttribute('data-auto-detected', '1');
                    }
                })
                .catch(function () { /* silent — user can still type manually */ });
        }

        startBtn.addEventListener('click', startImport);

        abortBtn.addEventListener('click', function () {
            cancelRequested = true;
            if (currentJobId) {
                window.WPMS.api.postJson('import/abort', { job_id: currentJobId })
                    .catch(function () {});
            }
        });

        async function startImport() {
            const filename = select.value;
            if (!filename) {
                showError('Please select a backup file.');
                return;
            }

            hide(error);
            hide(result);
            show(progress);
            cancelRequested = false;

            try {
                const init = await window.WPMS.api.postJson('import/start', {
                    filename: filename,
                    old_url:  (sourceUrl && sourceUrl.value) || '',
                });
                currentJobId = init.job_id;

                while (true) {
                    if (cancelRequested) throw new Error('Import cancelled.');

                    const step = await window.WPMS.api.postJson('import/step', { job_id: currentJobId });
                    updateUI(step);

                    if (step.status === 'completed') {
                        hide(progress);
                        show(result);
                        result.innerHTML = '<div class="notice notice-success"><p>' +
                            escapeHtml('Import complete! Your site has been restored from: ' + filename) +
                            '</p></div>';
                        currentJobId = null;
                        return;
                    }

                    if (step.status === 'failed') {
                        throw buildError(step);
                    }

                    if (step.status === 'aborted') {
                        throw new Error('Import aborted.');
                    }

                    await delay(500);
                }
            } catch (err) {
                hide(progress);
                showError(err.message || String(err));
                currentJobId = null;
            }
        }

        function updateUI(step) {
            fill.style.width = step.progress + '%';
            text.textContent = step.progress + '%';
            detail.textContent = stepDescription(step);
            if (step.stale) {
                detail.textContent += ' ⚠ No heartbeat for >60s — worker may have died. Consider aborting.';
            }
        }

        function stepDescription(step) {
            const msg = (step.meta && step.meta.last_message) || '';
            if (msg) return msg;
            return 'Step ' + (step.step_index + 1);
        }

        function buildError(step) {
            const err = new Error(
                (step.error && step.error.message) || 'Import failed'
            );
            err.code = step.error && step.error.code;
            err.step = step.error && step.error.step;
            return err;
        }

        function showError(msg) {
            show(error);
            error.innerHTML = '<p>' + escapeHtml(msg) + '</p>';
        }

        function show(el) { if (el) el.style.display = 'block'; }
        function hide(el) { if (el) el.style.display = 'none'; }
        function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        function humanSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024)       return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }
    });
})();
