<?php
declare(strict_types=1);

namespace WpMigrateSafe\Archive;

use InvalidArgumentException;
use WpMigrateSafe\Archive\Exception\CorruptedArchiveException;

/**
 * Immutable value object representing the 4377-byte header block preceding
 * each file inside a .wpress archive. Format is byte-compatible with ai1wm.
 */
final class Header
{
    public const HEADER_SIZE = 4377;

    private const NAME_LENGTH = 255;
    private const SIZE_LENGTH = 14;
    private const MTIME_LENGTH = 12;
    private const PREFIX_LENGTH = 4088;
    private const CRC32_LENGTH = 8;

    private string $name;
    private int $size;
    private int $mtime;
    private string $prefix;

    public function __construct(string $name, int $size, int $mtime, string $prefix)
    {
        if ($name === '') {
            throw new InvalidArgumentException('File name cannot be empty.');
        }
        if (strlen($name) > self::NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'File name exceeds %d bytes: %s',
                self::NAME_LENGTH,
                $name
            ));
        }
        if ($size < 0) {
            throw new InvalidArgumentException('File size cannot be negative.');
        }
        if (strlen($prefix) > self::PREFIX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Prefix exceeds %d bytes: %s',
                self::PREFIX_LENGTH,
                $prefix
            ));
        }

        $this->name = $name;
        $this->size = $size;
        $this->mtime = $mtime;
        $this->prefix = $prefix;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function mtime(): int
    {
        return $this->mtime;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Full relative path inside the archive (prefix/name, forward slashes).
     */
    public function path(): string
    {
        if ($this->prefix === '') {
            return $this->name;
        }
        return $this->prefix . '/' . $this->name;
    }

    /**
     * Serialize to the 4377-byte binary header block.
     */
    public function pack(): string
    {
        return pack(
            'a' . self::NAME_LENGTH
            . 'a' . self::SIZE_LENGTH
            . 'a' . self::MTIME_LENGTH
            . 'a' . self::PREFIX_LENGTH
            . 'a' . self::CRC32_LENGTH,
            $this->name,
            (string) $this->size,
            (string) $this->mtime,
            $this->prefix,
            '' // crc32 is empty for file headers; only v2 EOF uses it
        );
    }

    /**
     * Parse a 4377-byte block into a Header.
     *
     * @throws CorruptedArchiveException if block size is wrong or fields invalid.
     */
    public static function unpack(string $block): self
    {
        if (strlen($block) !== self::HEADER_SIZE) {
            throw new CorruptedArchiveException(sprintf(
                'Header block must be exactly %d bytes, got %d.',
                self::HEADER_SIZE,
                strlen($block)
            ));
        }

        $data = unpack(
            'a' . self::NAME_LENGTH . 'name/'
            . 'a' . self::SIZE_LENGTH . 'size/'
            . 'a' . self::MTIME_LENGTH . 'mtime/'
            . 'a' . self::PREFIX_LENGTH . 'prefix/'
            . 'a' . self::CRC32_LENGTH . 'crc32',
            $block
        );

        if ($data === false) {
            throw new CorruptedArchiveException('Failed to unpack header block.');
        }

        $name = rtrim($data['name'], "\0");
        $sizeStr = rtrim($data['size'], "\0");
        $mtimeStr = rtrim($data['mtime'], "\0");
        $prefix = rtrim($data['prefix'], "\0");

        if ($name === '') {
            throw new CorruptedArchiveException('Header has empty file name.');
        }
        if ($sizeStr === '' || !ctype_digit($sizeStr)) {
            throw new CorruptedArchiveException(sprintf(
                'Header size field is not a decimal integer: %s',
                bin2hex($sizeStr)
            ));
        }
        if ($mtimeStr !== '' && !ctype_digit($mtimeStr)) {
            throw new CorruptedArchiveException(sprintf(
                'Header mtime field is not a decimal integer: %s',
                bin2hex($mtimeStr)
            ));
        }

        return new self(
            $name,
            (int) $sizeStr,
            $mtimeStr === '' ? 0 : (int) $mtimeStr,
            $prefix
        );
    }

    /**
     * Check if a 4377-byte block is an end-of-file marker (v1 all-null or v2 with CRC).
     */
    public static function isEofBlock(string $block): bool
    {
        if (strlen($block) !== self::HEADER_SIZE) {
            return false;
        }

        // v1 EOF: all zero bytes.
        if ($block === str_repeat("\0", self::HEADER_SIZE)) {
            return true;
        }

        // v2 EOF: name/mtime/prefix are empty, size is a decimal string, crc32 is 8 hex chars.
        $data = unpack(
            'a' . self::NAME_LENGTH . 'name/'
            . 'a' . self::SIZE_LENGTH . 'size/'
            . 'a' . (self::MTIME_LENGTH + self::PREFIX_LENGTH) . 'middle/'
            . 'a' . self::CRC32_LENGTH . 'crc32',
            $block
        );

        if ($data === false) {
            return false;
        }

        if (rtrim($data['name'], "\0") !== '' || rtrim($data['middle'], "\0") !== '') {
            return false;
        }

        $crc = rtrim($data['crc32'], "\0");
        $sizeStr = rtrim($data['size'], "\0");

        if ($crc === '' || $sizeStr === '') {
            return false;
        }

        return (bool) preg_match('/^[0-9a-f]{8}$/i', $crc) && ctype_digit($sizeStr);
    }
}
