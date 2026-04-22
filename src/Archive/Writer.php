<?php
declare(strict_types=1);

namespace WpMigrateSafe\Archive;

use WpMigrateSafe\Archive\Exception\NotWritableException;

/**
 * Streaming writer for .wpress archives.
 *
 * Usage:
 *   $w = new Writer('/path/to/archive.wpress');
 *   $w->appendFile('/tmp/hello.txt', 'hello.txt', 'greetings');
 *   $w->appendFile('/tmp/wp-config.php', 'wp-config.php', '');
 *   $w->close();
 */
final class Writer
{
    private const READ_CHUNK_SIZE = 1024 * 1024; // 1 MB

    private string $archivePath;
    /** @var resource */
    private $handle;
    private bool $closed = false;

    public function __construct(string $archivePath)
    {
        $this->archivePath = $archivePath;

        $dir = dirname($archivePath);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new NotWritableException(sprintf(
                'Cannot write archive: directory does not exist or is not writable: %s',
                $dir
            ));
        }

        $handle = @fopen($archivePath, 'wb');
        if ($handle === false) {
            throw new NotWritableException(sprintf('Could not open archive for writing: %s', $archivePath));
        }
        $this->handle = $handle;
    }

    /**
     * Append a single file to the archive.
     *
     * @param string $sourcePath Filesystem path to the source file.
     * @param string $nameInArchive Filename stored inside the archive (no path).
     * @param string $prefixInArchive Directory prefix (forward slashes, no trailing slash).
     */
    public function appendFile(string $sourcePath, string $nameInArchive, string $prefixInArchive): void
    {
        $this->assertOpen();

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new NotWritableException(sprintf('Source file is not readable: %s', $sourcePath));
        }

        $size = filesize($sourcePath);
        $mtime = filemtime($sourcePath);
        if ($size === false || $mtime === false) {
            throw new NotWritableException(sprintf('Cannot stat source file: %s', $sourcePath));
        }

        $header = new Header($nameInArchive, $size, $mtime, $prefixInArchive);

        $this->writeAll($header->pack());

        $src = @fopen($sourcePath, 'rb');
        if ($src === false) {
            throw new NotWritableException(sprintf('Could not open source file for reading: %s', $sourcePath));
        }

        try {
            while (!feof($src)) {
                $chunk = fread($src, self::READ_CHUNK_SIZE);
                if ($chunk === false) {
                    throw new NotWritableException(sprintf('Error reading from source file: %s', $sourcePath));
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeAll($chunk);
            }
        } finally {
            fclose($src);
        }
    }

    /**
     * Finalize the archive: write the v2 EOF block with CRC32 of all prior bytes, then close the file handle.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $position = @ftell($this->handle);
        if ($position === false) {
            throw new NotWritableException('Could not determine archive size before writing EOF.');
        }

        // Flush and close so Crc32 can read the file from disk.
        if (@fflush($this->handle) === false) {
            throw new NotWritableException('Could not flush archive before writing EOF.');
        }
        fclose($this->handle);

        $crc = Crc32::ofFile($this->archivePath);

        // Re-open in append mode to write the EOF block.
        $handle = @fopen($this->archivePath, 'ab');
        if ($handle === false) {
            throw new NotWritableException(sprintf('Could not re-open archive to append EOF: %s', $this->archivePath));
        }

        $eof = $this->buildV2EofBlock($position, $crc);
        $written = @fwrite($handle, $eof);
        fclose($handle);

        if ($written !== strlen($eof)) {
            throw new NotWritableException('Short write while appending EOF block.');
        }

        // PHP caches stat results per request; without this, filesize() called
        // right after close() can return the stale pre-EOF size on PHP < 8.3.
        clearstatcache(true, $this->archivePath);

        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            // Best-effort cleanup if caller forgot to call close().
            @fclose($this->handle);
        }
    }

    private function writeAll(string $bytes): void
    {
        $this->assertOpen();
        $length = strlen($bytes);
        $written = @fwrite($this->handle, $bytes);
        if ($written !== $length) {
            throw new NotWritableException(sprintf(
                'Short write: expected %d bytes, wrote %s.',
                $length,
                $written === false ? 'false' : (string) $written
            ));
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new NotWritableException('Writer is already closed.');
        }
    }

    private function buildV2EofBlock(int $archiveSize, string $crc32Hex): string
    {
        // Matches ai1wm's get_eof_block(): pack('a255a14a4100a8', '', $size, '', $crc).
        // Total: 255 + 14 + 4100 + 8 = 4377.
        return pack('a255a14a4100a8', '', (string) $archiveSize, '', $crc32Hex);
    }
}
