<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\Database\PrefixRewriter;
use WpMigrateSafe\Import\Database\SqlDumpReader;
use WpMigrateSafe\Import\Database\SqlExecutor;
use WpMigrateSafe\Import\Database\SqlRewriter;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;
use WpMigrateSafe\SearchReplace\Replacer;

final class ImportDatabase implements ImportStep
{
    public function name(): string { return 'import-database'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        global $wpdb;

        $started = microtime(true);
        $dumpPath = $context->extractDir() . '/database/database.sql';
        if (!is_file($dumpPath)) {
            throw new \RuntimeException('database/database.sql not found in extracted archive.');
        }

        $dropped = (bool) ($cursor['dropped_existing'] ?? false);
        if (!$dropped) {
            $this->dropExistingTables($wpdb);
            $dropped = true;
        }

        $startStmt = (int) ($cursor['statement_index'] ?? 0);
        $targetPrefix = (string) $wpdb->prefix;
        $sourcePrefix = $context->sourcePrefix();
        $prefixRewriter = new PrefixRewriter($sourcePrefix, $targetPrefix);
        $urlRewriter = new SqlRewriter(new Replacer($context->oldUrl() ?: 'http://__skip__', $context->newUrl()));
        $executor = new SqlExecutor($wpdb);

        $reader = new SqlDumpReader($dumpPath);
        $index = 0;
        foreach ($reader->statements() as $stmt) {
            if ($index < $startStmt) { $index++; continue; }

            // 1) Rewrite table prefix (`wpsp_x` → `wp_x`) if needed.
            $rewritten = $prefixRewriter->rewrite($stmt);
            // 2) Rewrite URLs inside INSERT string literals, if old_url was given.
            if ($context->oldUrl() !== '') {
                $rewritten = $urlRewriter->rewrite($rewritten);
            }

            $executor->execute($rewritten);
            $index++;

            if (microtime(true) - $started >= $maxSeconds) {
                return StepResult::advance(
                    ['dropped_existing' => $dropped, 'statement_index' => $index],
                    min(95, $index % 100),
                    sprintf('Executed %d SQL statements…', $index)
                );
            }
        }

        return StepResult::complete(100, sprintf('Database imported (%d statements).', $index));
    }

    private function dropExistingTables(\wpdb $wpdb): void
    {
        // SHOW TABLES + PHP strict-prefix filter. LIKE with escaped underscore
        // is unreliable across MySQL setups (sql_mode, wpdb prepare quirks).
        $prefix = (string) $wpdb->prefix;
        if ($prefix === '') return;
        $rows = $wpdb->get_col('SHOW TABLES');
        foreach ((array) $rows as $table) {
            if (!is_string($table) || strpos($table, $prefix) !== 0) continue;
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
    }
}
