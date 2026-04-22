<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Header;
use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Archive\Writer;

/**
 * Compatibility tests proving byte-level compatibility with ai1wm's archive format.
 *
 * Reference source (ai1wm): lib/vendor/servmask/archiver/class-ai1wm-archiver.php
 * Header block format: pack('a255a14a12a4088a8', name, size, mtime, prefix, crc32)
 * v1 EOF: 4377 NUL bytes
 * v2 EOF: pack('a255a14a4100a8', '', archive_size, '', crc32_hex)
 */
final class Ai1wmCompatibilityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_ai1wm_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testOurReaderParsesAi1wmStyleHeader(): void
    {
        // Build an archive byte-for-byte the way ai1wm would.
        $archive = $this->dir . '/ai1wm-style.wpress';
        $handle = fopen($archive, 'wb');

        $header = pack(
            'a255a14a12a4088a8',
            'wp-config.php',
            (string) 1234,
            (string) 1700000000,
            'wp-content',
            '' // file headers have empty crc32 field
        );
        fwrite($handle, $header);
        fwrite($handle, str_repeat('x', 1234));

        // v1 EOF (ai1wm still writes these on some code paths)
        fwrite($handle, str_repeat("\0", 4377));
        fclose($handle);

        $reader = new Reader($archive);
        $entries = iterator_to_array($reader->listFiles());

        $this->assertCount(1, $entries);
        [$h, $offset] = $entries[0];

        $this->assertSame('wp-config.php', $h->name());
        $this->assertSame(1234, $h->size());
        $this->assertSame(1700000000, $h->mtime());
        $this->assertSame('wp-content', $h->prefix());
        $this->assertSame('wp-content/wp-config.php', $h->path());
        $this->assertSame(4377, $offset);
    }

    public function testOurReaderAcceptsBothV1AndV2Eof(): void
    {
        // Same single entry, two different EOF styles.
        $v1Archive = $this->writeArchiveWithEof(false);
        $v2Archive = $this->writeArchiveWithEof(true);

        foreach ([$v1Archive, $v2Archive] as $path) {
            $reader = new Reader($path);
            $this->assertTrue($reader->isValid(), 'Valid EOF should be recognized: ' . $path);

            $entries = iterator_to_array($reader->listFiles());
            $this->assertCount(1, $entries, 'Should find one entry in: ' . $path);
        }
    }

    public function testOurWriterOutputMatchesAi1wmPackCall(): void
    {
        $source = $this->dir . '/hello.txt';
        file_put_contents($source, 'Hello, world!');
        $mtime = filemtime($source);

        $archive = $this->dir . '/ours.wpress';
        $writer = new Writer($archive);
        $writer->appendFile($source, 'hello.txt', 'greetings');
        $writer->close();

        $handle = fopen($archive, 'rb');
        $headerBlock = fread($handle, Header::HEADER_SIZE);
        fclose($handle);

        // The first 4377 bytes should be exactly what ai1wm would pack().
        $expected = pack(
            'a255a14a12a4088a8',
            'hello.txt',
            '13',
            (string) $mtime,
            'greetings',
            ''
        );

        $this->assertSame(bin2hex($expected), bin2hex($headerBlock));
    }

    public function testOurWriterProducesValidV2Eof(): void
    {
        $source = $this->dir . '/a.txt';
        file_put_contents($source, str_repeat('A', 1000));

        $archive = $this->dir . '/v2.wpress';
        $writer = new Writer($archive);
        $writer->appendFile($source, 'a.txt', '');
        $writer->close();

        // Last 4377 bytes = v2 EOF; unpack exactly like ai1wm does.
        $handle = fopen($archive, 'rb');
        fseek($handle, -Header::HEADER_SIZE, SEEK_END);
        $eof = fread($handle, Header::HEADER_SIZE);
        fclose($handle);

        $data = unpack('a255/a14size/a4100/a8crc32', $eof);
        $size = (int) rtrim($data['size'], "\0");
        $crc = rtrim($data['crc32'], "\0");

        // ai1wm expects archive_crc_size = file bytes BEFORE the EOF.
        $this->assertSame(4377 + 1000, $size);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/i', $crc);

        // The CRC must match actual content: hash the first $size bytes and compare.
        $handle = fopen($archive, 'rb');
        $content = fread($handle, $size);
        fclose($handle);
        $this->assertSame($crc, hash('crc32b', $content));
    }

    // ---------- helpers ----------

    private function writeArchiveWithEof(bool $v2): string
    {
        $archive = $this->dir . '/' . ($v2 ? 'v2' : 'v1') . '_' . uniqid() . '.wpress';
        $handle = fopen($archive, 'wb');

        $header = pack('a255a14a12a4088a8', 'x.txt', '5', '1700000000', '', '');
        fwrite($handle, $header);
        fwrite($handle, 'xxxxx');

        if ($v2) {
            $preEofSize = ftell($handle);
            fflush($handle);
            fclose($handle);
            $crc = hash_file('crc32b', $archive);
            $handle = fopen($archive, 'ab');
            $eof = pack('a255a14a4100a8', '', (string) $preEofSize, '', $crc);
            fwrite($handle, $eof);
        } else {
            fwrite($handle, str_repeat("\0", 4377));
        }

        fclose($handle);
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
