<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Database;

/**
 * Execute SQL statements via $wpdb, tracking progress and stopping cleanly on error.
 */
final class SqlExecutor
{
    /** @var \wpdb */
    private $wpdb;

    /**
     * @param \wpdb|null $wpdb Injected for tests; defaults to global.
     */
    public function __construct($wpdb = null)
    {
        if ($wpdb === null) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    /**
     * Execute a single statement. Returns true on success.
     * @throws \RuntimeException on failure with context.
     */
    public function execute(string $statement): void
    {
        $result = $this->wpdb->query($statement);
        if ($result === false) {
            throw new \RuntimeException(sprintf(
                'SQL execution failed: %s | Statement preview: %s',
                (string) $this->wpdb->last_error,
                self::preview($statement)
            ));
        }
    }

    private static function preview(string $statement): string
    {
        $oneLine = preg_replace('/\s+/', ' ', $statement) ?? $statement;
        return substr((string) $oneLine, 0, 120) . (strlen((string) $oneLine) > 120 ? '…' : '');
    }
}
