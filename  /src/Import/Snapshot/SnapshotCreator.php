<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Snapshot;

use WpMigrateSafe\Export\Database\DatabaseDumper;

/**
 * Create a pre-import snapshot:
 *   1. Dump current DB to {rollbackDir}/{id}/database.sql (reuses Plan 4's DatabaseDumper).
 *   2. Atomically rename:
 *      wp-content/plugins  → wp-content/plugins.rollback.{id}
 *      wp-content/themes   → wp-content/themes.rollback.{id}
 *      wp-content/uploads  → wp-content/uploads.rollback.{id}
 *
 * Rename is O(1) on same filesystem — no data is copied.
 * After rename, plugins/themes/uploads dirs DO NOT EXIST. The caller must
 * populate them from the archive before any WordPress feature accesses them.
 */
final class SnapshotCreator
{
    private SnapshotStore $store;
    private string $wpContentDir;

    public function __construct(SnapshotStore $store, string $wpContentDir)
    {
        $this->store = $store;
        $this->wpContentDir = rtrim($wpContentDir, '/\\');
    }

    public function create(): Snapshot
    {
        $id = bin2hex(random_bytes(16));
        $snapshotDir = $this->store->rollbackDir() . '/' . $id;
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);

        // 1. Dump database fully (snapshot runs in a single step, so budget is large).
        $dumpPath = $snapshotDir . '/database.sql';
        $dumper = new DatabaseDumper();
        $cursor = [];
        do {
            $cursor = $dumper->dumpChunk($dumpPath, $cursor, 60);
        } while (!$cursor['done']);

        // 2. Rename content directories.
        $pluginsRollback = $this->wpContentDir . '/plugins.rollback.' . $id;
        $themesRollback  = $this->wpContentDir . '/themes.rollback.' . $id;
        $uploadsRollback = $this->wpContentDir . '/uploads.rollback.' . $id;

        $this->renameIfExists($this->wpContentDir . '/plugins', $pluginsRollback);
        $this->renameIfExists($this->wpContentDir . '/themes', $themesRollback);
        $this->renameIfExists($this->wpContentDir . '/uploads', $uploadsRollback);

        $snapshot = new Snapshot(
            $id, time(), $dumpPath,
            $pluginsRollback, $themesRollback, $uploadsRollback,
            Snapshot::STATUS_PENDING
        );
        $this->store->save($snapshot);
        return $snapshot;
    }

    private function renameIfExists(string $from, string $to): void
    {
        if (!is_dir($from)) {
            return; // Nothing to rename; snapshot records the expected path regardless.
        }
        if (!rename($from, $to)) {
            throw new \RuntimeException(sprintf('rename(%s, %s) failed.', $from, $to));
        }
    }
}
