<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

final class AppendUploads implements ExportStep
{
    public function name(): string { return 'append-uploads'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $root = $context->wpContentDir() . '/uploads';
        // Exclude our own backups directory from the export.
        return (new AppendDirectoryStep('uploads', 'wp-content/uploads', $root, ['wp-migrate-safe']))
            ->run($context, $cursor, $maxSeconds);
    }
}
