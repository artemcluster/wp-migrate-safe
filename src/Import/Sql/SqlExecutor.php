<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Sql;

/**
 * Executes SQL statements against the WordPress database ($wpdb).
 *
 * Wraps $wpdb->query() and throws on any error.
 *
 * This class requires a live WordPress + MySQL environment (WP-integration test).
 */
final class SqlExecutor
{
    /**
     * Execute a single SQL statement.
     *
     * @throws \RuntimeException on any DB error.
     */
    public function execute(string $sql): void
    {
        global $wpdb;

        $result = $wpdb->query($sql);

        if ($result === false || $wpdb->last_error !== '') {
            throw new \RuntimeException(
                sprintf('SQL execution error: %s | Statement: %s', $wpdb->last_error, substr($sql, 0, 200))
            );
        }
    }

    /**
     * Execute all statements from a SqlDumpReader, optionally rewriting each.
     *
     * @throws \RuntimeException on any DB error.
     */
    public function executeAll(SqlDumpReader $reader, ?SqlRewriter $rewriter = null): int
    {
        $count = 0;

        foreach ($reader->statements() as $sql) {
            if ($rewriter !== null) {
                $sql = $rewriter->rewrite($sql);
            }
            $this->execute($sql);
            $count++;
        }

        return $count;
    }
}
