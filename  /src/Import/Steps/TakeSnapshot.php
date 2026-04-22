<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Import\Snapshot\SnapshotCreator;
use WpMigrateSafe\Job\StepResult;

final class TakeSnapshot implements ImportStep
{
    public function name(): string { return 'take-snapshot'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $creator = new SnapshotCreator($context->snapshotStore(), $context->wpContentDir());
        $snapshot = $creator->create();

        return StepResult::complete(15, 'Snapshot taken.', [
            'snapshot_id' => $snapshot->id(),
        ]);
    }
}
