<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

final class AppendThemes implements ExportStep
{
    public function name(): string { return 'append-themes'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $root = $context->wpContentDir() . '/themes';
        return (new AppendDirectoryStep('themes', 'wp-content/themes', $root))
            ->run($context, $cursor, $maxSeconds);
    }
}
