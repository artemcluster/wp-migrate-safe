<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Rollback;

use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Rollback\RollbackExecutor;
use WpMigrateSafe\Rollback\RollbackFailedException;

/**
 * WP-Integration test — requires WP_TESTS_DIR and a live MySQL connection.
 * DO NOT add to phpunit.xml.dist unit suite.
 *
 * @group wp-integration
 */
final class RollbackExecutorTest extends \WP_UnitTestCase
{
    private string $tmpDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/wpms_rollback_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        foreach ((array) glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testRollbackRestoresDatabaseFromDump(): void
    {
        global $wpdb;

        // Create a simple test table and dump it.
        $table  = $wpdb->prefix . 'wpms_rollback_test';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$table}` (id INT PRIMARY KEY, val VARCHAR(100))");
        $wpdb->insert($table, ['id' => 1, 'val' => 'original']);

        // Build a minimal SQL dump manually.
        $dumpPath = $this->tmpDir . '/dump.sql';
        $sql = "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= "CREATE TABLE `{$table}` (id INT PRIMARY KEY, val VARCHAR(100));\n";
        $sql .= "INSERT INTO `{$table}` VALUES (1, 'original');\n";
        file_put_contents($dumpPath, $sql);

        // Mutate the table as if an import happened.
        $wpdb->update($table, ['val' => 'modified'], ['id' => 1]);
        $this->assertSame('modified', $wpdb->get_var("SELECT val FROM `{$table}` WHERE id=1"));

        // Rollback.
        $snapshot = new Snapshot('snap-test-rb', time(), [], $dumpPath, $wpdb->prefix);
        $executor = new RollbackExecutor(WP_CONTENT_DIR);
        $executor->rollback($snapshot);

        // Value should be restored.
        $this->assertSame('original', $wpdb->get_var("SELECT val FROM `{$table}` WHERE id=1"));

        // Cleanup.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    public function testRollbackThrowsWhenDumpMissing(): void
    {
        $this->expectException(RollbackFailedException::class);

        $snapshot = new Snapshot('snap-no-dump', time(), [], '/nonexistent/dump.sql', 'wp_');
        $executor = new RollbackExecutor(WP_CONTENT_DIR);
        $executor->rollback($snapshot);
    }
}
