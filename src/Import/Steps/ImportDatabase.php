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

        // Suspend integrity checks for the duration of this slice. MySQL rejects
        // CREATE TABLE statements whose foreign keys reference tables not yet created
        // (errno 150). Same with UNIQUE index deferrals and strict SQL modes that
        // reject NO_AUTO_VALUE_ON_ZERO inserts. mysqldump and ai1wm both set these
        // before an import. These are connection-scoped; each HTTP slice gets a
        // fresh wpdb connection, so we re-apply on every slice entry.
        $this->suspendIntegrityChecks($wpdb);

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
            // 3) Drop bare SET statements from inside the dump — we manage the session
            //    ourselves and dumps sometimes include `SET FOREIGN_KEY_CHECKS=1`
            //    mid-stream, which would undo our suspension.
            if ($this->isSessionSetStatement($rewritten)) {
                $index++;
                continue;
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

        $this->restoreIntegrityChecks($wpdb);

        return StepResult::complete(100, sprintf('Database imported (%d statements).', $index));
    }

    private function suspendIntegrityChecks(\wpdb $wpdb): void
    {
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('SET UNIQUE_CHECKS = 0');
        $wpdb->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $wpdb->query('SET NAMES utf8mb4');
    }

    private function restoreIntegrityChecks(\wpdb $wpdb): void
    {
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        $wpdb->query('SET UNIQUE_CHECKS = 1');
    }

    /**
     * SET session-variable statements that we already manage ourselves.
     * Dumps sometimes intermix these; letting them run would re-enable FK checks
     * and fail CREATE TABLE statements whose referenced tables haven't been
     * created yet.
     */
    private function isSessionSetStatement(string $stmt): bool
    {
        $trimmed = ltrim($stmt);
        if (stripos($trimmed, 'SET ') !== 0) return false;
        $patterns = [
            '/^SET\s+(@\w+\s*=\s*@@)?\s*FOREIGN_KEY_CHECKS\b/i',
            '/^SET\s+(@\w+\s*=\s*@@)?\s*UNIQUE_CHECKS\b/i',
            '/^SET\s+(SESSION\s+|@\w+\s*=\s*@@)?\s*sql_mode\b/i',
            '/^SET\s+NAMES\b/i',
            '/^SET\s+CHARACTER\s+SET\b/i',
            '/^SET\s+time_zone\b/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $trimmed) === 1) return true;
        }
        return false;
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
