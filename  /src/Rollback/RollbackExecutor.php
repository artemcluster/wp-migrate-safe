<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rollback;

use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Rollback\Exception\RollbackFailedException;

/**
 * Restore a snapshot: replay DB dump, restore content directories.
 *
 * Rollback is atomic-best-effort. Each substep is attempted; failures are collected
 * and reported. If any step fails, RollbackFailedException is thrown with
 * user-facing manual recovery instructions.
 */
final class RollbackExecutor
{
    private string $wpContentDir;

    public function __construct(string $wpContentDir)
    {
        $this->wpContentDir = rtrim($wpContentDir, '/\\');
    }

    public function execute(Snapshot $snapshot): void
    {
        $errors = [];

        // 1. Restore DB from dump.
        try {
            $this->restoreDatabase($snapshot->dbDumpPath());
        } catch (\Throwable $e) {
            $errors[] = 'Database restore failed: ' . $e->getMessage();
        }

        // 2. Restore plugins dir.
        try {
            $this->restoreDirectory($snapshot->pluginsRollbackPath(), $this->wpContentDir . '/plugins');
        } catch (\Throwable $e) {
            $errors[] = 'Plugins restore failed: ' . $e->getMessage();
        }

        // 3. Restore themes dir.
        try {
            $this->restoreDirectory($snapshot->themesRollbackPath(), $this->wpContentDir . '/themes');
        } catch (\Throwable $e) {
            $errors[] = 'Themes restore failed: ' . $e->getMessage();
        }

        // 4. Restore uploads dir.
        try {
            $this->restoreDirectory($snapshot->uploadsRollbackPath(), $this->wpContentDir . '/uploads');
        } catch (\Throwable $e) {
            $errors[] = 'Uploads restore failed: ' . $e->getMessage();
        }

        if (!empty($errors)) {
            throw new RollbackFailedException(
                'Rollback could not be completed: ' . implode('; ', $errors),
                $this->buildManualRecoverySteps($snapshot, $errors)
            );
        }
    }

    private function restoreDatabase(string $dumpPath): void
    {
        if (!is_file($dumpPath)) {
            throw new \RuntimeException('DB dump not found: ' . $dumpPath);
        }

        global $wpdb;

        // Drop all current tables with wpdb prefix (post-import state).
        $prefix = $wpdb->prefix;
        $like = str_replace('_', '\_', $prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        foreach ((array) $tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }

        // Replay dump.
        $handle = fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open DB dump: ' . $dumpPath);
        }

        try {
            $statement = '';
            while (($line = fgets($handle)) !== false) {
                $trim = trim($line);
                if ($trim === '' || strpos($trim, '--') === 0) continue;
                $statement .= $line;
                if (substr($trim, -1) === ';') {
                    $result = $wpdb->query($statement);
                    if ($result === false) {
                        throw new \RuntimeException('Rollback SQL failed: ' . $wpdb->last_error);
                    }
                    $statement = '';
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function restoreDirectory(string $rollbackPath, string $livePath): void
    {
        if (!is_dir($rollbackPath)) {
            // Original directory never existed; nothing to restore.
            return;
        }
        if (is_dir($livePath)) {
            // Move the failed-import dir aside so rename succeeds.
            $failedAside = $livePath . '.failed.' . time();
            if (!rename($livePath, $failedAside)) {
                throw new \RuntimeException('Could not move aside current directory: ' . $livePath);
            }
        }
        if (!rename($rollbackPath, $livePath)) {
            throw new \RuntimeException(sprintf('rename(%s, %s) failed.', $rollbackPath, $livePath));
        }
    }

    /**
     * @param array<int, string> $errors
     * @return array<int, string>
     */
    private function buildManualRecoverySteps(Snapshot $snapshot, array $errors): array
    {
        return [
            sprintf('Snapshot ID: %s', $snapshot->id()),
            sprintf('DB dump is at: %s', $snapshot->dbDumpPath()),
            sprintf('Old plugins: %s', $snapshot->pluginsRollbackPath()),
            sprintf('Old themes: %s', $snapshot->themesRollbackPath()),
            sprintf('Old uploads: %s', $snapshot->uploadsRollbackPath()),
            '1. Connect to your server via SSH or FTP.',
            '2. Restore DB manually: mysql < ' . $snapshot->dbDumpPath(),
            '3. Rename directories:',
            '   mv ' . $snapshot->pluginsRollbackPath() . ' ' . $this->wpContentDir . '/plugins',
            '   mv ' . $snapshot->themesRollbackPath() . ' ' . $this->wpContentDir . '/themes',
            '   mv ' . $snapshot->uploadsRollbackPath() . ' ' . $this->wpContentDir . '/uploads',
            '4. Contact support with these exact error messages: ' . implode(' | ', $errors),
        ];
    }
}
