<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Step 4: Copy extracted content files (plugins, themes, uploads) over the live wp-content.
 *
 * Files are moved from the extract dir into the appropriate wp-content subdirectories.
 */
final class RestoreContent implements ImportStep
{
    /** Subdirectories of wp-content that we restore. */
    private const CONTENT_DIRS = ['plugins', 'themes', 'uploads', 'mu-plugins'];

    public function name(): string
    {
        return 'restore_content';
    }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $extractDir    = $context->extractDir();
        $wpContentDir  = $context->wpContentDir();

        $movedCount = 0;

        foreach (self::CONTENT_DIRS as $subdir) {
            $src = $extractDir . '/' . $subdir;
            if (!is_dir($src)) {
                continue;
            }

            $dst = $wpContentDir . '/' . $subdir;
            $movedCount += $this->copyDirectory($src, $dst);
        }

        return StepResult::complete(
            100,
            sprintf('Restored %d content files.', $movedCount),
            ['content_files_restored' => $movedCount]
        );
    }

    private function copyDirectory(string $src, string $dst): int
    {
        if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new \RuntimeException('Could not create directory: ' . $dst);
        }

        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . '/' . $it->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new \RuntimeException('Could not create directory: ' . $target);
                }
                continue;
            }

            if (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException('Could not copy file: ' . $item->getPathname());
            }
            $count++;
        }

        return $count;
    }
}
