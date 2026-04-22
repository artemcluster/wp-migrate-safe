<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Sql;

/**
 * Rewrites SQL statements to replace table prefixes and perform URL search-replace.
 *
 * Used when importing a database from a different site:
 *  - Source prefix  (e.g. "wp_")  → target prefix (e.g. "wpnew_")
 *  - Source site URL               → target site URL
 */
final class SqlRewriter
{
    private string $sourcePrefix;
    private string $targetPrefix;
    private string $sourceUrl;
    private string $targetUrl;

    public function __construct(
        string $sourcePrefix,
        string $targetPrefix,
        string $sourceUrl = '',
        string $targetUrl = ''
    ) {
        $this->sourcePrefix = $sourcePrefix;
        $this->targetPrefix = $targetPrefix;
        $this->sourceUrl    = rtrim($sourceUrl, '/');
        $this->targetUrl    = rtrim($targetUrl, '/');
    }

    /**
     * Rewrite a single SQL statement.
     */
    public function rewrite(string $sql): string
    {
        $sql = $this->rewritePrefix($sql);

        if ($this->sourceUrl !== '' && $this->sourceUrl !== $this->targetUrl) {
            $sql = $this->rewriteUrl($sql);
        }

        return $sql;
    }

    private function rewritePrefix(string $sql): string
    {
        if ($this->sourcePrefix === $this->targetPrefix) {
            return $sql;
        }

        // Replace backtick-quoted table names: `wp_options` → `wpnew_options`.
        $escaped = preg_quote($this->sourcePrefix, '/');
        $sql = preg_replace(
            '/`' . $escaped . '([^`]+)`/',
            '`' . $this->targetPrefix . '$1`',
            $sql
        ) ?? $sql;

        return $sql;
    }

    private function rewriteUrl(string $sql): string
    {
        return str_replace($this->sourceUrl, $this->targetUrl, $sql);
    }
}
