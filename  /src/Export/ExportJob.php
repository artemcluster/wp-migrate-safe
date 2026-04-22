<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export;

use WpMigrateSafe\Export\Steps\AppendDatabaseFile;
use WpMigrateSafe\Export\Steps\AppendMetadata;
use WpMigrateSafe\Export\Steps\AppendPlugins;
use WpMigrateSafe\Export\Steps\AppendThemes;
use WpMigrateSafe\Export\Steps\AppendUploads;
use WpMigrateSafe\Export\Steps\DumpDatabase;
use WpMigrateSafe\Export\Steps\FinalizeArchive;
use WpMigrateSafe\Export\Steps\InitializeArchive;
use WpMigrateSafe\Job\Exception\StepFailedException;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\StepResult;

/**
 * Runs one slice of an export job and returns the updated Job state.
 *
 * The step list is fixed (index maps to implementation).
 * Total progress is weighted: initialize=1%, database=15%, plugins+themes=30%, uploads=50%, finalize=4%.
 */
final class ExportJob
{
    /**
     * @return ExportStep[]
     */
    private static function steps(): array
    {
        return [
            new InitializeArchive(),
            new AppendMetadata(),
            new DumpDatabase(),
            new AppendDatabaseFile(),
            new AppendPlugins(),
            new AppendThemes(),
            new AppendUploads(),
            new FinalizeArchive(),
        ];
    }

    /** @return int[] */
    private static function stepWeights(): array
    {
        return [1, 1, 10, 5, 14, 15, 50, 4]; // sums to 100
    }

    /**
     * Run up to $budgetSeconds seconds of work for this job. Returns the mutated Job.
     */
    public static function runSlice(Job $job, ExportContext $context, int $budgetSeconds): Job
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
                'code'    => 'STEP_FAILED',
                'message' => $e->getMessage(),
                'hint'    => 'See technical log for stack trace.',
                'step'    => $step->name(),
                'context' => $e instanceof StepFailedException ? $e->context() : [],
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
        for ($i = 0; $i < $stepIndex; $i++) {
            $before += $weights[$i];
        }
        $current = (int) floor($weights[$stepIndex] * ($localProgress / 100));
        return min(100, $before + $current);
    }
}
