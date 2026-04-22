<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Database;

use WpMigrateSafe\SearchReplace\Replacer;

/**
 * Rewrite URLs inside a SQL INSERT statement by decoding string values,
 * applying the Replacer to each, and re-encoding them.
 *
 * Approach: extract VALUES (...) lists by a regex-tokenizer that respects
 * quoted strings and escapes. Apply Replacer to each string literal.
 *
 * Caveat: this handles the statement format produced by Plan 4's DatabaseDumper
 * (one INSERT per row). For multi-row INSERTs the same logic still applies —
 * each VALUES tuple is processed independently.
 */
final class SqlRewriter
{
    private Replacer $replacer;

    public function __construct(Replacer $replacer)
    {
        $this->replacer = $replacer;
    }

    /**
     * Rewrite every string literal inside a single SQL statement.
     *
     * Only INSERT statements are rewritten; CREATE/DROP/etc. are returned unchanged.
     */
    public function rewrite(string $statement): string
    {
        $trimmed = ltrim($statement);
        if (stripos($trimmed, 'INSERT') !== 0) {
            return $statement;
        }

        return preg_replace_callback(
            "/'((?:\\\\.|[^'\\\\])*)'/",
            function (array $m): string {
                $unescaped = $this->unescape($m[1]);
                $result = $this->replacer->apply($unescaped);
                $replaced = $result->value();
                if ($result->replacements() === 0 && $replaced === $unescaped) {
                    return $m[0]; // unchanged
                }
                return "'" . $this->escape($replaced) . "'";
            },
            $statement
        ) ?? $statement;
    }

    private function unescape(string $s): string
    {
        return str_replace(
            ['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t', '\\0'],
            ['\\',   "'",   '"',   "\n",  "\r",  "\t",  "\0"],
            $s
        );
    }

    private function escape(string $s): string
    {
        return str_replace(
            ['\\',   "'",  '"',  "\n",  "\r",  "\t",  "\0"],
            ['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t', '\\0'],
            $s
        );
    }
}
