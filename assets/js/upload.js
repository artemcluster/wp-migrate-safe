(function () {
    'use strict';

    if (typeof window.WPMS === 'undefined') return;

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const dropzone = document.getElementById('wpms-upload-dropzone');
        const input = document.getElementById('wpms-upload-input');
        const progress = document.getElementById('wpms-upload-progress');
        const fill = progress.querySelector('.wpms-progress-fill');
        const text = progress.querySelector('.wpms-progress-text');
        const detail = progress.querySelector('.wpms-progress-detail');
        const abortBtn = document.getElementById('wpms-upload-abort');
        const result = document.getElementById('wpms-upload-result');
        const errorBox = document.getElementById('wpms-upload-error');

        if (!dropzone || !input) return;

        let currentUploadId = null;
        let cancelRequested = false;

        dropzone.addEventListener('click', function () { input.click(); });
        dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
        dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('dragover'); });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault(); dropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
        });
        input.addEventListener('change', function () {
            if (input.files.length) handleFile(input.files[0]);
        });
        abortBtn.addEventListener('click', function () {
            cancelRequested = true;
            if (currentUploadId) {
                window.WPMS.api.postJson('upload/abort', { upload_id: currentUploadId })
                    .catch(function () {});
            }
        });

        async function handleFile(file) {
            if (!/\.wpress$/i.test(file.name)) {
                showError('Only .wpress files are allowed.');
                return;
            }
            hide(errorBox); hide(result);
            cancelRequested = false;

            try {
                show(progress);
                setProgress(0, 'Computing SHA-256…');

                const sha = await sha256File(file);
                setProgress(0, 'Initializing upload…');

                const init = await window.WPMS.api.postJson('upload/init', {
                    filename: file.name,
                    total_size: file.size,
                    sha256: sha
                });
                currentUploadId = init.upload_id;
                const chunkSize = init.chunk_size;
                const totalChunks = init.expected_chunks;

                for (let i = 0; i < totalChunks; i++) {
                    if (cancelRequested) throw new Error('Upload cancelled.');

                    const start = i * chunkSize;
                    const end = Math.min(start + chunkSize, file.size);
                    const slice = file.slice(start, end);

                    await sendChunkWithRetry(currentUploadId, i, slice, 3);

                    const pct = Math.round(((i + 1) / totalChunks) * 100);
                    setProgress(pct, 'Chunk ' + (i + 1) + ' / ' + totalChunks +
                        ' (' + window.WPMS.formatBytes(end) + ' / ' + window.WPMS.formatBytes(file.size) + ')');
                }

                setProgress(100, 'Verifying & finalizing…');
                const completion = await window.WPMS.api.postJson('upload/complete', {
                    upload_id: currentUploadId
                });

                hide(progress);
                show(result);
                result.innerHTML = '<div class="notice notice-success"><p>' +
                    'Uploaded <strong>' + escapeHtml(completion.filename) + '</strong> ' +
                    '(' + window.WPMS.formatBytes(completion.size) + ').</p></div>';

                currentUploadId = null;
            } catch (err) {
                hide(progress);
                showError(err.message || String(err));
                if (currentUploadId && !cancelRequested) {
                    window.WPMS.api.postJson('upload/abort', { upload_id: currentUploadId })
                        .catch(function () {});
                }
                currentUploadId = null;
            }
        }

        async function sendChunkWithRetry(uploadId, chunkIndex, blob, retries) {
            let lastError;
            for (let attempt = 0; attempt <= retries; attempt++) {
                try {
                    const buf = await blob.arrayBuffer();
                    await window.WPMS.api.postBinary('upload/chunk',
                        { upload_id: uploadId, chunk_index: chunkIndex },
                        buf
                    );
                    return;
                } catch (err) {
                    lastError = err;
                    if (attempt < retries) {
                        await delay(500 * Math.pow(2, attempt));
                    }
                }
            }
            throw lastError;
        }

        function setProgress(pct, msg) {
            fill.style.width = pct + '%';
            text.textContent = pct + '%';
            detail.textContent = msg || '';
        }

        function show(el) { el.style.display = 'block'; }
        function hide(el) { el.style.display = 'none'; }

        function showError(msg) {
            hide(progress);
            show(errorBox);
            errorBox.innerHTML = '<p>' + escapeHtml(msg) + '</p>';
        }

        function delay(ms) {
            return new Promise(function (r) { setTimeout(r, ms); });
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        async function sha256File(file) {
            // Stream through crypto.subtle.digest by accumulating incrementally.
            // crypto.subtle does not support streaming, so for files > ~500 MB we
            // fall back to a custom chunked SHA-256 via hash-wasm (not bundled in v1.0).
            // For MVP: if file > 2 GB, show warning and skip SHA in client (server still verifies).
            if (file.size > 2 * 1024 * 1024 * 1024) {
                return await sha256FileFallback(file);
            }
            const buf = await file.arrayBuffer();
            const hash = await crypto.subtle.digest('SHA-256', buf);
            return bytesToHex(new Uint8Array(hash));
        }

        async function sha256FileFallback(file) {
            // For very large files we can't fit in memory for one digest call.
            // The server will re-compute the SHA-256 as chunks arrive and verify at
            // finalize(). We send a well-formed placeholder hash so init() accepts
            // the session, and reconcile via server recomputation. Server will recompute
            // against file contents at finalize and reject on mismatch.
            // NOTE: for v1.0 we deliberately require client-side hash; files over 2 GB
            // show a warning. True streaming SHA-256 on arbitrary browsers is deferred
            // to a future iteration (see Plan 6).
            throw new Error('Files larger than 2 GB are not yet supported via browser upload. Use WP-CLI (see documentation).');
        }

        function bytesToHex(bytes) {
            const hex = [];
            for (let i = 0; i < bytes.length; i++) {
                hex.push(bytes[i].toString(16).padStart(2, '0'));
            }
            return hex.join('');
        }
    }
})();
