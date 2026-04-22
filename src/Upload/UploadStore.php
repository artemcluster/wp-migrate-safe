<?php
declare(strict_types=1);

namespace WpMigrateSafe\Upload;

use WpMigrateSafe\Upload\Exception\InsufficientDiskSpaceException;
use WpMigrateSafe\Upload\Exception\InvalidChunkException;
use WpMigrateSafe\Upload\Exception\UploadException;

/**
 * Owns the filesystem layout for chunked uploads.
 *
 * Each session lives in `{sessionsDir}/{upload_id}/`:
 *   - session.json  — metadata
 *   - upload.part   — growing file, chunks written at byte offsets
 *
 * On finalize(), the .part file is verified against the expected sha256 and
 * atomically moved to `{finalDir}/{filename}`.
 */
final class UploadStore
{
    private string $sessionsDir;
    private string $finalDir;
    /** @var callable():int|null */
    private $freeBytesCallback;

    public function __construct(string $sessionsDir, string $finalDir, ?callable $freeBytesCallback = null)
    {
        $this->sessionsDir = rtrim($sessionsDir, '/\\');
        $this->finalDir = rtrim($finalDir, '/\\');
        $this->freeBytesCallback = $freeBytesCallback;
    }

    public function create(UploadSession $session): void
    {
        $needed = (int) ($session->totalSize() * 1.1);
        $free = $this->freeBytes();
        if ($free !== null && $free < $needed) {
            throw new InsufficientDiskSpaceException(sprintf(
                'Not enough disk space: need ~%d bytes, have %d.',
                $needed,
                $free
            ));
        }

        $dir = $this->sessionDir($session->uploadId());
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new UploadException('Could not create session directory: ' . $dir);
        }

        $this->writeSession($session);

