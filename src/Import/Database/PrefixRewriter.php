<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Database;

/**
 * Rewrite MySQL table-name prefix inside SQL statements.
 *
 * Replaces every backtick-delimited identifier that starts with `{source_prefix}`
 * with `{target_prefix}`, leaving the rest of the identifier intact:
 *
 *   `wpsp_options`  →  `wp_options`
 *   `wpsp_posts`    →  `wp_posts`
 *   `wpsp_yoast_*`  →  `wp_yoast_*`
 *
 * Only rewrites inside backticks — never touches string literals. WP-generated
 * SQL dumps always use backtick-quoted identifiers, so this is reliable.
 *
 * If source and target prefixes are identical, the SQL is returned unchanged.
 */
final class PrefixRewriter
{
    private string $sourcePrefix;
    private string $targetPrefix;
    /** @var string|null cached regex */
    private ?string $pattern;

    public function __construct(string $sourcePrefix, string $targetPrefix)
    {
        $this->sourcePrefix = $sourcePrefix;
        $this->targetPrefix = $targetPrefix;
        if ($sourcePrefix === '' || $sourcePrefix === $targetPrefix) {
            $this->pattern = null;
        } else {
            $this->pattern = '/`' . preg_quote($sourcePrefix, '/') . '/';
        }
    }

    public function isNoOp(): bool
    {
        return $this->pattern === null;
    }

    public function rewrite(string $sql): string
    {
        if ($this->pattern === null) {
            return $sql;
        }
        $result = preg_replace($this->pattern, '`' . $this->targetPrefix, $sql);
        return $result === null ? $sql : $result;
    }

    public function sourcePrefix(): string { return $this->sourcePrefix; }
    public function targetPrefix(): string { return $this->targetPrefix; }
}
