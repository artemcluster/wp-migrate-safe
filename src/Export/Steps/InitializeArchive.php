<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

final class InitializeArchive implements ExportStep
{
    public function name(): string { return 'initialize'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        // Ensure the archive file is created, empty, so subsequent steps can append.
        $path = $context->archivePath();
        if (is_file($path)) {
            unlink($path);
        }
        file_put_contents($path, '');

        return StepResult::complete(1, 'Archive file initialized.');
    }
}
