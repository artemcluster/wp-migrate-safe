<?php
declare(strict_types=1);

namespace WpMigrateSafe\Plugin;

/**
 * Filesystem path resolution.
 *
 * All paths are derived from wp-content constants so the plugin works correctly
 * on sites with non-default wp-content locations.
 */
final class Paths
{
    /**
     * Absolute path to the backups directory (creates it on first access).
     */
    public static function backupsDir(): string
    {
        $dir = trailingslashit(WP_CONTENT_DIR) . WPMS_BACKUPS_SUBDIR;
        self::ensureDir($dir);
        return $dir;
    }

    /**
     * Absolute path to the directory used for in-progress chunked uploads.
     */
    public static function uploadsTmpDir(): string
    {
        $dir = self::backupsDir() . '/_uploads';
        self::ensureDir($dir);
        return $dir;
    }

    /**
     * Absolute path to a single upload session's working directory.
     */
    public static function uploadSessionDir(string $uploadId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new \InvalidArgumentException('Invalid upload_id format.');
        }
        $dir = self::uploadsTmpDir() . '/' . $uploadId;
        self::ensureDir($dir);
        return $dir;
    }

    /**
     * Directory for job progress/heartbeat state (used by Plans 4 & 5).
     */
    public static function jobsDir(): string
    {
        $dir = self::backupsDir() . '/_jobs';
        self::ensureDir($dir);
        return $dir;
    }

    /**
     * Directory for rollback snapshots (used by Plan 5).
     */
    public static function rollbackDir(): string
    {
        $dir = self::backupsDir() . '/_rollback';
        self::ensureDir($dir);
        return $dir;
    }

    /**
     * Return available free bytes in the backups dir's filesystem, or null if not determinable.
     */
    public static function freeDiskBytes(): ?int
    {
        $free = @disk_free_space(self::backupsDir());
        return $free === false ? null : (int) $free;
    }

    private static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!wp_mkdir_p($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $dir));
        }
        // Drop a .htaccess that denies direct web access (Apache) and an empty index.php (all servers).
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden.\n");
        }
    }
}
