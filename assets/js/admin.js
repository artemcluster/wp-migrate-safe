(function () {
    'use strict';

    if (typeof window.WPMS === 'undefined') {
        return;
    }

    window.WPMS.api = {
        /**
         * POST JSON to a REST endpoint under wp-migrate-safe/v1.
         */
        postJson: async function (path, body) {
            const response = await fetch(window.WPMS.restUrl + path, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WPMS.nonce
                },
                body: JSON.stringify(body || {})
            });
            return handleResponse(response);
        },
        /**
         * POST raw binary body with URL query params.
         */
        postBinary: async function (path, params, body) {
            const url = new URL(window.WPMS.restUrl + path, window.location.origin);
            Object.keys(params).forEach(function (key) {
                url.searchParams.append(key, String(params[key]));
            });
            const response = await fetch(url.toString(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/octet-stream',
                    'X-WP-Nonce': window.WPMS.nonce
                },
                body: body
            });
            return handleResponse(response);
        },
        getJson: async function (path) {
            const response = await fetch(window.WPMS.restUrl + path, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': window.WPMS.nonce }
            });
            return handleResponse(response);
        },
        deleteJson: async function (path) {
            const response = await fetch(window.WPMS.restUrl + path, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': window.WPMS.nonce }
            });
            return handleResponse(response);
        }
    };

    async function handleResponse(response) {
        const text = await response.text();
        let data;
        try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { raw: text }; }
        if (!response.ok) {
            const err = new Error(data.message || data.code || ('HTTP ' + response.status));
            err.code = data.code || 'http_error';
            err.status = response.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    window.WPMS.formatBytes = function (bytes) {
        if (!bytes && bytes !== 0) return '?';
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    };

    // Backups table loader — only runs if the table is present.
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('wpms-backups-table');
        if (!table) return;
        loadBackups(table).catch(function (err) { showBackupsError(table, err); });
    });

    async function loadBackups(table) {
        const tbody = table.querySelector('tbody');
        const data = await window.WPMS.api.getJson('backups');
        if (!data.backups.length) {
            tbody.innerHTML = '<tr><td colspan="5">' + 'No backups yet.' + '</td></tr>';
            return;
        }
        tbody.innerHTML = data.backups.map(function (b) {
            const date = new Date(b.mtime * 1000).toLocaleString();
            const validity = b.is_valid ? '✅' : '⚠';
            return (
                '<tr>' +
                '<td>' + escapeHtml(b.filename) + '</td>' +
                '<td>' + window.WPMS.formatBytes(b.size) + '</td>' +
                '<td>' + escapeHtml(date) + '</td>' +
                '<td>' + validity + '</td>' +
                '<td><button type="button" class="button button-link-delete" data-filename="' +
                encodeURIComponent(b.filename) + '">Delete</button></td>' +
                '</tr>'
            );
        }).join('');

        tbody.querySelectorAll('button[data-filename]').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const filename = decodeURIComponent(btn.dataset.filename);
                if (!confirm('Delete ' + filename + '?')) return;
                try {
                    await window.WPMS.api.deleteJson('backups/' + encodeURIComponent(filename));
                    await loadBackups(table);
                } catch (err) {
                    alert('Delete failed: ' + err.message);
                }
            });
        });
    }

    function showBackupsError(table, err) {
        table.querySelector('tbody').innerHTML =
            '<tr><td colspan="5">Error loading backups: ' + escapeHtml(err.message) + '</td></tr>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
})();
