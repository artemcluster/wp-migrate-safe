<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Database;

/**
 * Streams a SQL dump of WordPress tables to a target file.
 *
 * Chunked by (table_index, row_offset) cursor so work can be split across
 * HTTP requests without OOM on huge tables (wp_postmeta with 10M+ rows).
 */
final class DatabaseDumper
{
    /**
     * Dump work for up to $budgetSeconds seconds, appending to $targetFile.
     *
     * @param array<string, mixed> $cursor
     * @return array{done: bool, table_index: int, row_offset: int, tables: string[]}
     */
    public function dumpChunk(string $targetFile, array $cursor, int $budgetSeconds): array
    {
        global $wpdb;

        $startedAt = microtime(true);
        $tables = $cursor['tables'] ?? $this->listTables($wpdb);
        $tableIndex = (int) ($cursor['table_index'] ?? 0);
        $rowOffset = (int) ($cursor['row_offset'] ?? 0);

        $handle = fopen($targetFile, $tableIndex === 0 && $rowOffset === 0 ? 'wb' : 'ab');
        if ($handle === false) {
            throw new \RuntimeException('Could not open dump target: ' . $targetFile);
        }

        try {
            while ($tableIndex < count($tables)) {
                $table = $tables[$tableIndex];

                if ($rowOffset === 0) {
                    $this->writeTableSchema($handle, $table, $wpdb);
                }

                $rowsPerBatch = 500;
                $rowsWritten = $this->writeTableRows($handle, $table, $rowOffset, $rowsPerBatch, $wpdb);

                if ($rowsWritten === 0) {
                    $tableIndex++;
                    $rowOffset = 0;
                } else {
                    $rowOffset += $rowsWritten;
                }

                if (microtime(true) - $startedAt >= $budgetSeconds) {
                    return [
                        'done' => false,
                        'table_index' => $tableIndex,
                        'row_offset' => $rowOffset,
                        'tables' => $tables,
                    ];
                }
            }

            return [
                'done' => true,
                'table_index' => $tableIndex,
                'row_offset' => 0,
                'tables' => $tables,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return string[]
     */
    private function listTables(\wpdb $wpdb): array
    {
        $prefix = $wpdb->prefix;
        // SHOW TABLES returns all tables; PHP-side filter for exact prefix match.
        // Using LIKE with escaped underscore is unreliable across MySQL setups
        // (sql_mode, NO_BACKSLASH_ESCAPES, wpdb->prepare behaviour), so we
        // filter in PHP to guarantee we ONLY take rows that start with $prefix.
        $rows = $wpdb->get_col('SHOW TABLES');
        $filtered = array_filter((array) $rows, static function ($table) use ($prefix) {
            return is_string($table) && strpos($table, $prefix) === 0;
        });
        sort($filtered);
        return array_values($filtered);
    }

    /**
     * @param resource $handle
     */
    private function writeTableSchema($handle, string $table, \wpdb $wpdb): void
    {
        fwrite($handle, "\n-- Table: $table\n");
        fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");

        $row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        $create = $row[1] ?? null;
        if (!is_string($create) || $create === '') {
            throw new \RuntimeException('Could not SHOW CREATE TABLE for ' . $table);
        }
        fwrite($handle, $create . ";\n\n");
    }

    /**
     * @param resource $handle
     */
    private function writeTableRows($handle, string $table, int $offset, int $limit, \wpdb $wpdb): int
    {
        $sql = $wpdb->prepare("SELECT * FROM `$table` LIMIT %d OFFSET %d", $limit, $offset);
        $rows = $wpdb->get_results($sql, ARRAY_N);
        if (!is_array($rows) || count($rows) === 0) {
            return 0;
        }

        foreach ($rows as $row) {
            $escaped = array_map(function ($v) use ($wpdb) {
                if ($v === null) return 'NULL';
                return "'" . $wpdb->_real_escape((string) $v) . "'";
            }, $row);
            fwrite($handle, "INSERT INTO `$table` VALUES (" . implode(', ', $escaped) . ");\n");
        }
        return count($rows);
    }
}
