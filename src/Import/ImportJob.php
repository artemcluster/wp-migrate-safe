<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;
use WpMigrateSafe\Import\Steps\ExtractArchive;
use WpMigrateSafe\Import\Steps\FinalizeImport;
use WpMigrateSafe\Import\Steps\ImportDatabase;
use WpMigrateSafe\Import\Steps\RestoreContent;
use WpMigrateSafe\Import\Steps\TakeSnapshot;
use WpMigrateSafe\Import\Steps\ValidateArchive;
use WpMigrateSafe\Job\Exception\StepFailedException;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\StepResult;
use WpMigrateSafe\Rollback\RollbackExecutor;
use WpMigrateSafe\Rollback\RollbackFailedException;

/**
 * Runs one slice of an import job, with automatic rollback on failure.
 *
 * Step list (index maps to implementation):
 *   0 — ValidateArchive
 *   1 — TakeSnapshot
 *   2 — ExtractArchive
 *   3 — ImportDatabase
 *   4 — RestoreContent
 *   5 — FinalizeImport
 *
 * Steps 0–1 run before the point of no return. If a failure occurs in
 * steps 2–4, rollback is triggered automatically using the snapshot.
 * Step 5 (finalize) cleans up temp files and flushes caches; a failure
 * there does NOT trigger rollback (content is already live).
 */
final class ImportJob
{
    /**
     * Steps where automatic rollback is triggered on failure.
     * Step 0 (validate) and step 1 (snapshot) fail gracefully.
     * Step 5 (finalize) is post-commit — no rollback.
     */
    private const ROLLBACK_FROM_STEP = 2;
    private const ROLLBACK_UNTIL_STEP = 4;

    /**
     * @return ImportStep[]
     */
    private static function steps(): array
    {
        return [
            new ValidateArchive(),   // 0
            new TakeSnapshot(),      // 1
            new ExtractArchive(),    // 2
            new ImportDatabase(),    // 3
            new RestoreContent(),    // 4
            new FinalizeImport(),    // 5
        ];
    }

    /** @return int[] */
    private static function stepWeights(): array
    {
        return [2, 5, 20, 50, 20, 3]; // sums to 100
    }

    /**
     * Run up to $budgetSeconds seconds of work for this job.
     */
    public static function runSlice(Job $job, ImportContext $context, int $budgetSeconds): Job
    {
        $steps   = self::steps();
        $weights = self::stepWeights();

        if ($job->stepIndex() >= count($steps)) {
            return $job->withStatus(JobStatus::COMPLETED);
        }

        $step = $steps[$job->stepIndex()];

        try {
            $result = $step->run($context, $job->cursor(), $budgetSeconds);
        } catch (\Throwable $e) {
            // Attempt rollback if we are past the point of no return.
            if ($job->stepIndex() >= self::ROLLBACK_FROM_STEP
                && $job->stepIndex() <= self::ROLLBACK_UNTIL_STEP
            ) {
                $rollbackError = self::attemptRollback($job, $context);
            } else {
                $rollbackError = null;
            }

            $errorContext = $e instanceof StepFailedException ? $e->context() : [];
            if ($rollbackError !== null) {
                $errorContext['rollback_error'] = $rollbackError;
            }

            return $job->withError([
                'code'    => $rollbackError !== null ? 'STEP_FAILED_ROLLBACK_FAILED' : 'STEP_FAILED',
                'message' => $e->getMessage(),
                'hint'    => 'See technical log for stack trace.',
                'step'    => $step->name(),
                'context' => $errorContext,
            ]);
        }

        $globalProgress = self::computeGlobalProgress($job->stepIndex(), $result->progress(), $weights);
        $mergedMeta     = array_merge($job->meta(), $result->meta());

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
     * Attempt to roll back to the snapshot recorded in job meta.
     *
     * @return string|null Error message if rollback itself fails, null on success.
     */
    private static function attemptRollback(Job $job, ImportContext $context): ?string
    {
        $snapshotId = (string) ($job->meta()['snapshot_id'] ?? '');
        if ($snapshotId === '') {
            return 'No snapshot_id found in job meta; cannot rollback.';
        }

        try {
            $store    = new SnapshotStore($context->rollbackDir());
            $snapshot = $store->load($snapshotId);

            $executor = new RollbackExecutor($context->wpContentDir());
            $executor->rollback($snapshot);

            return null; // rollback succeeded
        } catch (RollbackFailedException $e) {
            return $e->getMessage() . ' | Steps: ' . implode(', ', $e->failedSteps());
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
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
