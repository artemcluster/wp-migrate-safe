<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export;

use WpMigrateSafe\Archive\Crc32;
use WpMigrateSafe\Archive\Header;
use WpMigrateSafe\Archive\Exception\NotWritableException;

/**
 * Writer variant that APPENDS to a growing .wpress file across multiple HTTP requests.
 *
 * The file never has an EOF block until finalize() is called exactly once at the end.
 */
final class AppendingWriter
{
    private const READ_CHUNK_SIZE = 1024 * 1024;

    private string $archivePath;

    public function __construct(string $archivePath)
    {
        $this->archivePath = $archivePath;
        $dir = dirname($archivePath);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new NotWritableException('Archive directory not writable: ' . $dir);
        }
        if (!is_file($archivePath)) {
            // Create empty file.
            if (file_put_contents($archivePath, '') === false) {
                throw new NotWritableException('Could not create archive file: ' . $archivePath);
            }
        }
    }

    public function appendFile(string $sourcePath, string $nameInArchive, string $prefixInArchive): void
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new NotWritableException('Source file not readable: ' . $sourcePath);
        }

        $size = filesize($sourcePath);
        $mtime = filemtime($sourcePath);
        if ($size === false || $mtime === false) {
            throw new NotWritableException('Cannot stat source: ' . $sourcePath);
        }

        $header = new Header($nameInArchive, $size, $mtime, $prefixInArchive);

        $dest = @fopen($this->archivePath, 'ab');
        if ($dest === false) {
            throw new NotWritableException('Could not open archive for append: ' . $this->archivePath);
        }

        try {
            $this->writeAll($dest, $header->pack());

            $src = @fopen($sourcePath, 'rb');
            if ($src === false) {
                throw new NotWritableException('Could not open source: ' . $sourcePath);
            }

            try {
                while (!feof($src)) {
                    $chunk = fread($src, self::READ_CHUNK_SIZE);
                    if ($chunk === false) {
                        throw new NotWritableException('Read error on source: ' . $sourcePath);
                    }
                    if ($chunk === '') break;
                    $this->writeAll($dest, $chunk);
                }
            } finally {
                fclose($src);
            }
        } finally {
            fclose($dest);
        }
    }

    /**
     * Append a blob of bytes directly (used when dumping DB chunks).
     */
    public function appendBytes(string $nameInArchive, string $prefixInArchive, string $content): void
    {
        $header = new Header($nameInArchive, strlen($content), time(), $prefixInArchive);
        $dest = @fopen($this->archivePath, 'ab');
        if ($dest === false) {
            throw new NotWritableException('Could not open archive for append: ' . $this->archivePath);
        }
        try {
            $this->writeAll($dest, $header->pack());
            $this->writeAll($dest, $content);
        } finally {
            fclose($dest);
        }
    }

    public function finalize(): void
    {
        $archiveSize = filesize($this->archivePath);
        if ($archiveSize === false) {
            throw new NotWritableException('Cannot stat archive: ' . $this->archivePath);
        }

        $crc = Crc32::ofFile($this->archivePath);
        $eof = pack('a255a14a4100a8', '', (string) $archiveSize, '', $crc);

        $dest = @fopen($this->archivePath, 'ab');
        if ($dest === false) {
            throw new NotWritableException('Could not reopen archive to finalize.');
        }
        try {
            $this->writeAll($dest, $eof);
        } finally {
            fclose($dest);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        $written = @fwrite($handle, $bytes);
        if ($written !== strlen($bytes)) {
            throw new NotWritableException(sprintf(
                'Short write: %d/%d bytes.',
                $written === false ? 0 : $written,
                strlen($bytes)
            ));
        }
    }
}
