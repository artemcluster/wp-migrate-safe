<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Header;
use WpMigrateSafe\Archive\Exception\CorruptedArchiveException;

final class HeaderTest extends TestCase
{
    public function testConstructAndAccessors(): void
    {
        $header = new Header('hello.txt', 123, 1700000000, 'subdir/deeper');

        $this->assertSame('hello.txt', $header->name());
        $this->assertSame(123, $header->size());
        $this->assertSame(1700000000, $header->mtime());
        $this->assertSame('subdir/deeper', $header->prefix());
    }

    public function testPathReturnsJoinedPrefixAndName(): void
    {
        $header = new Header('file.txt', 0, 0, 'a/b');
        $this->assertSame('a/b/file.txt', $header->path());
    }

    public function testPathWithEmptyPrefix(): void
    {
        $header = new Header('file.txt', 0, 0, '');
        $this->assertSame('file.txt', $header->path());
    }

    public function testPackProducesExactly4377Bytes(): void
    {
        $header = new Header('hello.txt', 123, 1700000000, 'subdir');
        $packed = $header->pack();
        $this->assertSame(4377, strlen($packed));
    }

    public function testPackIsCompatibleWithAi1wmFormat(): void
    {
        // Reproduce the exact byte layout ai1wm uses:
        // pack('a255a14a12a4088a8', $name, $size, $mtime, $prefix, $crc32)
        $header = new Header('hello.txt', 42, 1700000000, 'wp-content/uploads');

        $packed = $header->pack();
        $data = unpack('a255name/a14size/a12mtime/a4088prefix/a8crc32', $packed);

        $this->assertSame('hello.txt', rtrim($data['name'], "\0"));
        $this->assertSame('42', rtrim($data['size'], "\0"));
        $this->assertSame('1700000000', rtrim($data['mtime'], "\0"));
        $this->assertSame('wp-content/uploads', rtrim($data['prefix'], "\0"));
        $this->assertSame('', rtrim($data['crc32'], "\0"));
    }

    public function testUnpackRoundTrip(): void
    {
        $original = new Header('unicode_файл.txt', 1024, 1700000000, 'uploads/2024');
        $packed = $original->pack();
        $parsed = Header::unpack($packed);

        $this->assertSame($original->name(), $parsed->name());
        $this->assertSame($original->size(), $parsed->size());
        $this->assertSame($original->mtime(), $parsed->mtime());
        $this->assertSame($original->prefix(), $parsed->prefix());
    }

    public function testUnpackThrowsOnWrongBlockSize(): void
    {
        $this->expectException(CorruptedArchiveException::class);
        Header::unpack(str_repeat('x', 100));
    }

    public function testIsEofBlockReturnsTrueForAllNullV1(): void
    {
        $nulls = str_repeat("\0", 4377);
        $this->assertTrue(Header::isEofBlock($nulls));
    }

    public function testIsEofBlockReturnsTrueForV2WithCrc(): void
    {
        // v2 EOF: size field = archive size (decimal), crc32 field = 8 hex chars
        $block = pack('a255a14a4100a8', '', '1234567890', '', 'deadbeef');
        $this->assertTrue(Header::isEofBlock($block));
    }

    public function testIsEofBlockReturnsFalseForRegularFileHeader(): void
    {
        $header = new Header('file.txt', 100, 1700000000, 'wp-content');
        $this->assertFalse(Header::isEofBlock($header->pack()));
    }

    public function testConstructorRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Header('', 0, 0, 'somewhere');
    }

    public function testConstructorRejectsNegativeSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Header('file.txt', -1, 0, '');
    }

    public function testConstructorRejectsNameLongerThan255Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Header(str_repeat('a', 256), 0, 0, '');
    }

    public function testConstructorRejectsPrefixLongerThan4088Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Header('file.txt', 0, 0, str_repeat('a', 4089));
    }
}
