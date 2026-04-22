<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

final class AppendDatabaseFile implements ExportStep
{
    public function name(): string { return 'append-database'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $dumpPath = $context->archivePath() . '.dump.sql';
        if (!is_file($dumpPath)) {
            throw new \RuntimeException('Database dump missing; DumpDatabase step must run first.');
        }

        $context->archiveWriter()->appendFile($dumpPath, 'database.sql', 'database');

        // Dump file no longer needed.
        @unlink($dumpPath);

        return StepResult::complete(15, 'Database included in archive.');
    }
}
