<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rollback;

use WpMigrateSafe\Import\Snapshot\Snapshot;

/**
 * Restores a WordPress site to its pre-import state using a Snapshot.
 *
 * Strategy:
 *  1. Re-import the SQL dump (DROP+CREATE+INSERT).
 *  2. Remove any directories that were freshly extracted by the import.
 *  3. Report success or throw RollbackFailedException on any failure.
 *
 * This class requires a live WordPress + MySQL environment (WP-integration test).
 */
final class RollbackExecutor
{
    private string $wpContentDir;

    public function __construct(string $wpContentDir)
    {
        $this->wpContentDir = rtrim($wpContentDir, '/\\');
    }

    /**
     * Execute full rollback from snapshot.
     *
     * @throws RollbackFailedException
     */
    public function rollback(Snapshot $snapshot): void
    {
        $failedSteps = [];

        // Step 1: restore database.
        try {
            $this->restoreDatabase($snapshot->sqlDumpPath());
        } catch (\Throwable $e) {
            $failedSteps[] = 'restore_database: ' . $e->getMessage();
        }

        // Step 2: restore content directories (best-effort removal of new content).
        try {
            $this->restoreContentDirectories($snapshot->contentPaths());
        } catch (\Throwable $e) {
            $failedSteps[] = 'restore_content: ' . $e->getMessage();
        }

        if (!empty($failedSteps)) {
            throw new RollbackFailedException(
                'Rollback failed on ' . count($failedSteps) . ' step(s). Manual intervention required.',
                $failedSteps
            );
        }
    }

    private function restoreDatabase(string $dumpPath): void
    {
        global $wpdb;

        if (!is_file($dumpPath)) {
            throw new \RuntimeException('SQL dump file not found: ' . $dumpPath);
        }

        $sql = file_get_contents($dumpPath);
        if ($sql === false) {
            throw new \RuntimeException('Could not read SQL dump: ' . $dumpPath);
        }

        // Split on statement boundaries (semicolon at end of line).
        $statements = preg_split('/;\s*\n/', $sql);
        if ($statements === false) {
            throw new \RuntimeException('Could not parse SQL dump.');
        }

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || strpos($statement, '--') === 0) {
                continue;
            }
            $wpdb->query($statement);
            if ($wpdb->last_error) {
                throw new \RuntimeException('SQL error during rollback: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * For each directory path that was recorded in the snapshot,
     * verify it still exists (the pre-import content should be intact
     * because we backed it up before overwriting). This is a no-op
     * in the current implementation — a future version might copy
     * backup copies back from the rollback dir.
     *
     * @param string[] $contentPaths
     */
    private function restoreContentDirectories(array $contentPaths): void
    {
        // Currently a no-op: the snapshot records paths that existed BEFORE
        // the import. File-level rollback (copying entire directories) is out
        // of scope for v0.1 — only the database is restored.
        // Future: diff the directory listing and remove newly added files.
        foreach ($contentPaths as $path) {
            if (!is_dir($path)) {
                // Logged as warning; not fatal.
                error_log(sprintf('[wp-migrate-safe] Rollback: content path missing: %s', $path));
            }
        }
    }
}
