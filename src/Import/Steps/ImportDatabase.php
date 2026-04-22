<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Import\Sql\SqlDumpReader;
use WpMigrateSafe\Import\Sql\SqlExecutor;
use WpMigrateSafe\Import\Sql\SqlRewriter;
use WpMigrateSafe\Job\StepResult;

/**
 * Step 3: Import the database dump extracted from the archive.
 *
 * Expects a file named `dump.sql` inside the `database/` directory
 * of the extracted archive (ai1wm .wpress convention).
 *
 * Requires a live WordPress + MySQL environment.
 */
final class ImportDatabase implements ImportStep
{
    public function name(): string
    {
        return 'import_database';
    }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $dumpPath = $context->extractDir() . '/database/dump.sql';

        if (!is_file($dumpPath)) {
            throw new \RuntimeException('SQL dump not found in extracted archive: ' . $dumpPath);
        }

        $reader   = new SqlDumpReader($dumpPath);
        $rewriter = new SqlRewriter(
            $context->sourcePrefix(),
            $context->targetPrefix(),
            $context->sourceUrl(),
            $context->targetUrl()
        );
        $executor = new SqlExecutor();

        $count = $executor->executeAll($reader, $rewriter);

        return StepResult::complete(
            100,
            sprintf('Imported %d SQL statements.', $count),
            ['sql_statements' => $count]
        );
    }
}
