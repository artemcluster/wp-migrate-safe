<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Header;
use WpMigrateSafe\Archive\Writer;
use WpMigrateSafe\Archive\Exception\NotWritableException;

final class WriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_writer_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testEmptyArchiveHasOnlyEofBlock(): void
    {
        $archive = $this->dir . '/empty.wpress';
        $writer = new Writer($archive);
        $writer->close();

        $this->assertSame(Header::HEADER_SIZE, filesize($archive));

        $block = file_get_contents($archive);
        $this->assertTrue(Header::isEofBlock($block));
    }

    public function testAppendSingleFile(): void
    {
        $source = $this->dir . '/hello.txt';
        file_put_contents($source, 'Hello, world!');

        $archive = $this->dir . '/single.wpress';
        $writer = new Writer($archive);
        $writer->appendFile($source, 'hello.txt', 'greetings');
        $writer->close();

        // Layout: 1 header (4377) + 13 bytes content + 1 EOF header (4377)
        $this->assertSame(Header::HEADER_SIZE + 13 + Header::HEADER_SIZE, filesize($archive));

        $handle = fopen($archive, 'rb');
        try {
            $headerBlock = fread($handle, Header::HEADER_SIZE);
            $header = Header::unpack($headerBlock);

            $this->assertSame('hello.txt', $header->name());
            $this->assertSame(13, $header->size());
            $this->assertSame('greetings', $header->prefix());

            $content = fread($handle, 13);
            $this->assertSame('Hello, world!', $content);

            $eof = fread($handle, Header::HEADER_SIZE);
            $this->assertTrue(Header::isEofBlock($eof));
        } finally {
            fclose($handle);
        }
    }

    public function testAppendMultipleFiles(): void
    {
        $a = $this->dir . '/a.txt';
        $b = $this->dir . '/b.txt';
        file_put_contents($a, 'AAAAA');
        file_put_contents($b, 'BBBBBBBBBB');

        $archive = $this->dir . '/multi.wpress';
        $writer = new Writer($archive);
        $writer->appendFile($a, 'a.txt', '');
        $writer->appendFile($b, 'b.txt', 'sub');
        $writer->close();

        $this->assertSame(
            Header::HEADER_SIZE + 5 + Header::HEADER_SIZE + 10 + Header::HEADER_SIZE,
            filesize($archive)
        );
    }

    public function testEofBlockIsV2WithCrc(): void
    {
        $archive = $this->dir . '/crc.wpress';
        $source = $this->dir . '/x.txt';
        file_put_contents($source, 'data');

        $writer = new Writer($archive);
        $writer->appendFile($source, 'x.txt', '');
        $writer->close();

        // Read the EOF block (last 4377 bytes).
        $handle = fopen($archive, 'rb');
        fseek($handle, -Header::HEADER_SIZE, SEEK_END);
        $eof = fread($handle, Header::HEADER_SIZE);
        fclose($handle);

        // The v2 EOF is an EOF block AND contains a valid CRC hex string in its crc32 field.
        $this->assertTrue(Header::isEofBlock($eof));

        $data = unpack('a255/a14size/a4100/a8crc32', $eof);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/i', rtrim($data['crc32'], "\0"));
    }

    public function testThrowsWhenTargetNotWritable(): void
    {
        $this->expectException(NotWritableException::class);
        new Writer('/this/path/does/not/exist/at/all/archive.wpress');
    }

    public function testAppendFileThrowsWhenSourceMissing(): void
    {
        $archive = $this->dir . '/missing.wpress';
        $writer = new Writer($archive);

        $this->expectException(NotWritableException::class);
        $writer->appendFile($this->dir . '/does-not-exist', 'x.txt', '');
    }

    public function testAppendWithUnicodePath(): void
    {
        $source = $this->dir . '/файл.txt';
        file_put_contents($source, 'ukrainian');

        $archive = $this->dir . '/unicode.wpress';
        $writer = new Writer($archive);
        $writer->appendFile($source, 'файл.txt', 'uploads/тека');
        $writer->close();

        $handle = fopen($archive, 'rb');
        $headerBlock = fread($handle, Header::HEADER_SIZE);
        fclose($handle);

        $header = Header::unpack($headerBlock);
        $this->assertSame('файл.txt', $header->name());
        $this->assertSame('uploads/тека', $header->prefix());
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
