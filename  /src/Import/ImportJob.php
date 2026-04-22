<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

use WpMigrateSafe\Import\Steps\ExtractArchive;
use WpMigrateSafe\Import\Steps\FinalizeImport;
use WpMigrateSafe\Import\Steps\ImportDatabase;
use WpMigrateSafe\Import\Steps\RestoreContent;
use WpMigrateSafe\Import\Steps\Validate;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;

final class ImportJob
{
    /** @return ImportStep[] */
    private static function steps(): array
    {
        return [
            new Validate(),          // 0
            new ExtractArchive(),    // 1
            new ImportDatabase(),    // 2
            new RestoreContent(),    // 3
            new FinalizeImport(),    // 4
        ];
    }

    /** @return int[] */
    private static function stepWeights(): array
    {
        return [5, 30, 50, 10, 5]; // sums to 100
    }

    public static function runSlice(Job $job, ImportContext $context, int $budgetSeconds): Job
    {
        $steps = self::steps();
        $weights = self::stepWeights();

        if ($job->stepIndex() >= count($steps)) {
            return $job->withStatus(JobStatus::COMPLETED);
        }

        $step = $steps[$job->stepIndex()];

        try {
            $result = $step->run($context, $job->cursor(), $budgetSeconds);
        } catch (\Throwable $e) {
            return $job->withError([
                'code'    => 'IMPORT_FAILED',
                'message' => $e->getMessage(),
                'hint'    => 'Retry import or use a different backup.',
                'step'    => $step->name(),
                'context' => [],
            ]);
        }

        $globalProgress = self::computeGlobalProgress($job->stepIndex(), $result->progress(), $weights);
        $mergedMeta = array_merge($job->meta(), $result->meta());

        if ($result->done()) {
            $nextIndex = $job->stepIndex() + 1;
            if ($nextIndex >= count($steps)) {
                return $job
                    ->withStepIndex($nextIndex, [])
                    ->withCursor([], 100)
                    ->withMeta($mergedMeta)
                    ->withStatus(JobStatus::COMPLETED);
            }
            return $job
                ->withStepIndex($nextIndex, [])
                ->withCursor([], $globalProgress)
                ->withMeta($mergedMeta);
        }

        return $job
            ->withCursor($result->cursor(), $globalProgress)
            ->withMeta($mergedMeta);
    }

    /**
     * @param int[] $weights
     */
    private static function computeGlobalProgress(int $stepIndex, int $localProgress, array $weights): int
    {
        $before = 0;
        for ($i = 0; $i < $stepIndex; $i++) $before += $weights[$i];
        $current = (int) floor($weights[$stepIndex] * ($localProgress / 100));
        return min(100, $before + $current);
    }
}
