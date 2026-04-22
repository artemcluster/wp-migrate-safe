<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Import\DryRun\ArchiveValidityCheck;
use WpMigrateSafe\Import\DryRun\DiskSpaceCheck;
use WpMigrateSafe\Import\DryRun\DryRunReport;
use WpMigrateSafe\Job\Exception\StepFailedException;
use WpMigrateSafe\Job\StepResult;
use WpMigrateSafe\Plugin\Paths;

/**
 * Step 0: Validate the archive and run pre-import checks (disk space, archive validity).
 */
final class ValidateArchive implements ImportStep
{
    public function name(): string
    {
        return 'validate_archive';
    }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $archiveSize = @filesize($context->archivePath()) ?: 0;
        $freeBytes   = Paths::freeDiskBytes();

        $report = (new DryRunReport())
            ->withCheck('archive_validity', true); // structural check done in controller

        $report = (new DiskSpaceCheck($archiveSize, $freeBytes))->run($report);
        $report = (new ArchiveValidityCheck($context->archivePath()))->run($report);

        if (!$report->ok()) {
            $failures = array_filter($report->checks(), fn($c) => !$c['passed']);
            $msg = implode('; ', array_column($failures, 'message'));
            throw new StepFailedException('Pre-import validation failed: ' . $msg, ['checks' => $report->checks()]);
        }

        return StepResult::complete(100, 'Archive validated.', ['dry_run' => $report->toArray()]);
    }
}
