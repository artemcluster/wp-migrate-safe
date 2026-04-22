<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Snapshot;

use WP_UnitTestCase;
use WpMigrateSafe\Import\Snapshot\Snapshot;
use WpMigrateSafe\Import\Snapshot\SnapshotCreator;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;

/**
 * Run with: ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class SnapshotCreatorTest extends WP_UnitTestCase
{
    private string $rollbackDir;
    private string $origPlugins;
    private string $origThemes;
    private string $origUploads;

    protected function set_up(): void
    {
        parent::set_up();
        $this->rollbackDir = sys_get_temp_dir() . '/wpms_rb_' . uniqid();
        mkdir($this->rollbackDir);

        $wpContent = WP_CONTENT_DIR;
        $this->origPlugins = $wpContent . '/plugins';
        $this->origThemes = $wpContent . '/themes';
        $this->origUploads = $wpContent . '/uploads';

        // Ensure uploads exists (plugins/themes always exist in WP test env).
        if (!is_dir($this->origUploads)) mkdir($this->origUploads);
    }

    public function testCreateProducesValidSnapshotAndRenamesDirs(): void
    {
        $store = new SnapshotStore($this->rollbackDir);
        $creator = new SnapshotCreator($store, WP_CONTENT_DIR);
        $snapshot = $creator->create();

        $this->assertFileExists($snapshot->dbDumpPath());
        $this->assertDirectoryExists($snapshot->pluginsRollbackPath());
        $this->assertDirectoryExists($snapshot->themesRollbackPath());
        $this->assertDirectoryExists($snapshot->uploadsRollbackPath());
        $this->assertDirectoryDoesNotExist($this->origPlugins);

        $this->assertSame(Snapshot::STATUS_PENDING, $snapshot->status());

        // Rename back so the test suite doesn't break subsequent tests.
        rename($snapshot->pluginsRollbackPath(), $this->origPlugins);
        rename($snapshot->themesRollbackPath(), $this->origThemes);
        if (is_dir($snapshot->uploadsRollbackPath())) {
            rename($snapshot->uploadsRollbackPath(), $this->origUploads);
        }
    }
}
