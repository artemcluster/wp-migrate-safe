<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

final class FinalizeArchive implements ExportStep
{
    public function name(): string { return 'finalize'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $context->archiveWriter()->finalize();

        // Sanity check: Reader should validate.
        $reader = new Reader($context->archivePath());
        if (!$reader->isValid()) {
            throw new \RuntimeException('Archive failed validation after finalize.');
        }

        return StepResult::complete(100, 'Archive finalized and validated.', [
            'archive_path' => $context->archivePath(),
            'archive_size' => filesize($context->archivePath()),
        ]);
    }
}
