<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Header;
use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Archive\Writer;
use WpMigrateSafe\Archive\Exception\CorruptedArchiveException;
use WpMigrateSafe\Archive\Exception\TruncatedArchiveException;
use WpMigrateSafe\Archive\Exception\NotReadableException;

final class ReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_reader_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testListFilesEmpty(): void
    {
        $archive = $this->dir . '/empty.wpress';
        (new Writer($archive))->close();

        $reader = new Reader($archive);
        $this->assertSame([], iterator_to_array($reader->listFiles()));
    }

    public function testListFilesSingleEntry(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'hello.txt', 'prefix' => 'greetings', 'content' => 'Hello'],
        ]);

        $reader = new Reader($archive);
        $entries = iterator_to_array($reader->listFiles());

        $this->assertCount(1, $entries);
        [$header, $offset] = $entries[0];

        $this->assertInstanceOf(Header::class, $header);
        $this->assertSame('hello.txt', $header->name());
        $this->assertSame(5, $header->size());
        $this->assertSame('greetings', $header->prefix());
        $this->assertSame(Header::HEADER_SIZE, $offset); // content starts after first header
    }

    public function testListFilesMultipleEntries(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'a.txt', 'prefix' => '', 'content' => 'AAAA'],
            ['name' => 'b.txt', 'prefix' => 'sub', 'content' => 'BBBBBBBB'],
            ['name' => 'c.txt', 'prefix' => 'sub/deeper', 'content' => 'CCC'],
        ]);

        $reader = new Reader($archive);
        $entries = iterator_to_array($reader->listFiles());

        $this->assertCount(3, $entries);
        $names = array_map(fn(array $e) => $e[0]->name(), $entries);
        $this->assertSame(['a.txt', 'b.txt', 'c.txt'], $names);

        // Second entry starts after: header + 4 bytes + header
        $this->assertSame(Header::HEADER_SIZE + 4 + Header::HEADER_SIZE, $entries[1][1]);
    }

    public function testExtractAllWritesAllFilesWithCorrectContent(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'a.txt', 'prefix' => '', 'content' => 'AAAA'],
            ['name' => 'nested.txt', 'prefix' => 'dir1/dir2', 'content' => 'nested content'],
        ]);

        $dest = $this->dir . '/extract';
        mkdir($dest, 0755, true);

        $reader = new Reader($archive);
        $count = $reader->extractAll($dest);

        $this->assertSame(2, $count);
        $this->assertSame('AAAA', file_get_contents($dest . '/a.txt'));
        $this->assertSame('nested content', file_get_contents($dest . '/dir1/dir2/nested.txt'));
    }

    public function testExtractSingleFileByHeader(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'only.txt', 'prefix' => 'sub', 'content' => 'target'],
        ]);

        $reader = new Reader($archive);
        $entries = iterator_to_array($reader->listFiles());
        [$header, $offset] = $entries[0];

        $dest = $this->dir . '/extract';
        mkdir($dest, 0755, true);
        $reader->extractFile($header, $offset, $dest);

        $this->assertSame('target', file_get_contents($dest . '/sub/only.txt'));
    }

    public function testExtractRefusesPathTraversal(): void
    {
        // Build an archive whose prefix contains '..' — reader must reject it.
        $archive = $this->dir . '/evil.wpress';
        $writer = new Writer($archive);
        $evilSource = $this->dir . '/evil.txt';
        file_put_contents($evilSource, 'malicious');
        // Inject '..' via prefix by constructing header manually.
        $w = fopen($archive, 'wb');
        $hdr = pack('a255a14a12a4088a8', 'evil.txt', '9', '1700000000', '../../outside', '');
        fwrite($w, $hdr);
        fwrite($w, 'malicious');
        fwrite($w, pack('a255a14a4100a8', '', '4386', '', '00000000'));
        fclose($w);

        $dest = $this->dir . '/extract';
        mkdir($dest, 0755, true);

        $reader = new Reader($archive);

        $this->expectException(CorruptedArchiveException::class);
        $reader->extractAll($dest);
    }

    public function testIsValidReturnsTrueForWriterOutput(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'a.txt', 'prefix' => '', 'content' => 'data'],
        ]);

        $reader = new Reader($archive);
        $this->assertTrue($reader->isValid());
    }

    public function testIsValidReturnsFalseForTruncatedFile(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'a.txt', 'prefix' => '', 'content' => 'AAAAAAAAAA'],
        ]);

        // Truncate: chop off the last 100 bytes, destroying the EOF block.
        $truncated = substr(file_get_contents($archive), 0, -100);
        file_put_contents($archive, $truncated);

        $reader = new Reader($archive);
        $this->assertFalse($reader->isValid());
    }

    public function testListFilesThrowsOnTruncatedContent(): void
    {
        // Header says size=1000, but file only has 50 bytes after the header.
        $archive = $this->dir . '/truncated.wpress';
        $w = fopen($archive, 'wb');
        $hdr = pack('a255a14a12a4088a8', 'a.txt', '1000', '1700000000', '', '');
        fwrite($w, $hdr);
        fwrite($w, str_repeat('x', 50));
        fclose($w);

        $reader = new Reader($archive);

        $this->expectException(TruncatedArchiveException::class);
        iterator_to_array($reader->listFiles());
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(NotReadableException::class);
        new Reader($this->dir . '/does-not-exist.wpress');
    }

    public function testZeroByteFileEntry(): void
    {
        // Entry with size=0 must still produce an empty extracted file.
        $archive = $this->buildArchive([
            ['name' => 'placeholder', 'prefix' => '', 'content' => ''],
        ]);

        $dest = $this->dir . '/extract';
        mkdir($dest, 0755, true);

        $reader = new Reader($archive);
        $count = $reader->extractAll($dest);

        $this->assertSame(1, $count);
        $this->assertFileExists($dest . '/placeholder');
        $this->assertSame(0, filesize($dest . '/placeholder'));
    }

    public function testListFilesOnCompletelyEmptyFileReturnsNothing(): void
    {
        $archive = $this->dir . '/zero-bytes.wpress';
        file_put_contents($archive, '');

        $reader = new Reader($archive);
        // A 0-byte file has no EOF block and no entries; listFiles should terminate cleanly.
        $this->assertSame([], iterator_to_array($reader->listFiles()));
        $this->assertFalse($reader->isValid());
    }

    public function testCorruptedHeaderRaisesException(): void
    {
        // Header block whose size field is non-numeric garbage.
        $archive = $this->dir . '/corrupt.wpress';
        $handle = fopen($archive, 'wb');
        fwrite($handle, pack('a255a14a12a4088a8', 'x.txt', 'NOT-A-NUMBER', '0', '', ''));
        fwrite($handle, str_repeat('x', 10));
        fwrite($handle, str_repeat("\0", 4377));
        fclose($handle);

        $reader = new Reader($archive);
        $this->expectException(CorruptedArchiveException::class);
        iterator_to_array($reader->listFiles());
    }

    public function testArchiveWithTwoFilesExtractionOrderMatchesListing(): void
    {
        $archive = $this->buildArchive([
            ['name' => 'first.txt', 'prefix' => '', 'content' => 'first content'],
            ['name' => 'second.txt', 'prefix' => 'sub', 'content' => 'second content'],
        ]);

        $dest = $this->dir . '/out';
        mkdir($dest, 0755, true);

        $reader = new Reader($archive);
        $reader->extractAll($dest);

        $this->assertSame('first content', file_get_contents($dest . '/first.txt'));
        $this->assertSame('second content', file_get_contents($dest . '/sub/second.txt'));
    }

    // ----------------- helpers -----------------

    /**
     * @param array<int, array{name: string, prefix: string, content: string}> $entries
     */
    private function buildArchive(array $entries): string
    {
        $archive = $this->dir . '/test_' . uniqid() . '.wpress';
        $writer = new Writer($archive);
        foreach ($entries as $i => $entry) {
            $src = $this->dir . '/src_' . $i . '.bin';
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
