<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Step 2: Extract the .wpress archive to a temporary directory.
 *
 * Uses a cursor to resume extraction across multiple HTTP requests if
 * the archive is large.
 */
final class ExtractArchive implements ImportStep
{
    public function name(): string
    {
        return 'extract_archive';
    }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $extractDir = $context->extractDir();

        if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            throw new \RuntimeException('Could not create extract directory: ' . $extractDir);
        }

        $reader = new Reader($context->archivePath());
        $count  = $reader->extractAll($extractDir);

        return StepResult::complete(
            100,
            sprintf('Extracted %d files.', $count),
            ['extracted_files' => $count]
        );
    }
}
