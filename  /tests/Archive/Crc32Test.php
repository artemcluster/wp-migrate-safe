<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Crc32;
use WpMigrateSafe\Archive\Exception\NotReadableException;

final class Crc32Test extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'crc32_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmp)) {
            unlink($this->tmp);
        }
    }

    public function testEmptyFileReturnsCrc32OfEmptyString(): void
    {
        file_put_contents($this->tmp, '');
        $this->assertSame('00000000', Crc32::ofFile($this->tmp));
    }

    public function testKnownCrc32ForShortString(): void
    {
        // "hello" -> 3610a686 per standard CRC32 (IEEE 802.3 polynomial)
        file_put_contents($this->tmp, 'hello');
        $this->assertSame('3610a686', Crc32::ofFile($this->tmp));
    }

    public function testStreamingOverLargerThanOneChunk(): void
    {
        // Write 2 MB of deterministic content; compare streaming vs hash_file.
        $content = str_repeat('abcdef1234567890', 131072); // 2 MB
        file_put_contents($this->tmp, $content);

        $expected = hash_file('crc32b', $this->tmp);
        $this->assertSame($expected, Crc32::ofFile($this->tmp));
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(NotReadableException::class);
        Crc32::ofFile('/nonexistent/path/to/nowhere-' . uniqid());
    }
}
