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
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function makeSnapshot(string $id = 'snap-abc123'): Snapshot
    {
        return new Snapshot(
            $id,
            1700000000,
            ['/var/www/html/wp-content/uploads'],
            '/var/www/html/wp-content/wpms-backups/_rollback/dump.sql',
            'wp_'
        );
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $snap = $this->makeSnapshot();
        $this->store->save($snap);

        $loaded = $this->store->load($snap->id());

        $this->assertSame($snap->id(), $loaded->id());
        $this->assertSame($snap->createdAt(), $loaded->createdAt());
        $this->assertSame($snap->contentPaths(), $loaded->contentPaths());
        $this->assertSame($snap->sqlDumpPath(), $loaded->sqlDumpPath());
        $this->assertSame($snap->dbPrefix(), $loaded->dbPrefix());
    }

    public function testLoadThrowsForMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->load('nonexistent-id-99');
    }

    public function testDeleteRemovesSnapshot(): void
    {
        $snap = $this->makeSnapshot('snap-to-delete');
        $this->store->save($snap);
        $this->store->delete($snap->id());

        $this->expectException(\RuntimeException::class);
        $this->store->load($snap->id());
    }

    public function testFindAllReturnsSortedNewestFirst(): void
    {
        $older = new Snapshot('snap-older-aaa', 1000, [], '/tmp/a.sql', 'wp_');
        $newer = new Snapshot('snap-newer-bbb', 2000, [], '/tmp/b.sql', 'wp_');

        $this->store->save($older);
        $this->store->save($newer);

        $all = $this->store->findAll();

        $this->assertCount(2, $all);
        $this->assertSame('snap-newer-bbb', $all[0]->id());
        $this->assertSame('snap-older-aaa', $all[1]->id());
    }
}
