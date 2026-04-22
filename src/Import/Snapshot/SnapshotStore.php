<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Snapshot;

/**
 * Filesystem persistence for Snapshot objects.
 *
 * Each snapshot is stored as a JSON file named `{snapshot_id}.json` under rollbackDir.
 */
final class SnapshotStore
{
    private string $dir;

    public function __construct(string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create snapshot directory: ' . $dir);
        }
        $this->dir = rtrim($dir, '/\\');
    }

    public function save(Snapshot $snapshot): void
    {
        $path = $this->path($snapshot->id());
        $tmp  = $path . '.tmp';
        $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Could not serialize snapshot.');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write snapshot file: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not rename snapshot file into place.');
        }
    }

    public function load(string $snapshotId): Snapshot
    {
        $path = $this->path($snapshotId);
        if (!is_file($path)) {
            throw new \RuntimeException('Snapshot not found: ' . $snapshotId);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Snapshot file corrupt: ' . $snapshotId);
        }
        return Snapshot::fromArray($data);
    }

    public function delete(string $snapshotId): void
    {
        @unlink($this->path($snapshotId));
    }

    /** @return Snapshot[] All snapshots sorted newest first. */
    public function findAll(): array
    {
        $result = [];
        foreach ((array) glob($this->dir . '/*.json') as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $result[] = Snapshot::fromArray($data);
            }
        }
        usort($result, fn(Snapshot $a, Snapshot $b) => $b->createdAt() <=> $a->createdAt());
        return $result;
    }

    private function path(string $snapshotId): string
    {
        // Snapshot IDs may be UUIDs or hex strings; allow alphanumeric + hyphens.
        if (!preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $snapshotId)) {
            throw new \InvalidArgumentException('Invalid snapshot id: ' . $snapshotId);
        }
        return $this->dir . '/' . $snapshotId . '.json';
    }
}
