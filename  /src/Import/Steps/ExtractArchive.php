<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

final class ExtractArchive implements ImportStep
{
    public function name(): string { return 'extract'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $started = microtime(true);

        $dest = $context->extractDir();
        if (!is_dir($dest)) mkdir($dest, 0755, true);

        $reader = new Reader($context->archivePath());

        $entries = iterator_to_array($reader->listFiles(), false);
        $total = count($entries);
        $startIdx = (int) ($cursor['index'] ?? 0);

        for ($i = $startIdx; $i < $total; $i++) {
            [$header, $offset] = $entries[$i];
            $reader->extractFile($header, $offset, $dest);

            if (microtime(true) - $started >= $maxSeconds) {
                $progress = $total > 0 ? (int) floor(($i + 1) / $total * 100) : 100;
                return StepResult::advance(
                    ['index' => $i + 1, 'total' => $total],
                    $progress,
                    sprintf('Extracting %d of %d', $i + 1, $total)
                );
            }
        }

        return StepResult::complete(100, sprintf('Extracted %d files.', $total));
    }
}
