<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

/**
 * Verify at least 2× archive size is available on disk.
 *
 * Rationale: snapshot takes ~1× (plus SQL dump), extraction takes ~1×,
 * so during import we need roughly 2-3× free space.
 */
final class DiskSpaceCheck
{
    public function run(string $archivePath, int $freeBytes): DryRunReport
    {
        if (!is_file($archivePath)) {
            return new DryRunReport(
                [['code' => 'ARCHIVE_MISSING', 'message' => 'Archive file not found.', 'hint' => '']],
                []
            );
        }

        $archiveSize = filesize($archivePath);
        if ($archiveSize === false) {
            return new DryRunReport(
                [['code' => 'ARCHIVE_UNREADABLE', 'message' => 'Cannot stat archive.', 'hint' => '']],
                []
            );
        }

        $required = $archiveSize * 2;
        if ($freeBytes < $required) {
            return new DryRunReport(
                [[
                    'code' => 'DISK_FULL',
                    'message' => sprintf(
                        'Not enough free disk space: %s required, %s available.',
                        self::formatBytes($required),
                        self::formatBytes($freeBytes)
                    ),
                    'hint' => 'Delete old backups or increase hosting quota.',
                ]],
                []
            );
        }

        // Warn if free space is close to the bound (<3× archive).
        if ($freeBytes < $archiveSize * 3) {
            return new DryRunReport([], [[
                'code' => 'DISK_SPACE_TIGHT',
                'message' => sprintf(
                    'Disk space is tight: %s available. Recommended: %s.',
                    self::formatBytes($freeBytes),
                    self::formatBytes($archiveSize * 3)
                ),
                'hint' => 'Import may succeed but leaves little margin.',
            ]]);
        }

        return DryRunReport::ok();
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $i = min($i, count($units) - 1);
        return sprintf('%.2f %s', $bytes / (1024 ** $i), $units[$i]);
    }
}
