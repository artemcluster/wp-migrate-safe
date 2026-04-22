<?php
declare(strict_types=1);

namespace WpMigrateSafe\Archive;

use WpMigrateSafe\Archive\Exception\NotReadableException;

/**
 * Streaming CRC32 calculator for archive files.
 *
 * Uses the IEEE 802.3 polynomial (same as PHP's `crc32()` and `hash('crc32b')`),
 * producing a lowercase 8-character hex string compatible with the ai1wm v2 EOF block.
 */
final class Crc32
{
    private const CHUNK_SIZE = 1024 * 1024; // 1 MB

    /**
     * Compute CRC32 of a file's contents without loading it fully into memory.
     */
    public static function ofFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new NotReadableException(sprintf('File is not readable: %s', $path));
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new NotReadableException(sprintf('Could not open file for reading: %s', $path));
        }

        try {
            $ctx = hash_init('crc32b');
            hash_update_stream($ctx, $handle, -1);
            return hash_final($ctx);
        } finally {
            fclose($handle);
        }
    }
}
