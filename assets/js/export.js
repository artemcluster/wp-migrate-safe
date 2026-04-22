(function () {
    'use strict';

    if (typeof window.WPMS === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function () {
        const start = document.getElementById('wpms-export-start');
        if (!start) return;

        const progress = document.getElementById('wpms-export-progress');
        const fill = progress.querySelector('.wpms-progress-fill');
        const text = progress.querySelector('.wpms-progress-text');
        const detail = progress.querySelector('.wpms-progress-detail');
        const abortBtn = document.getElementById('wpms-export-abort');
        const result = document.getElementById('wpms-export-result');
        const error = document.getElementById('wpms-export-error');

        let currentJobId = null;
        let cancelRequested = false;

        start.addEventListener('click', startExport);
        abortBtn.addEventListener('click', function () {
            cancelRequested = true;
            if (currentJobId) {
                window.WPMS.api.postJson('export/abort', { job_id: currentJobId })
                    .catch(function () {});
            }
        });

        async function startExport() {
            hide(error); hide(result); show(progress);
            cancelRequested = false;

            try {
                const init = await window.WPMS.api.postJson('export/start', {});
                currentJobId = init.job_id;

                while (true) {
                    if (cancelRequested) throw new Error('Cancelled');

                    const step = await window.WPMS.api.postJson('export/step', { job_id: currentJobId });
                    updateUI(step);

                    if (step.status === 'completed') {
                        hide(progress); show(result);
                        const filename = (step.meta && step.meta.filename) || '';
                        result.innerHTML = '<div class="notice notice-success"><p>' +
                            'Export complete: <strong>' + escapeHtml(filename) + '</strong>. ' +
                            'See the Backups tab.</p></div>';
                        currentJobId = null;
                        return;
                    }

                    if (step.status === 'failed') {
                        throw buildError(step);
                    }

                    if (step.status === 'aborted') {
                        throw new Error('Export aborted.');
                    }

                    await delay(250); // short pause between slices
                }
            } catch (err) {
                hide(progress); show(error);
                error.innerHTML = '<p>' + escapeHtml(err.message || String(err)) + '</p>';
                currentJobId = null;
            }
        }

        function updateUI(step) {
            fill.style.width = step.progress + '%';
            text.textContent = step.progress + '%';
            detail.textContent = stepDescription(step);
        }

        function stepDescription(step) {
            const msg = (step.meta && step.meta.last_message) || '';
            if (msg) return msg;
            // Fallback: show step index.
            return 'Step ' + (step.step_index + 1);
        }

        function buildError(step) {
            const err = new Error(
                (step.error && step.error.message) || 'Export failed'
            );
            err.code = step.error && step.error.code;
            err.step = step.error && step.error.step;
            return err;
        }

        function show(el) { el.style.display = 'block'; }
        function hide(el) { el.style.display = 'none'; }
        function delay(ms) { return new Promise(r => setTimeout(r, ms)); }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }
    });
})();
