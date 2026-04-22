<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

use WpMigrateSafe\Import\Steps\ExtractArchive;
use WpMigrateSafe\Import\Steps\FinalizeImport;
use WpMigrateSafe\Import\Steps\ImportDatabase;
use WpMigrateSafe\Import\Steps\RestoreContent;
use WpMigrateSafe\Import\Steps\TakeSnapshot;
use WpMigrateSafe\Import\Steps\Validate;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Rollback\Exception\RollbackFailedException;
use WpMigrateSafe\Rollback\RollbackExecutor;

final class ImportJob
{
    /** @return ImportStep[] */
    private static function steps(): array
    {
        return [
            new Validate(),          // 0
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
        return [5, 10, 25, 45, 10, 5]; // sums to 100
    }

    public static function runSlice(Job $job, ImportContext $context, int $budgetSeconds): Job
    {
        $steps = self::steps();
        $weights = self::stepWeights();

        if ($job->stepIndex() >= count($steps)) {
            return $job->withStatus(JobStatus::COMPLETED);
        }

        $step = $steps[$job->stepIndex()];
        $snapshotId = (string) ($job->meta()['snapshot_id'] ?? '');

        // Pass snapshot_id as part of cursor to FinalizeImport.
        $cursor = $job->cursor();
        if ($snapshotId !== '' && !isset($cursor['snapshot_id'])) {
            $cursor['snapshot_id'] = $snapshotId;
        }

        try {
            $result = $step->run($context, $cursor, $budgetSeconds);
        } catch (\Throwable $e) {
            // Something went wrong. If we have a snapshot, run rollback.
            if ($snapshotId !== '') {
                try {
                    $snapshot = $context->snapshotStore()->load($snapshotId);
                    (new RollbackExecutor($context->wpContentDir()))->execute($snapshot);

                    return $job->withError([
                        'code'    => 'IMPORT_FAILED_ROLLED_BACK',
                        'message' => $e->getMessage(),
                        'hint'    => 'Site restored to its previous state via snapshot.',
                        'step'    => $step->name(),
                        'context' => ['snapshot_id' => $snapshotId, 'rolled_back' => true],
                    ]);
                } catch (RollbackFailedException $rbEx) {
                    return $job->withError([
                        'code'    => 'ROLLBACK_FAILED',
                        'message' => $rbEx->getMessage(),
                        'hint'    => 'Manual recovery required. See manual_steps.',
                        'step'    => $step->name(),
                        'context' => [
                            'original_error' => $e->getMessage(),
                            'snapshot_id' => $snapshotId,
                            'manual_steps' => $rbEx->manualSteps(),
                        ],
                    ]);
                }
            }

            // No snapshot yet (failure during Validate step) — plain error.
            return $job->withError([
                'code'    => 'IMPORT_FAILED',
                'message' => $e->getMessage(),
                'hint'    => 'Failure occurred before snapshot was taken.',
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
