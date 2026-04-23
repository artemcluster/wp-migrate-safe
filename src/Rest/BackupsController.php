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

        $size = filesize($path);
        if ($size === false) {
            return new WP_Error('wpms_stat_failed', 'Cannot read backup', ['status' => 500]);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return new WP_Error('wpms_read_failed', 'Cannot open backup', ['status' => 500]);
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) break;
            echo $chunk;
            @ob_flush();
            @flush();
        }
        fclose($handle);
        exit;
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
