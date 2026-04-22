<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Replace wp-content/{plugins,themes,uploads} with the freshly-extracted content.
 *
 * No snapshot kept — user accepted this import will overwrite current site.
 * If the process crashes between rmTree and rename, the site will be broken;
 * the user can re-run the import from the same archive.
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
                $this->rmTree($to);
            }
            if (!rename($from, $to)) {
                throw new \RuntimeException(sprintf('Could not move %s to %s', $from, $to));
            }
        }

        return StepResult::complete(100, 'Content restored.');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            if (is_dir($p) && !is_link($p)) {
                $this->rmTree($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
