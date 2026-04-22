<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Snapshot;

use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Import\Snapshot\SnapshotCreator;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;
use WpMigrateSafe\Plugin\Paths;

/**
 * WP-Integration test — requires WP_TESTS_DIR and a live MySQL connection.
 * DO NOT add to phpunit.xml.dist unit suite.
 *
 * @group wp-integration
 */
final class SnapshotCreatorTest extends \WP_UnitTestCase
{
    private string $rollbackDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->rollbackDir = sys_get_temp_dir() . '/wpms_snap_creator_' . uniqid();
        mkdir($this->rollbackDir, 0755, true);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        foreach ((array) glob($this->rollbackDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->rollbackDir);
    }

    public function testCreateProducesSnapshotWithDumpFile(): void
    {
        global $wpdb;

        $creator  = new SnapshotCreator($this->rollbackDir, WP_CONTENT_DIR, $wpdb->prefix);
        $snapshot = $creator->create();

        $this->assertInstanceOf(Snapshot::class, $snapshot);
        $this->assertFileExists($snapshot->sqlDumpPath());
        $this->assertGreaterThan(0, filesize($snapshot->sqlDumpPath()));
        $this->assertSame($wpdb->prefix, $snapshot->dbPrefix());
    }

    public function testSavedSnapshotCanBeLoaded(): void
    {
        global $wpdb;

        $creator  = new SnapshotCreator($this->rollbackDir, WP_CONTENT_DIR, $wpdb->prefix);
        $snapshot = $creator->create();

        $store  = new SnapshotStore($this->rollbackDir);
        $store->save($snapshot);
        $loaded = $store->load($snapshot->id());

        $this->assertSame($snapshot->id(), $loaded->id());
    }
}
