<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Export\FileList\FileEnumerator;
use WpMigrateSafe\Job\StepResult;

/**
 * Generic "append every file under directory X, resumable across requests" step.
 *
 * Cursor shape:
 *   ['manifest_path' => '/tmp/..._manifest.txt', 'line' => 42]
 *
 * On first run we enumerate the directory into a manifest (a flat text file, one
 * relative path per line), then we iterate the manifest line by line across
 * subsequent invocations.
 */
final class AppendDirectoryStep implements ExportStep
{
    private string $label;
    private string $archivePrefix;
    private string $sourceRoot;
    /** @var string[] */
    private array $excludeDirs;

    /**
     * @param string[] $excludeDirs
     */
    public function __construct(string $label, string $archivePrefix, string $sourceRoot, array $excludeDirs = [])
    {
        $this->label = $label;
        $this->archivePrefix = $archivePrefix;
        $this->sourceRoot = $sourceRoot;
        $this->excludeDirs = $excludeDirs;
    }

    public function name(): string { return 'append-' . $this->label; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $startedAt = microtime(true);

        $manifestPath = $cursor['manifest_path'] ?? null;
        if ($manifestPath === null || !is_file($manifestPath)) {
            $manifestPath = $this->buildManifest($context);
        }
        $line = (int) ($cursor['line'] ?? 0);
        $totalLines = (int) ($cursor['total_lines'] ?? $this->countLines($manifestPath));

        if ($totalLines === 0) {
            @unlink($manifestPath);
            return StepResult::complete(100, $this->label . ': nothing to archive.');
        }

        $writer = $context->archiveWriter();
        $handle = fopen($manifestPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open manifest: ' . $manifestPath);
        }

        try {
            // Seek to line $line by skipping.
            $skipped = 0;
            while ($skipped < $line && ($dummy = fgets($handle)) !== false) {
                $skipped++;
            }

            while (($rel = fgets($handle)) !== false) {
                $rel = rtrim($rel, "\r\n");
                if ($rel === '') { $line++; continue; }

                $absolute = $this->sourceRoot . '/' . $rel;
                if (is_file($absolute)) {
                    $archiveName = basename($rel);
                    $archivePrefix = $this->archivePrefix . '/' . trim(dirname($rel), '.');
                    $archivePrefix = rtrim($archivePrefix, '/');
                    $writer->appendFile($absolute, $archiveName, $archivePrefix);
                }

                $line++;

                if (microtime(true) - $startedAt >= $maxSeconds) {
                    $progress = (int) floor(($line / $totalLines) * 100);
                    return StepResult::advance([
                        'manifest_path' => $manifestPath,
                        'line' => $line,
                        'total_lines' => $totalLines,
                    ], $progress, sprintf('%s: %d of %d files', $this->label, $line, $totalLines));
                }
            }
        } finally {
            fclose($handle);
        }

        @unlink($manifestPath);
        return StepResult::complete(100, sprintf(
            '%s: archived %d files.',
            $this->label,
            $totalLines
        ));
    }

    private function buildManifest(ExportContext $context): string
    {
        $manifestPath = $context->archivePath() . '.' . $this->label . '.manifest.txt';
        $handle = fopen($manifestPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not create manifest: ' . $manifestPath);
        }
        try {
            $enumerator = new FileEnumerator($this->sourceRoot, $this->excludeDirs);
            foreach ($enumerator->iterate() as $rel) {
                fwrite($handle, $rel . "\n");
            }
        } finally {
            fclose($handle);
        }
        return $manifestPath;
    }

    private function countLines(string $path): int
    {
        $count = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) return 0;
        try {
            while (fgets($handle) !== false) $count++;
        } finally {
            fclose($handle);
        }
        return $count;
    }
}
