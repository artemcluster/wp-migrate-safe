<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Archive\Writer;

final class RoundTripTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_rt_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testEmptyFile(): void
    {
        $this->assertRoundTrip([
            ['name' => 'empty.txt', 'prefix' => '', 'content' => ''],
        ]);
    }

    public function testMultipleSmallFiles(): void
    {
        $this->assertRoundTrip([
            ['name' => 'a.txt', 'prefix' => '', 'content' => 'A'],
            ['name' => 'b.txt', 'prefix' => 'dir', 'content' => str_repeat('B', 1024)],
            ['name' => 'c.txt', 'prefix' => 'dir/sub', 'content' => str_repeat('C', 999999)],
        ]);
    }

    public function testBinaryContent(): void
    {
        $binary = '';
        for ($i = 0; $i < 256; $i++) {
            $binary .= chr($i);
        }
        $this->assertRoundTrip([
            ['name' => 'binary.bin', 'prefix' => 'bins', 'content' => $binary],
        ]);
    }

    public function testUnicodePathsAndContent(): void
    {
        $this->assertRoundTrip([
            ['name' => 'файл.txt', 'prefix' => 'uploads/тека', 'content' => 'український текст'],
            ['name' => '日本語.txt', 'prefix' => 'uploads/日本', 'content' => 'こんにちは'],
            ['name' => 'emoji🔥.txt', 'prefix' => 'fun', 'content' => '🎉🚀'],
        ]);
    }

    public function testFileLargerThanReadChunk(): void
    {
        // READ_CHUNK_SIZE is 1 MB in Reader/Writer; use 3.5 MB to exercise multi-chunk logic.
        $content = random_bytes(3 * 1024 * 1024 + 500000);
        $this->assertRoundTrip([
            ['name' => 'big.bin', 'prefix' => 'large', 'content' => $content],
        ]);
    }

    public function testCrcVerificationPasses(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'a.txt', 'prefix' => '', 'content' => 'AAAA'],
            ['name' => 'b.txt', 'prefix' => 'sub', 'content' => str_repeat('B', 100000)],
        ]);

        $reader = new Reader($archive);
        $this->assertTrue($reader->verifyCrc(), 'v2 EOF CRC should match file contents.');
    }

    public function testCrcDetectsCorruption(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'a.txt', 'prefix' => '', 'content' => str_repeat('A', 1000)],
        ]);

        // Flip one byte somewhere in the middle of the content region.
        $fp = fopen($archive, 'r+b');
        fseek($fp, 4377 + 500, SEEK_SET);
        fwrite($fp, 'X');
        fclose($fp);

        $reader = new Reader($archive);
        $this->assertFalse($reader->verifyCrc(), 'Corruption must be detected by CRC.');
    }

    // ---------- helpers ----------

    /**
     * @param array<int, array{name: string, prefix: string, content: string}> $entries
     */
    private function assertRoundTrip(array $entries): void
    {
        $archive = $this->buildArchive($entries);
        $dest = $this->dir . '/extract_' . uniqid();
        mkdir($dest, 0755, true);

        $reader = new Reader($archive);
        $count = $reader->extractAll($dest);
        $this->assertSame(count($entries), $count);

        foreach ($entries as $entry) {
            $path = $dest . '/' . ($entry['prefix'] !== '' ? $entry['prefix'] . '/' : '') . $entry['name'];
            $this->assertFileExists($path);
            $this->assertSame($entry['content'], file_get_contents($path), 'Content mismatch for ' . $path);
        }
    }

    /**
     * @param array<int, array{name: string, prefix: string, content: string}> $entries
     */
    private function buildArchive(array $entries): string
    {
        $archive = $this->dir . '/archive_' . uniqid() . '.wpress';
        $writer = new Writer($archive);
        foreach ($entries as $i => $entry) {
            $src = $this->dir . '/src_' . $i . '_' . uniqid() . '.bin';
            file_put_contents($src, $entry['content']);
            $writer->appendFile($src, $entry['name'], $entry['prefix']);
        }
        $writer->close();
        return $archive;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
