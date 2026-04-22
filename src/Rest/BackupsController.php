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

    private function quickValidate(string $path): bool
    {
        try {
            return (new Reader($path))->isValid();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
