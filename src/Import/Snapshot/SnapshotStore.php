<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Snapshot;

/**
 * Persist snapshot metadata as JSON under {rollbackDir}/{id}/snapshot.json.
 */
final class SnapshotStore
{
    private string $rollbackDir;

    public function __construct(string $rollbackDir)
    {
        $this->rollbackDir = rtrim($rollbackDir, '/\\');
        if (!is_dir($this->rollbackDir)) {
            mkdir($this->rollbackDir, 0755, true);
        }
    }

    public function rollbackDir(): string { return $this->rollbackDir; }

    public function save(Snapshot $snapshot): void
    {
        $dir = $this->rollbackDir . '/' . $snapshot->id();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/snapshot.json';
        if (file_put_contents($path, json_encode($snapshot->toArray(), JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new \RuntimeException('Could not save snapshot metadata: ' . $path);
        }
    }

    public function load(string $id): Snapshot
    {
        $path = $this->rollbackDir . '/' . $id . '/snapshot.json';
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Snapshot not found: ' . $id);
        }
        return Snapshot::fromArray($data);
    }

    /**
     * @return Snapshot[]
     */
    public function findAll(): array
    {
        $result = [];
        foreach ((array) glob($this->rollbackDir . '/*/snapshot.json') as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $result[] = Snapshot::fromArray($data);
            }
        }
        usort($result, fn(Snapshot $a, Snapshot $b) => $b->createdAt() <=> $a->createdAt());
        return $result;
    }

    public function remove(Snapshot $snapshot): void
    {
        $dir = $this->rollbackDir . '/' . $snapshot->id();
        $this->rmTree($dir);
    }

    /**
     * Remove committed snapshots older than $maxAgeSeconds.
     * Pending snapshots are never auto-removed (they may still be needed for manual rollback).
     *
     * @return int Number of snapshots removed.
     */
    public function purgeCommittedOlderThan(int $maxAgeSeconds): int
    {
        $cutoff = time() - $maxAgeSeconds;
        $removed = 0;
        foreach ($this->findAll() as $snapshot) {
            if ($snapshot->status() !== Snapshot::STATUS_COMMITTED) continue;
            if ($snapshot->createdAt() >= $cutoff) continue;
            $this->remove($snapshot);
            $removed++;
        }
        return $removed;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
