<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

use WpMigrateSafe\Import\Snapshot\SnapshotStore;

/**
 * Context shared across import steps within a single HTTP request.
 *
 * Constructed once per HTTP request from persisted Job meta.
 */
final class ImportContext
{
    private string $archivePath;
    private string $wpRoot;
    private string $wpContentDir;
    private string $extractDir;
    private string $oldUrl;
    private string $newUrl;
    private SnapshotStore $snapshotStore;

    public function __construct(
        string $archivePath,
        string $wpRoot,
        string $wpContentDir,
        string $extractDir,
        string $oldUrl,
        string $newUrl,
        SnapshotStore $snapshotStore
    ) {
        $this->archivePath = $archivePath;
        $this->wpRoot = rtrim($wpRoot, '/\\');
        $this->wpContentDir = rtrim($wpContentDir, '/\\');
        $this->extractDir = rtrim($extractDir, '/\\');
        $this->oldUrl = $oldUrl;
        $this->newUrl = $newUrl;
        $this->snapshotStore = $snapshotStore;
    }

    public function archivePath(): string { return $this->archivePath; }
    public function wpRoot(): string { return $this->wpRoot; }
    public function wpContentDir(): string { return $this->wpContentDir; }
    public function extractDir(): string { return $this->extractDir; }
    public function oldUrl(): string { return $this->oldUrl; }
    public function newUrl(): string { return $this->newUrl; }
    public function snapshotStore(): SnapshotStore { return $this->snapshotStore; }
}
