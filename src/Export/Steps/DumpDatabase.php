<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\Database\DatabaseDumper;
use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Stream a SQL dump of all WP tables into a temp file under the session dir.
 * The temp file will be appended to the archive in the next step.
 */
final class DumpDatabase implements ExportStep
{
    public function name(): string { return 'dump-database'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $target = $context->archivePath() . '.dump.sql';
        $dumper = new DatabaseDumper();

        $result = $dumper->dumpChunk($target, $cursor, $maxSeconds);

        if ($result['done']) {
            return StepResult::complete(10, sprintf(
                'Dumped %d tables.',
                count($result['tables'] ?? [])
            ), ['dump_path' => $target]);
        }

        // Progress estimate based on table_index.
        $total = count($result['tables'] ?? []);
        $progress = $total > 0 ? (int) floor(($result['table_index'] / $total) * 10) : 0;

        return StepResult::advance($result, max(1, $progress), sprintf(
            'Dumping table %d of %d (row offset %d)…',
            $result['table_index'] + 1,
            $total,
            $result['row_offset']
        ));
    }
}
