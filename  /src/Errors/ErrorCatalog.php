<?php
declare(strict_types=1);

namespace WpMigrateSafe\Errors;

/**
 * Canonical registry of all plugin error codes.
 *
 * Every error surfaced in the UI or REST response should reference one of
 * these codes so users can google/support can trace behavior.
 *
 * Design notes:
 * - `message` is a default English template; production builds pass it through
 *   __() for i18n (translators reference `wp-migrate-safe.pot`).
 * - `hint` is a user-actionable tip, kept short.
 * - `doc_slug` is appended to the docs base URL to deep-link into documentation.
 * - `recoverable` = true means the UI should offer "Retry" or "Continue" buttons.
 */
final class ErrorCatalog
{
    /**
     * @return array<string, array{category: string, message: string, hint: string, recoverable: bool, doc_slug: string}>
     */
    public static function all(): array
    {
        return [
            'DISK_FULL' => [
                'category' => 'filesystem',
                'message' => 'Not enough free disk space.',
                'hint' => 'Delete old backups or increase your hosting quota.',
                'recoverable' => false,
                'doc_slug' => 'errors#DISK_FULL',
            ],
            'PHP_MEMORY_LOW' => [
                'category' => 'environment',
                'message' => 'PHP memory_limit is too low for this operation.',
                'hint' => 'Raise memory_limit to at least 256M in php.ini or wp-config.php.',
                'recoverable' => false,
                'doc_slug' => 'errors#PHP_MEMORY_LOW',
            ],
            'MYSQL_VERSION_OLD' => [
                'category' => 'environment',
                'message' => 'Server MySQL/MariaDB version is older than the archive expects.',
                'hint' => 'Upgrade MySQL or import may fail on recent SQL syntax.',
                'recoverable' => true,
                'doc_slug' => 'errors#MYSQL_VERSION_OLD',
            ],
            'WPRESS_CORRUPTED' => [
                'category' => 'archive',
                'message' => 'Archive file is corrupted.',
                'hint' => 'Re-upload the backup.',
                'recoverable' => false,
                'doc_slug' => 'errors#WPRESS_CORRUPTED',
            ],
            'WPRESS_TRUNCATED' => [
                'category' => 'archive',
                'message' => 'Archive was truncated before the end-of-file marker.',
                'hint' => 'Re-upload the backup — transfer may have been interrupted.',
                'recoverable' => false,
                'doc_slug' => 'errors#WPRESS_TRUNCATED',
            ],
            'DB_IMPORT_SYNTAX' => [
                'category' => 'database',
                'message' => 'SQL statement failed during import.',
                'hint' => 'Check MySQL version compatibility; the archive may use syntax your server does not support.',
                'recoverable' => false,
                'doc_slug' => 'errors#DB_IMPORT_SYNTAX',
            ],
            'DB_CONNECTION_LOST' => [
                'category' => 'database',
                'message' => 'Database connection was lost mid-operation.',
                'hint' => 'Check max_allowed_packet and wait_timeout on MySQL.',
                'recoverable' => true,
                'doc_slug' => 'errors#DB_CONNECTION_LOST',
            ],
            'DB_ROW_TOO_LARGE' => [
                'category' => 'database',
                'message' => 'A single database row exceeds available memory.',
                'hint' => 'Use the WP-CLI import command (wp migrate-safe import) — no memory limit.',
                'recoverable' => false,
                'doc_slug' => 'errors#DB_ROW_TOO_LARGE',
            ],
            'FS_PERMISSION' => [
                'category' => 'filesystem',
                'message' => 'Filesystem permission denied.',
                'hint' => 'Ensure the PHP user can write to wp-content/. chmod 755 on directories typically fixes this.',
                'recoverable' => false,
                'doc_slug' => 'errors#FS_PERMISSION',
            ],
            'UPLOAD_CHUNK_HASH' => [
                'category' => 'user',
                'message' => 'Uploaded chunk failed integrity check.',
                'hint' => 'Chunk will be automatically retried up to 3 times.',
                'recoverable' => true,
                'doc_slug' => 'errors#UPLOAD_CHUNK_HASH',
            ],
            'STEP_TIMEOUT' => [
                'category' => 'environment',
                'message' => 'A step is taking longer than expected.',
                'hint' => 'Try the WP-CLI command for very large sites.',
                'recoverable' => true,
                'doc_slug' => 'errors#STEP_TIMEOUT',
            ],
            'JOB_HEARTBEAT_LOST' => [
                'category' => 'environment',
                'message' => 'No heartbeat from server for over 60 seconds.',
                'hint' => 'The PHP worker may have been killed (OOM, timeout). Check server error.log.',
                'recoverable' => true,
                'doc_slug' => 'errors#JOB_HEARTBEAT_LOST',
            ],
            'ROLLBACK_FAILED' => [
                'category' => 'critical',
                'message' => 'Rollback itself failed. Site is in an indeterminate state.',
                'hint' => 'Follow the manual recovery steps provided in the error context.',
                'recoverable' => false,
                'doc_slug' => 'errors#ROLLBACK_FAILED',
            ],
            'IMPORT_FAILED_ROLLED_BACK' => [
                'category' => 'database',
                'message' => 'Import failed but the site was automatically restored.',
                'hint' => 'Read the original error and fix the source issue before retrying.',
                'recoverable' => true,
                'doc_slug' => 'errors#IMPORT_FAILED_ROLLED_BACK',
            ],
            'IMPORT_FAILED' => [
                'category' => 'database',
                'message' => 'Import failed before snapshot was taken; site is unchanged.',
                'hint' => 'Correct the reported issue and retry.',
                'recoverable' => true,
                'doc_slug' => 'errors#IMPORT_FAILED',
            ],
            'GLOBAL_LOCK_HELD' => [
                'category' => 'concurrency',
                'message' => 'Another import or export is already running.',
                'hint' => 'Wait for it to finish, or abort it from the Tools menu.',
                'recoverable' => true,
                'doc_slug' => 'errors#GLOBAL_LOCK_HELD',
            ],
        ];
    }

    public static function has(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    /**
     * @return array{category: string, message: string, hint: string, recoverable: bool, doc_slug: string}|null
     */
    public static function lookup(string $code): ?array
    {
        $all = self::all();
        return $all[$code] ?? null;
    }
}
