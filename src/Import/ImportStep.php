<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

use WpMigrateSafe\Job\StepResult;

/**
 * A single step in the import pipeline.
 *
 * Each step should complete its work in <= 20 seconds. If more work remains,
 * it returns StepResult::advance() with an updated cursor; the orchestrator
 * calls the same step again with that cursor.
 */
interface ImportStep
{
    public function name(): string;

    /**
     * @param array<string, mixed> $cursor
     */
    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult;
}
