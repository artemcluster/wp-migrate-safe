<?php
declare(strict_types=1);

namespace WpMigrateSafe\Archive;

use Generator;
use WpMigrateSafe\Archive\Exception\CorruptedArchiveException;
use WpMigrateSafe\Archive\Exception\NotReadableException;
use WpMigrateSafe\Archive\Exception\TruncatedArchiveException;

/**
 * Streaming reader for .wpress archives.
 *
 * Usage:
 *   $r = new Reader('/path/to/archive.wpress');
 *   foreach ($r->listFiles() as [$header, $contentOffset]) {
 *       $r->extractFile($header, $contentOffset, '/dest/dir');
 *   }
 */
final class Reader
{
    private const READ_CHUNK_SIZE = 1024 * 1024; // 1 MB

    private string $archivePath;

    public function __construct(string $archivePath)
    {
        if (!is_file($archivePath) || !is_readable($archivePath)) {
            throw new NotReadableException(sprintf('Archive file is not readable: %s', $archivePath));
        }
        $this->archivePath = $archivePath;
    }

    public function archivePath(): string
    {
        return $this->archivePath;
    }

    /**
     * Yield [Header, contentOffset] pairs for each file entry in the archive.
     *
     * @return Generator<int, array{0: Header, 1: int}>
     */
    public function listFiles(): Generator
    {
        $handle = $this->openForReading();
        $archiveSize = filesize($this->archivePath);

        try {
            while (true) {
                $headerStart = ftell($handle);
                if ($headerStart === false) {
                    throw new CorruptedArchiveException('ftell failed while iterating archive.');
                }

                $block = fread($handle, Header::HEADER_SIZE);
                if ($block === false) {
                    throw new CorruptedArchiveException('Read error while iterating archive.');
                }
                if ($block === '') {
                    return; // clean EOF of file
                }
                if (strlen($block) !== Header::HEADER_SIZE) {
                    throw new TruncatedArchiveException(sprintf(
                        'Incomplete header at offset %d (got %d of %d bytes).',
                        $headerStart,
                        strlen($block),
                        Header::HEADER_SIZE
                    ));
                }

                if (Header::isEofBlock($block)) {
                    return;
                }

                $header = Header::unpack($block);
                $contentOffset = $headerStart + Header::HEADER_SIZE;

                // Verify content bytes exist before skipping past them.
                if ($contentOffset + $header->size() > $archiveSize) {
                    throw new TruncatedArchiveException(sprintf(
                        'Archive truncated: entry "%s" needs %d bytes at offset %d but archive is only %d bytes.',
                        $header->path(),
                        $header->size(),
                        $contentOffset,
                        $archiveSize
                    ));
                }

                yield [$header, $contentOffset];

                if (fseek($handle, $header->size(), SEEK_CUR) === -1) {
                    throw new CorruptedArchiveException(sprintf(
                        'Failed to skip %d content bytes at offset %d.',
                        $header->size(),
                        $contentOffset
                    ));
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extract a single file to a destination directory.
     *
     * @param Header $header
     * @param int    $contentOffset Byte offset of the file contents inside the archive.
     * @param string $destDir       Destination root directory (must exist and be writable).
     */
    public function extractFile(Header $header, int $contentOffset, string $destDir): void
    {
        $targetPath = $this->resolveSafeTargetPath($header, $destDir);
        $this->ensureDirectory(dirname($targetPath));

        $src = $this->openForReading();
        try {
            if (fseek($src, $contentOffset, SEEK_SET) === -1) {
                throw new CorruptedArchiveException(sprintf(
                    'Failed to seek to content offset %d for %s.',
                    $contentOffset,
                    $header->path()
                ));
            }

            $dest = @fopen($targetPath, 'wb');
            if ($dest === false) {
                throw new NotReadableException(sprintf('Could not open destination for writing: %s', $targetPath));
            }

            try {
                $remaining = $header->size();
                while ($remaining > 0) {
                    $want = min(self::READ_CHUNK_SIZE, $remaining);
                    $chunk = fread($src, $want);
                    if ($chunk === false || $chunk === '') {
                        throw new TruncatedArchiveException(sprintf(
                            'Unexpected EOF while extracting %s (%d bytes remaining).',
                            $header->path(),
                            $remaining
                        ));
                    }
                    $written = fwrite($dest, $chunk);
                    if ($written !== strlen($chunk)) {
                        throw new NotReadableException(sprintf(
                            'Short write while extracting %s.',
                            $header->path()
                        ));
                    }
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($dest);
            }
        } finally {
            fclose($src);
        }

        // Preserve mtime.
        if ($header->mtime() > 0) {
            @touch($targetPath, $header->mtime());
        }
    }

    /**
     * Extract every file in the archive to a destination directory.
     *
     * @return int Number of files extracted.
     */
    public function extractAll(string $destDir): int
    {
        $this->ensureDirectory($destDir);
        $count = 0;
        foreach ($this->listFiles() as [$header, $offset]) {
            $this->extractFile($header, $offset, $destDir);
            $count++;
        }
        return $count;
    }

    /**
     * Check whether the archive has a proper EOF block at the end.
     * Does NOT verify CRC (use verifyCrc() for that).
     */
    public function isValid(): bool
    {
        $size = filesize($this->archivePath);
        if ($size === false || $size < Header::HEADER_SIZE) {
            return false;
        }

        $handle = @fopen($this->archivePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (fseek($handle, -Header::HEADER_SIZE, SEEK_END) === -1) {
                return false;
            }
            $block = fread($handle, Header::HEADER_SIZE);
            return $block !== false && Header::isEofBlock($block);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Verify v2 EOF CRC against actual file contents. Returns true if archive is v1
     * (no CRC to check) or v2 and CRC matches.
     */
    public function verifyCrc(): bool
    {
        $size = filesize($this->archivePath);
        if ($size === false || $size < Header::HEADER_SIZE) {
            return false;
        }

        $handle = @fopen($this->archivePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (fseek($handle, -Header::HEADER_SIZE, SEEK_END) === -1) {
                return false;
            }
            $block = fread($handle, Header::HEADER_SIZE);
        } finally {
            fclose($handle);
        }

        if ($block === false || !Header::isEofBlock($block)) {
            return false;
        }

        $data = unpack('a255/a14size/a4100/a8crc32', $block);
        if ($data === false) {
            return false;
        }
        $crc = rtrim($data['crc32'], "\0");
        $sizeStr = rtrim($data['size'], "\0");
        if ($crc === '' || $sizeStr === '') {
            // v1 archive — no CRC to verify.
            return true;
        }

        $expectedSize = (int) $sizeStr;
        if ($expectedSize + Header::HEADER_SIZE !== $size) {
            return false;
        }

        // Compute CRC over the pre-EOF bytes.
        $handle = @fopen($this->archivePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $ctx = hash_init('crc32b');
            $remaining = $expectedSize;
            while ($remaining > 0) {
                $want = min(self::READ_CHUNK_SIZE, $remaining);
                $chunk = fread($handle, $want);
                if ($chunk === false || $chunk === '') {
                    return false;
                }
                hash_update($ctx, $chunk);
                $remaining -= strlen($chunk);
            }
            $computed = hash_final($ctx);
        } finally {
            fclose($handle);
        }

        return hash_equals(strtolower($crc), strtolower($computed));
    }

    private function openForReading()
    {
        $handle = @fopen($this->archivePath, 'rb');
        if ($handle === false) {
            throw new NotReadableException(sprintf('Could not open archive for reading: %s', $this->archivePath));
        }
        return $handle;
    }

    /**
     * Join prefix + name safely, rejecting '..' traversal and absolute paths.
     */
    private function resolveSafeTargetPath(Header $header, string $destDir): string
    {
        $destReal = rtrim(realpath($destDir) ?: $destDir, DIRECTORY_SEPARATOR);

        $relative = $header->path();
        if ($this->containsPathTraversal($relative) || $this->isAbsolutePath($relative)) {
            throw new CorruptedArchiveException(sprintf(
                'Refusing to extract entry with unsafe path: %s',
                $relative
            ));
        }

        $target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        // Extra safety: ensure normalized target stays under destDir.
        $normalized = $this->normalizePath($target);
        if (strpos($normalized, $destReal . DIRECTORY_SEPARATOR) !== 0 && $normalized !== $destReal) {
            throw new CorruptedArchiveException(sprintf(
                'Refusing to extract outside destination directory: %s',
                $relative
            ));
        }

        return $target;
    }

    private function containsPathTraversal(string $path): bool
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        foreach ($parts as $part) {
            if ($part === '..') {
                return true;
            }
        }
        return false;
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
    }

    private function normalizePath(string $path): string
    {
        $separator = DIRECTORY_SEPARATOR;
        $parts = [];
        foreach (explode($separator, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $prefix = ($path !== '' && $path[0] === $separator) ? $separator : '';
        return $prefix . implode($separator, $parts);
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new NotReadableException(sprintf('Could not create directory: %s', $dir));
        }
    }
}
