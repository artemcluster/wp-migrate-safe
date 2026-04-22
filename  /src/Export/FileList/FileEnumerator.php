<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\FileList;

use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Yield relative file paths under a root directory, optionally excluding
 * directory names and skipping symlinks.
 */
final class FileEnumerator
{
    private string $root;
    /** @var string[] */
    private array $excludeDirs;

    /**
     * @param string[] $excludeDirs Exact directory names to skip (e.g. ['cache', 'node_modules']).
     */
    public function __construct(string $root, array $excludeDirs = [])
    {
        $this->root = rtrim($root, '/\\');
        $this->excludeDirs = $excludeDirs;
    }

    /**
     * @return Generator<int, string>
     */
    public function iterate(): Generator
    {
        if (!is_dir($this->root)) return;

        $flags = RecursiveDirectoryIterator::SKIP_DOTS;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, $flags),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink()) continue;
            if (!$file->isFile()) continue;

            $relative = ltrim(substr($file->getPathname(), strlen($this->root)), '/\\');
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($this->isExcluded($relative)) continue;

            yield $relative;
        }
    }

    private function isExcluded(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, $this->excludeDirs, true)) {
                return true;
            }
        }
        return false;
    }
}
