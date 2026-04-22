<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Sql;

/**
 * Reads SQL statements from a dump file, yielding one statement at a time.
 *
 * Handles:
 * - Single-line (-- and #) comments.
 * - Multi-line C-style (/* ... *\/) comments.
 * - Statements terminated by semicolons.
 * - Quoted strings with escaped characters.
 */
final class SqlDumpReader
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Yields complete SQL statements (without trailing semicolon) from the dump file.
     *
     * @return \Generator<string>
     * @throws \RuntimeException if the file cannot be opened.
     */
    public function statements(): \Generator
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open SQL dump: ' . $this->path);
        }

        try {
            yield from $this->parse($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function parse($handle): \Generator
    {
        $buffer      = '';
        $inString    = false;
        $stringChar  = '';
        $inComment   = false; // /* */ style
        $prev        = '';

        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            // Skip single-line comments if not inside a string.
            if (!$inString && !$inComment) {
                $trimmed = ltrim($line);
                if (strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                    continue;
                }
            }

            for ($i = 0, $len = strlen($line); $i < $len; $i++) {
                $char = $line[$i];

                if ($inComment) {
                    if ($prev === '*' && $char === '/') {
                        $inComment = false;
                        $prev = '';
                    } else {
                        $prev = $char;
                    }
                    continue;
                }

                if (!$inString && $prev !== '\\' && ($char === '"' || $char === "'")) {
                    $inString   = true;
                    $stringChar = $char;
                    $buffer    .= $char;
                    $prev       = $char;
                    continue;
                }

                if ($inString) {
                    $buffer .= $char;
                    if ($char === $stringChar && $prev !== '\\') {
                        $inString = false;
                    }
                    $prev = $char;
                    continue;
                }

                // Check for block comment start.
                if ($char === '/' && isset($line[$i + 1]) && $line[$i + 1] === '*') {
                    $inComment = true;
                    $i++;
                    $prev = '';
                    continue;
                }

                if ($char === ';') {
                    $stmt = trim($buffer);
                    $buffer = '';
                    $prev = '';
                    if ($stmt !== '') {
                        yield $stmt;
                    }
                    continue;
                }

                $buffer .= $char;
                $prev = $char;
            }
        }

        // Yield any remaining content without a trailing semicolon.
        $stmt = trim($buffer);
        if ($stmt !== '') {
            yield $stmt;
        }
    }
}
