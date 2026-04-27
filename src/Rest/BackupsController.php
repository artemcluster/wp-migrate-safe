<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Plugin\Paths;

final class BackupsController
{
    public function index(WP_REST_Request $request)
    {
        $dir = Paths::backupsDir();
        $entries = [];

        foreach ((array) glob($dir . '/*.wpress') as $path) {
            if (!is_file($path)) continue;
            $entries[] = [
                'filename' => basename($path),
                'size' => filesize($path),
                'mtime' => filemtime($path),
                'is_valid' => $this->quickValidate($path),
            ];
        }

        usort($entries, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        return new WP_REST_Response([
            'backups' => $entries,
            'directory' => $dir,
            'free_bytes' => Paths::freeDiskBytes(),
        ], 200);
    }

    public function delete(WP_REST_Request $request)
    {
        $filename = basename((string) $request['filename']);
        $path = Paths::backupsDir() . '/' . $filename;

        // Refuse paths that escape the backups dir.
        if (!preg_match('/\.wpress$/i', $filename) || !is_file($path)) {
            return new WP_Error('wpms_not_found', 'Backup not found', ['status' => 404]);
        }

        if (!@unlink($path)) {
            return new WP_Error('wpms_delete_failed', 'Could not delete backup', ['status' => 500]);
        }

        return new WP_REST_Response(['deleted' => $filename], 200);
    }

    /**
     * Stream a backup file to the browser. Bypasses WP REST JSON wrapper.
     *
     * Only .wpress files inside the backups dir are allowed. Filename is basename-sanitized
     * to block path traversal.
     *
     * For large files behind a reverse proxy (Cloudflare, nginx in front of Apache),
     * the function tries — in order:
     *
     * 1. Apache mod_xsendfile — Apache reads the file directly, bypassing PHP entirely.
     * 2. Nginx X-Accel-Redirect — only if WPMS_NGINX_INTERNAL_LOCATION constant is set
     *    in wp-config.php pointing at an internal nginx location that maps to backupsDir.
     * 3. PHP streaming with HTTP Range support — chunks of 1 MB, supports resume,
     *    `set_time_limit(0)` + `ignore_user_abort(true)` to survive long transfers.
     */
    public function download(WP_REST_Request $request)
    {
        // Filename comes via query string, not a path segment. Putting ".wpress" inside
        // a REST route's path segment triggers server-side routing quirks on some hosts
        // (nginx configs that block/misroute extensions, mod_security rules, etc.).
        $rawFilename = (string) $request->get_param('filename');
        $filename = basename($rawFilename);
        $path = Paths::backupsDir() . '/' . $filename;

        if ($filename === '' || !preg_match('/\.wpress$/i', $filename) || !is_file($path)) {
            return new WP_Error('wpms_not_found', 'Backup not found', ['status' => 404]);
        }

        // Stale stat cache after writes can give a wrong size, breaking
        // Content-Length and confusing CDNs.
        clearstatcache(true, $path);

        $size = filesize($path);
        if ($size === false) {
            return new WP_Error('wpms_stat_failed', 'Cannot read backup', ['status' => 500]);
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Common headers, sent in every code path below.
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');

        // 1. Apache mod_xsendfile — fastest, totally bypasses PHP for the body.
        if ($this->trySendfileApache($path, $size)) {
            exit;
        }

        // 2. Nginx X-Accel-Redirect — only if the user wired up an internal location.
        if ($this->trySendfileNginx($path, $size, $filename)) {
            exit;
        }

        // 3. PHP streaming with optional Range support.
        $this->streamFile($path, $size);
        exit;
    }

    /**
     * @return bool true if X-Sendfile header was emitted; caller must exit.
     */
    private function trySendfileApache(string $path, int $size): bool
    {
        if (!function_exists('apache_get_modules')) return false;
        $modules = apache_get_modules();
        if (!is_array($modules) || !in_array('mod_xsendfile', $modules, true)) return false;

        header('Content-Length: ' . $size);
        header('X-Sendfile: ' . $path);
        return true;
    }

    /**
     * @return bool true if X-Accel-Redirect header was emitted; caller must exit.
     */
    private function trySendfileNginx(string $path, int $size, string $filename): bool
    {
        if (!defined('WPMS_NGINX_INTERNAL_LOCATION')) return false;
        $internal = (string) WPMS_NGINX_INTERNAL_LOCATION;
        if ($internal === '') return false;
        $internal = '/' . ltrim($internal, '/');

        header('Content-Length: ' . $size);
        header('X-Accel-Redirect: ' . rtrim($internal, '/') . '/' . rawurlencode($filename));
        header('X-Accel-Buffering: no');
        return true;
    }

    private function streamFile(string $path, int $size): void
    {
        $start = 0;
        $end = $size - 1;

        // Honour HTTP Range request — Chrome / wget / curl / CDNs all use this for
        // large downloads to support resume and parallel chunks.
        $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '';
        if ($rangeHeader !== '' && preg_match('/^bytes=(\d+)-(\d*)$/', trim($rangeHeader), $m)) {
            $start = (int) $m[1];
            $end = ($m[2] !== '') ? (int) $m[2] : ($size - 1);
            if ($start > $end || $start >= $size || $end >= $size) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                return;
            }
            header('HTTP/1.1 206 Partial Content');
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        header('Content-Length: ' . ($end - $start + 1));

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if ($start > 0 && fseek($handle, $start) !== 0) {
                return;
            }
            $remaining = $end - $start + 1;
            $bufSize = 1024 * 1024; // 1 MB
            while ($remaining > 0 && !feof($handle)) {
                if (connection_aborted()) break;
                $read = (int) min($bufSize, $remaining);
                $chunk = fread($handle, $read);
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                @ob_flush();
                @flush();
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    private function quickValidate(string $path): bool
    {
        try {
            return (new Reader($path))->isValid();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
