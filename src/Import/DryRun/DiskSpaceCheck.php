<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

/**
 * Verifies that there is sufficient free disk space to complete the import.
 *
 * Rule: free bytes must be >= archiveBytes * 3 (room for extraction + DB import).
 */
final class DiskSpaceCheck
{
    private const MULTIPLIER = 3;

    private int $archiveBytes;
    private ?int $freeBytes;

    public function __construct(int $archiveBytes, ?int $freeBytes)
    {
        $this->archiveBytes = $archiveBytes;
        $this->freeBytes    = $freeBytes;
    }

    public function run(DryRunReport $report): DryRunReport
    {
        $name = 'disk_space';

        if ($this->freeBytes === null) {
            return $report->withCheck($name, true, 'Free disk space could not be determined; skipping check.');
        }

        $required = $this->archiveBytes * self::MULTIPLIER;
        $passed   = $this->freeBytes >= $required;
        $message  = $passed
            ? sprintf('OK — %d MB free, %d MB required.', intdiv($this->freeBytes, 1024 ** 2), intdiv($required, 1024 ** 2))
            : sprintf('Insufficient disk space: %d MB free, %d MB required.', intdiv($this->freeBytes, 1024 ** 2), intdiv($required, 1024 ** 2));

        return $report->withCheck($name, $passed, $message);
    }
}
