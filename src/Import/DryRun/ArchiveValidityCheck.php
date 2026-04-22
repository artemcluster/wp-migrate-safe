<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

use WpMigrateSafe\Archive\Reader;

/**
 * Validates that the uploaded archive is a readable, non-corrupted .wpress file.
 */
final class ArchiveValidityCheck
{
    private string $archivePath;

    public function __construct(string $archivePath)
    {
        $this->archivePath = $archivePath;
    }

    public function run(DryRunReport $report): DryRunReport
    {
        $name = 'archive_validity';

        if (!file_exists($this->archivePath)) {
            return $report->withCheck($name, false, 'Archive file not found: ' . $this->archivePath);
        }

        try {
            $reader = new Reader($this->archivePath);
            $valid  = $reader->isValid();
            $message = $valid
                ? 'Archive is valid and readable.'
                : 'Archive appears corrupted or truncated.';
            return $report->withCheck($name, $valid, $message);
        } catch (\Throwable $e) {
            return $report->withCheck($name, false, 'Archive validation error: ' . $e->getMessage());
        }
    }
}
