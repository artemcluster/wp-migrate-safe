<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Step 5: Finalize the import — clean up temp files, flush caches.
 *
 * Requires a live WordPress environment (cache flush calls are WordPress functions).
 */
final class FinalizeImport implements ImportStep
{
    public function name(): string
    {
        return 'finalize_import';
    }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        // Clean up extract directory.
        $this->removeDirectory($context->extractDir());

        // Flush WordPress object cache if available.
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        return StepResult::complete(100, 'Import finalized.');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
