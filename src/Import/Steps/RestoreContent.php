<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Move extracted `wp-content/{plugins,themes,uploads}` trees into their real locations.
 *
 * The snapshot step already renamed the originals aside to `*.rollback.{id}`, so
 * the target paths are free and we can rename our extracted dirs into place.
 */
final class RestoreContent implements ImportStep
{
    public function name(): string { return 'restore-content'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $extracted = $context->extractDir();
        $wpContent = $context->wpContentDir();

        foreach (['plugins', 'themes', 'uploads'] as $sub) {
            $from = $extracted . '/wp-content/' . $sub;
            $to = $wpContent . '/' . $sub;
            if (!is_dir($from)) continue;

            if (is_dir($to)) {
                // Defensive: move out of the way if it reappeared somehow.
                $aside = $to . '.obsolete.' . time();
                rename($to, $aside);
            }
            if (!rename($from, $to)) {
                throw new \RuntimeException(sprintf('Could not rename %s to %s', $from, $to));
            }
        }

        return StepResult::complete(100, 'Content restored.');
    }
}
