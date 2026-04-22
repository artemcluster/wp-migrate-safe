<?php
declare(strict_types=1);

namespace WpMigrateSafe\Cron;

use WpMigrateSafe\Job\JobStore;
use WpMigrateSafe\Plugin\Paths;
use WpMigrateSafe\Upload\UploadStore;

/**
 * Hourly housekeeping: purge orphaned tmp files, stale upload sessions,
 * and old jobs.
 *
 * Runs via WP-Cron hook 'wpms_cleanup_tick'.
 */
final class Cleanup
{
    public const HOOK = 'wpms_cleanup_tick';

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts !== false) {
            wp_unschedule_event($ts, self::HOOK);
        }
    }

    public static function registerHook(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function run(): void
    {
        $uploadStore = new UploadStore(Paths::uploadsTmpDir(), Paths::backupsDir());
        $uploadStore->purgeStale(24 * HOUR_IN_SECONDS);

        $jobs = new JobStore(Paths::jobsDir());
        $jobs->purgeOld(7 * DAY_IN_SECONDS);

        // Remove orphan _extract_* directories older than 24h.
        self::purgeOldExtractDirs(24 * HOUR_IN_SECONDS);
    }

    private static function purgeOldExtractDirs(int $maxAgeSeconds): int
    {
        $base = Paths::backupsDir();
        $cutoff = time() - $maxAgeSeconds;
        $removed = 0;
        foreach ((array) glob($base . '/_extract_*', GLOB_ONLYDIR) as $dir) {
            $mtime = filemtime($dir);
            if ($mtime !== false && $mtime < $cutoff) {
                self::rmTree($dir);
                $removed++;
            }
        }
        return $removed;
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? self::rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
