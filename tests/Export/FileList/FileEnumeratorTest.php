<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Export\FileList;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Export\FileList\FileEnumerator;

final class FileEnumeratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpms_fe_' . uniqid();
        mkdir($this->root, 0755, true);
        mkdir($this->root . '/plugin-a');
        mkdir($this->root . '/plugin-b');
        file_put_contents($this->root . '/plugin-a/main.php', 'a');
        file_put_contents($this->root . '/plugin-a/readme.txt', 'b');
        file_put_contents($this->root . '/plugin-b/main.php', 'c');
        mkdir($this->root . '/plugin-a/cache');
        file_put_contents($this->root . '/plugin-a/cache/file.tmp', 'tmp');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testEnumerateAllFiles(): void
    {
        $e = new FileEnumerator($this->root);
        $files = iterator_to_array($e->iterate(), false);
        sort($files);

        $this->assertContains('plugin-a/main.php', $files);
        $this->assertContains('plugin-a/readme.txt', $files);
        $this->assertContains('plugin-a/cache/file.tmp', $files);
        $this->assertContains('plugin-b/main.php', $files);
        $this->assertCount(4, $files);
    }

    public function testExcludesDirectoryPattern(): void
    {
        $e = new FileEnumerator($this->root, ['cache']);
        $files = iterator_to_array($e->iterate(), false);

        $this->assertNotContains('plugin-a/cache/file.tmp', $files);
        $this->assertContains('plugin-a/main.php', $files);
    }

    public function testSkipsSymbolicLinks(): void
    {
        symlink($this->root . '/plugin-a', $this->root . '/linked');

        $e = new FileEnumerator($this->root);
        $files = iterator_to_array($e->iterate(), false);

        // Links themselves are skipped; their targets remain discoverable via original path.
        $this->assertNotContains('linked/main.php', $files);
    }

    private function rmrf(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (scandir($d) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $d . '/' . $e;
            if (is_link($p)) { @unlink($p); continue; }
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($d);
    }
}
