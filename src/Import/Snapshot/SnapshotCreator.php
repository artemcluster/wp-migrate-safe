<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Snapshot;

/**
 * Creates a rollback snapshot before import begins.
 *
 * Dumps the current database to a SQL file and records which
 * wp-content directories exist, so RollbackExecutor can restore them.
 *
 * This class requires a live WordPress + MySQL environment (WP-integration test).
 */
final class SnapshotCreator
{
    private string $rollbackDir;
    private string $wpContentDir;
    private string $dbPrefix;

    public function __construct(string $rollbackDir, string $wpContentDir, string $dbPrefix)
    {
        $this->rollbackDir  = rtrim($rollbackDir, '/\\');
        $this->wpContentDir = rtrim($wpContentDir, '/\\');
        $this->dbPrefix     = $dbPrefix;
    }

    /**
     * Create a full snapshot: DB dump + record of content directories.
     *
     * @throws \RuntimeException on any failure.
     */
    public function create(): Snapshot
    {
        $id        = bin2hex(random_bytes(16));
        $timestamp = time();
        $dumpPath  = $this->rollbackDir . '/' . $id . '_db.sql';

        $this->dumpDatabase($dumpPath);

        $contentPaths = $this->listContentPaths();

        return new Snapshot(
            $id,
            $timestamp,
            $contentPaths,
            $dumpPath,
            $this->dbPrefix
        );
    }

    private function dumpDatabase(string $dumpPath): void
    {
        global $wpdb;

        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->esc_like($this->dbPrefix)}%'");
        if ($tables === null) {
            throw new \RuntimeException('Could not retrieve table list from database.');
        }

        $handle = @fopen($dumpPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open dump file for writing: ' . $dumpPath);
        }

        try {
            fwrite($handle, "-- wp-migrate-safe rollback dump\n");
            fwrite($handle, "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $this->dumpTable($handle, (string) $table);
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function dumpTable($handle, string $table): void
    {
        global $wpdb;

        // DROP + CREATE TABLE
        $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        if (!$createRow) {
            return;
        }

        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $createRow[1] . ";\n\n");

        // Data
        $offset = 0;
        $batch  = 500;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset),
                ARRAY_N
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = implode(', ', array_map(
                    fn($v) => $v === null ? 'NULL' : "'" . esc_sql((string) $v) . "'",
                    $row
                ));
                fwrite($handle, "INSERT INTO `{$table}` VALUES ({$values});\n");
            }

            $offset += $batch;
        } while (count($rows) === $batch);

        fwrite($handle, "\n");
    }

    /** @return string[] */
    private function listContentPaths(): array
    {
        $paths = [];
        $dirs  = ['plugins', 'themes', 'uploads', 'mu-plugins'];

        foreach ($dirs as $subdir) {
            $path = $this->wpContentDir . '/' . $subdir;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
