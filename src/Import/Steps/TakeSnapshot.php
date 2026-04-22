<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Import\Snapshot\SnapshotCreator;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;
use WpMigrateSafe\Job\StepResult;

/**
 * Step 1: Take a database + file-path snapshot for rollback.
 *
 * Requires a live WordPress + MySQL environment.
 */
final class TakeSnapshot implements ImportStep
{
    public function name(): string
    {
        return 'take_snapshot';
    }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        global $wpdb;

        $creator  = new SnapshotCreator(
            $context->rollbackDir(),
            $context->wpContentDir(),
            $wpdb->prefix
        );

        $snapshot = $creator->create();

        $store = new SnapshotStore($context->rollbackDir());
        $store->save($snapshot);

        return StepResult::complete(
            100,
            'Snapshot created.',
            ['snapshot_id' => $snapshot->id()]
        );
    }
}
