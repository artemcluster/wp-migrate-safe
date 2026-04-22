<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import;

use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Import\Snapshot\SnapshotCreator;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;
use WpMigrateSafe\Rollback\RollbackExecutor;

/**
 * WP-Integration E2E test for the rollback flow.
 *
 * Requires WP_TESTS_DIR and a live MySQL connection.
 * DO NOT add to phpunit.xml.dist unit suite.
 *
 * @group wp-integration
 */
final class RollbackTest extends \WP_UnitTestCase
{
    private string $tmpDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/wpms_rollback_e2e_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    /**
     * Full rollback flow:
     * 1. Create a snapshot (DB dump + content path list).
     * 2. Mutate the database (simulate import side-effect).
     * 3. Roll back using the snapshot.
     * 4. Assert database state is restored.
     */
    public function testRollbackRestoresDatabaseToPreImportState(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpms_e2e_rollback';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$table}` (id INT PRIMARY KEY, val VARCHAR(100))");
        $wpdb->insert($table, ['id' => 1, 'val' => 'before_import']);

        // Step 1: take snapshot.
        $creator  = new SnapshotCreator($this->tmpDir, WP_CONTENT_DIR, $wpdb->prefix);
        $snapshot = $creator->create();

        $store = new SnapshotStore($this->tmpDir);
        $store->save($snapshot);

        // Step 2: simulate import side-effect.
        $wpdb->update($table, ['val' => 'after_import'], ['id' => 1]);
        $this->assertSame('after_import', $wpdb->get_var("SELECT val FROM `{$table}` WHERE id=1"));

        // Step 3: rollback.
        $executor = new RollbackExecutor(WP_CONTENT_DIR);
        $executor->rollback($snapshot);

        // Step 4: assert state restored.
        $this->assertSame('before_import', $wpdb->get_var("SELECT val FROM `{$table}` WHERE id=1"));

        // Cleanup.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
