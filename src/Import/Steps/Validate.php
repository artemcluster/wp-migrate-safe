<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\DryRun\ArchiveValidityCheck;
use WpMigrateSafe\Import\DryRun\DiskSpaceCheck;
use WpMigrateSafe\Import\DryRun\DryRunReport;
use WpMigrateSafe\Import\DryRun\MySqlVersionCheck;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

final class Validate implements ImportStep
{
    public function name(): string { return 'validate'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        global $wpdb;

        $freeBytes = (int) (@disk_free_space($context->wpContentDir()) ?: 0);
        $serverVersion = (string) $wpdb->db_version();

        $report = DryRunReport::ok()
            ->merge((new DiskSpaceCheck())->run($context->archivePath(), $freeBytes))
            ->merge((new MySqlVersionCheck())->run($serverVersion))
            ->merge((new ArchiveValidityCheck())->run($context->archivePath()));

        if ($report->hasErrors()) {
            throw new \RuntimeException(
                'Dry-run failed: ' . json_encode($report->errors()),
            );
        }

        return StepResult::complete(5, 'Validation complete.', [
            'dry_run_report' => $report->toArray(),
        ]);
    }
}
