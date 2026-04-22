<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Snapshot;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;

final class SnapshotStoreTest extends TestCase
{
    private string $dir;
    private SnapshotStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_snap_' . uniqid();
        mkdir($this->dir, 0755, true);
        $this->store = new SnapshotStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    private function makeSnapshot(?string $id = null): Snapshot
    {
        $id = $id ?? bin2hex(random_bytes(16));
        return new Snapshot(
            $id,
            time(),
            '/var/www/html/_rollback/' . $id . '/database.sql',
            '/var/www/html/wp-content/plugins.rollback.' . $id,
            '/var/www/html/wp-content/themes.rollback.' . $id,
            '/var/www/html/wp-content/uploads.rollback.' . $id,
            Snapshot::STATUS_PENDING
        );
    }

    public function testSaveLoadRoundTrip(): void
    {
        $snap = $this->makeSnapshot();
        $this->store->save($snap);
        $loaded = $this->store->load($snap->id());
        $this->assertSame($snap->toArray(), $loaded->toArray());
    }

    public function testLoadThrowsForMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->load(bin2hex(random_bytes(16)));
    }

    public function testRollbackDirAccessor(): void
    {
        $this->assertSame($this->dir, $this->store->rollbackDir());
    }

    public function testFindAllReturnsSortedNewestFirst(): void
    {
        $older = new Snapshot(bin2hex(random_bytes(16)), 1000, '/a', '/b', '/c', '/d', Snapshot::STATUS_COMMITTED);
        $newer = new Snapshot(bin2hex(random_bytes(16)), 2000, '/a', '/b', '/c', '/d', Snapshot::STATUS_COMMITTED);

        $this->store->save($older);
        $this->store->save($newer);

        $all = $this->store->findAll();

        $this->assertCount(2, $all);
        $this->assertSame(2000, $all[0]->createdAt());
        $this->assertSame(1000, $all[1]->createdAt());
    }

    public function testPurgeCommittedOldOnly(): void
    {
        $oldCommitted = new Snapshot(bin2hex(random_bytes(16)), time() - 8 * 86400, '/a', '/b', '/c', '/d', Snapshot::STATUS_COMMITTED);
        $oldPending = new Snapshot(bin2hex(random_bytes(16)), time() - 8 * 86400, '/a', '/b', '/c', '/d', Snapshot::STATUS_PENDING);
        $newCommitted = new Snapshot(bin2hex(random_bytes(16)), time(), '/a', '/b', '/c', '/d', Snapshot::STATUS_COMMITTED);

        $this->store->save($oldCommitted);
        $this->store->save($oldPending);
        $this->store->save($newCommitted);

        $removed = $this->store->purgeCommittedOlderThan(7 * 86400);
        $this->assertSame(1, $removed);
        $this->assertCount(2, $this->store->findAll());
    }

    private function rmrf(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (scandir($d) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $d . '/' . $e;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($d);
    }
}
