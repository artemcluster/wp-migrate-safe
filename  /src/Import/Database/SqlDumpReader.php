<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Database;

use Generator;

/**
 * Stream-reads a SQL dump file, yielding one statement at a time.
 *
 * A "statement" is any line sequence ending with a semicolon at end-of-line.
 * Comments (-- …) and blank lines are skipped.
 */
final class SqlDumpReader
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @return Generator<int, string>
     */
    public function statements(): Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open SQL dump: ' . $this->path);
        }

        try {
            $buffer = '';
            while (($line = fgets($handle)) !== false) {
                $trim = rtrim($line, "\r\n");
                if ($trim === '' || strpos(ltrim($trim), '--') === 0) continue;
                $buffer .= $line;
                if (substr($trim, -1) === ';') {
                    yield $buffer;
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
