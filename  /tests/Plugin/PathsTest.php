<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Plugin\Paths;

/**
 * Paths tests that don't require a real WordPress environment.
 *
 * We stub the minimal WP constants + functions that Paths touches.
 */
final class PathsTest extends TestCase
{
    private string $tmpWpContent;

    public static function setUpBeforeClass(): void
    {
        // Define constants expected by Paths.
        // WP function stubs (trailingslashit, wp_mkdir_p) are defined in tests/bootstrap.php.
        if (!defined('WPMS_BACKUPS_SUBDIR')) {
            define('WPMS_BACKUPS_SUBDIR', 'backups/wp-migrate-safe');
        }
    }

    protected function setUp(): void
    {
        $this->tmpWpContent = sys_get_temp_dir() . '/wpms_paths_' . uniqid();
        mkdir($this->tmpWpContent, 0755, true);

        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpWpContent);
        }
        // If the constant was already defined by a prior test run, point test files at it.
        if (WP_CONTENT_DIR !== $this->tmpWpContent) {
            $this->tmpWpContent = WP_CONTENT_DIR;
        }
    }

    protected function tearDown(): void
    {
        // Clean up only the folders we created under WP_CONTENT_DIR.
        $this->removeDir(WP_CONTENT_DIR . '/' . WPMS_BACKUPS_SUBDIR);
    }

    public function testBackupsDirIsCreatedUnderWpContent(): void
    {
        $dir = Paths::backupsDir();
        $this->assertDirectoryExists($dir);
        $this->assertStringStartsWith(WP_CONTENT_DIR, $dir);
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertFileExists($dir . '/index.php');
    }

    public function testUploadsTmpDirIsCreatedInsideBackups(): void
    {
        $dir = Paths::uploadsTmpDir();
        $this->assertDirectoryExists($dir);
        $this->assertStringEndsWith('/_uploads', $dir);
    }

    public function testUploadSessionDirRequiresHex32Id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Paths::uploadSessionDir('not-a-valid-id');
    }

    public function testUploadSessionDirAcceptsValidId(): void
    {
        $id = bin2hex(random_bytes(16));
        $dir = Paths::uploadSessionDir($id);
        $this->assertDirectoryExists($dir);
        $this->assertStringEndsWith('/' . $id, $dir);
    }

    public function testFreeDiskBytesReturnsIntOrNull(): void
    {
        $free = Paths::freeDiskBytes();
        $this->assertTrue($free === null || is_int($free));
        if ($free !== null) {
            $this->assertGreaterThanOrEqual(0, $free);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
