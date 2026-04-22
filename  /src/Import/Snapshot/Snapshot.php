<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Snapshot;

/**
 * Immutable metadata about a pre-import snapshot.
 *
 * The actual on-disk state consists of:
 *   - {rollbackDir}/{id}/database.sql
 *   - wp-content/plugins   → renamed to plugins.rollback.{id}
 *   - wp-content/themes    → renamed to themes.rollback.{id}
 *   - wp-content/uploads   → renamed to uploads.rollback.{id}
 *
 * status=pending → rollback would run on failure
 * status=committed → import succeeded; safe to delete after retention window
 */
final class Snapshot
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMMITTED = 'committed';

    private string $id;
    private int $createdAt;
    private string $dbDumpPath;
    private string $pluginsRollbackPath;
    private string $themesRollbackPath;
    private string $uploadsRollbackPath;
    private string $status;

    public function __construct(
        string $id,
        int $createdAt,
        string $dbDumpPath,
        string $pluginsRollbackPath,
        string $themesRollbackPath,
        string $uploadsRollbackPath,
        string $status
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new \InvalidArgumentException('Invalid snapshot id.');
        }
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_COMMITTED], true)) {
            throw new \InvalidArgumentException('Invalid status: ' . $status);
        }
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->dbDumpPath = $dbDumpPath;
        $this->pluginsRollbackPath = $pluginsRollbackPath;
        $this->themesRollbackPath = $themesRollbackPath;
        $this->uploadsRollbackPath = $uploadsRollbackPath;
        $this->status = $status;
    }

    public function id(): string { return $this->id; }
    public function createdAt(): int { return $this->createdAt; }
    public function dbDumpPath(): string { return $this->dbDumpPath; }
    public function pluginsRollbackPath(): string { return $this->pluginsRollbackPath; }
    public function themesRollbackPath(): string { return $this->themesRollbackPath; }
    public function uploadsRollbackPath(): string { return $this->uploadsRollbackPath; }
    public function status(): string { return $this->status; }

    public function commit(): self
    {
        return new self(
            $this->id, $this->createdAt, $this->dbDumpPath, $this->pluginsRollbackPath,
            $this->themesRollbackPath, $this->uploadsRollbackPath, self::STATUS_COMMITTED
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'db_dump_path' => $this->dbDumpPath,
            'plugins_rollback_path' => $this->pluginsRollbackPath,
            'themes_rollback_path' => $this->themesRollbackPath,
            'uploads_rollback_path' => $this->uploadsRollbackPath,
            'status' => $this->status,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            (string) $d['id'],
            (int) $d['created_at'],
            (string) $d['db_dump_path'],
            (string) $d['plugins_rollback_path'],
            (string) $d['themes_rollback_path'],
            (string) $d['uploads_rollback_path'],
            (string) $d['status']
        );
    }
}