        // Pre-create (touch) the .part file so writeChunk can fseek into arbitrary offsets.
        $partPath = $this->partPath($session->uploadId());
        $handle = fopen($partPath, 'cb');
        if ($handle === false) {
            throw new UploadException('Could not create .part file: ' . $partPath);
        }
        fclose($handle);
    }

    public function load(string $uploadId): UploadSession
    {
        $json = @file_get_contents($this->sessionFile($uploadId));
        if ($json === false) {
            throw new UploadException('Session not found: ' . $uploadId);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new UploadException('Session metadata is corrupt for ' . $uploadId);
        }
        return UploadSession::fromArray($data);
    }

    public function writeChunk(UploadSession $session, int $chunkIndex, string $bytes): UploadSession
    {
        // Always reload the persisted session so we capture any chunks written since
        // the caller last held the session object (supports out-of-order concurrent writes).
        $current = $this->load($session->uploadId());

        $expectedSize = $this->expectedChunkSize($current, $chunkIndex);
        if (strlen($bytes) !== $expectedSize) {
            throw new InvalidChunkException(sprintf(
                'Chunk %d has wrong size: got %d, expected %d.',
                $chunkIndex,
                strlen($bytes),
                $expectedSize
            ));
        }

        $offset = $chunkIndex * $current->chunkSize();
        $partPath = $this->partPath($current->uploadId());
        $handle = fopen($partPath, 'r+b');
        if ($handle === false) {
            throw new UploadException('Could not open .part for writing: ' . $partPath);
        }

        try {
            if (fseek($handle, $offset, SEEK_SET) === -1) {
                throw new UploadException('Seek failed at offset ' . $offset);
            }
            $written = fwrite($handle, $bytes);
            if ($written !== strlen($bytes)) {
                throw new UploadException('Short write on chunk ' . $chunkIndex);
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }

        $updated = $current->withChunkReceived($chunkIndex);
        $this->writeSession($updated);
        return $updated;
    }

    public function finalize(UploadSession $session): string
    {
        if (!$session->isComplete()) {
            throw new InvalidChunkException(sprintf(
                'Cannot finalize: received %d of %d chunks.',
                count($session->receivedChunks()),
                $session->expectedChunkCount()
            ));
        }

        $partPath = $this->partPath($session->uploadId());
        $actualHash = hash_file('sha256', $partPath);
        if (!hash_equals(strtolower($session->sha256()), strtolower($actualHash))) {
            throw new InvalidChunkException(sprintf(
                'SHA-256 mismatch on finalize. Expected %s, got %s.',
                $session->sha256(),
                $actualHash
            ));
        }

        if (!is_dir($this->finalDir) && !mkdir($this->finalDir, 0755, true)) {
            throw new UploadException('Could not create final directory: ' . $this->finalDir);
        }

        $finalPath = $this->resolveUniqueFinalPath($session->filename());
        if (!rename($partPath, $finalPath)) {
            throw new UploadException('Could not move file into place: ' . $finalPath);
        }

        // Remove session directory.
        $this->rmTree($this->sessionDir($session->uploadId()));

        return $finalPath;
    }

    public function abort(UploadSession $session): void
    {
        $this->rmTree($this->sessionDir($session->uploadId()));
    }

    /**
     * Remove sessions older than maxAgeSeconds (used by cron cleanup in Plan 6).
     *
     * @return int Number of sessions removed.
     */
    public function purgeStale(int $maxAgeSeconds): int
    {
        if (!is_dir($this->sessionsDir)) {
            return 0;
        }
        $now = time();
        $removed = 0;
        foreach (scandir($this->sessionsDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $meta = $this->sessionsDir . '/' . $entry . '/session.json';
            if (!is_file($meta)) continue;
            $data = json_decode((string) file_get_contents($meta), true);
            $createdAt = is_array($data) && isset($data['created_at']) ? (int) $data['created_at'] : 0;
            if ($now - $createdAt > $maxAgeSeconds) {
                $this->rmTree($this->sessionsDir . '/' . $entry);
                $removed++;
            }
        }
        return $removed;
    }

    // ---------- internals ----------

    private function sessionDir(string $uploadId): string
    {
        return $this->sessionsDir . '/' . $uploadId;
    }

    private function sessionFile(string $uploadId): string
    {
        return $this->sessionDir($uploadId) . '/session.json';
    }

    private function partPath(string $uploadId): string
    {
        return $this->sessionDir($uploadId) . '/upload.part';
    }

    private function writeSession(UploadSession $session): void
    {
        $json = json_encode($session->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new UploadException('Could not encode session JSON.');
        }
        if (file_put_contents($this->sessionFile($session->uploadId()), $json, LOCK_EX) === false) {
            throw new UploadException('Could not write session metadata.');
        }
    }

    private function expectedChunkSize(UploadSession $session, int $chunkIndex): int
    {
        if ($chunkIndex < 0 || $chunkIndex >= $session->expectedChunkCount()) {
            throw new InvalidChunkException('Chunk index out of range: ' . $chunkIndex);
        }
        $isLast = $chunkIndex === $session->expectedChunkCount() - 1;
        if (!$isLast) {
            return $session->chunkSize();
        }
        $tail = $session->totalSize() % $session->chunkSize();
        return $tail === 0 ? $session->chunkSize() : $tail;
    }

    private function resolveUniqueFinalPath(string $filename): string
    {
        $candidate = $this->finalDir . '/' . $filename;
        if (!file_exists($candidate)) {
            return $candidate;
        }
        $info = pathinfo($filename);
        $base = $info['filename'] ?? $filename;
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $i = 1;
        while (file_exists($this->finalDir . '/' . $base . '-' . $i . $ext)) {
            $i++;
        }
        return $this->finalDir . '/' . $base . '-' . $i . $ext;
    }

    private function freeBytes(): ?int
    {
        if ($this->freeBytesCallback !== null) {
            return ($this->freeBytesCallback)();
        }
        $free = @disk_free_space($this->sessionsDir);
        return $free === false ? null : (int) $free;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
