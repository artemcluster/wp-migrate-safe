<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Export\FileList\FileEnumerator;
use WpMigrateSafe\Job\StepResult;

final class AppendPlugins implements ExportStep
{
    public function name(): string { return 'append-plugins'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $root = $context->wpContentDir() . '/plugins';
        return (new AppendDirectoryStep('plugins', 'wp-content/plugins', $root))
            ->run($context, $cursor, $maxSeconds);
    }
}
