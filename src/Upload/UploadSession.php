<?php
declare(strict_types=1);

namespace WpMigrateSafe\Upload;

use InvalidArgumentException;

/**
 * Immutable value object describing an upload-in-progress.
 *
 * Persisted as JSON in the upload session directory (see UploadStore).
 */
final class UploadSession
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED   = 'aborted';

    private string $uploadId;
    private string $filename;
    private int $totalSize;
    private int $chunkSize;
    private string $sha256;
    private string $status;
    private int $createdAt;
    /** @var int[] */
    private array $receivedChunks;

    /**
     * @param int[] $receivedChunks
     */
    public function __construct(
        string $uploadId,
        string $filename,
        int $totalSize,
        int $chunkSize,
        string $sha256,
        string $status,
        int $createdAt,
        array $receivedChunks
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new InvalidArgumentException('Invalid upload_id format (expected 32 hex chars).');
        }
        if ($filename === '' || strpbrk($filename, "/\\\0") !== false) {
            throw new InvalidArgumentException('Filename must be non-empty and contain no path separators.');
        }
        if (!preg_match('/\.wpress$/i', $filename)) {
            throw new InvalidArgumentException('Only .wpress filenames are accepted.');
        }
        if ($totalSize <= 0) {
            throw new InvalidArgumentException('Total size must be positive.');
        }
        if ($chunkSize <= 0 || $chunkSize > $totalSize) {
            throw new InvalidArgumentException('Chunk size must be positive and <= total size.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new InvalidArgumentException('SHA-256 must be 64 hex chars.');
        }
        $validStatuses = [self::STATUS_PENDING, self::STATUS_UPLOADING, self::STATUS_COMPLETED, self::STATUS_ABORTED];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException('Invalid status: ' . $status);
        }

        foreach ($receivedChunks as $idx) {
            if (!is_int($idx) || $idx < 0) {
                throw new InvalidArgumentException('receivedChunks must be list of non-negative ints.');
            }
        }

        $this->uploadId = $uploadId;
        $this->filename = $filename;
        $this->totalSize = $totalSize;
        $this->chunkSize = $chunkSize;
        $this->sha256 = strtolower($sha256);
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->receivedChunks = array_values(array_unique($receivedChunks));
    }

    public function uploadId(): string { return $this->uploadId; }
    public function filename(): string { return $this->filename; }
    public function totalSize(): int { return $this->totalSize; }
    public function chunkSize(): int { return $this->chunkSize; }
    public function sha256(): string { return $this->sha256; }
    public function status(): string { return $this->status; }
    public function createdAt(): int { return $this->createdAt; }
    /** @return int[] */
    public function receivedChunks(): array { return $this->receivedChunks; }

    public function expectedChunkCount(): int
    {
        return (int) ceil($this->totalSize / $this->chunkSize);
    }

    public function isComplete(): bool
    {
        return count($this->receivedChunks) === $this->expectedChunkCount();
    }

    public function withStatus(string $status): self
    {
        return new self(
            $this->uploadId, $this->filename, $this->totalSize, $this->chunkSize,
            $this->sha256, $status, $this->createdAt, $this->receivedChunks
        );
    }

    public function withChunkReceived(int $chunkIndex): self
    {
        if ($chunkIndex < 0 || $chunkIndex >= $this->expectedChunkCount()) {
            throw new InvalidArgumentException(sprintf(
                'Chunk index %d out of range [0, %d).',
                $chunkIndex,
                $this->expectedChunkCount()
            ));
        }
        $chunks = $this->receivedChunks;
        if (!in_array($chunkIndex, $chunks, true)) {
            $chunks[] = $chunkIndex;
        }
        return new self(
            $this->uploadId, $this->filename, $this->totalSize, $this->chunkSize,
            $this->sha256, self::STATUS_UPLOADING, $this->createdAt, $chunks
        );
    }

    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'filename' => $this->filename,
            'total_size' => $this->totalSize,
            'chunk_size' => $this->chunkSize,
            'sha256' => $this->sha256,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'received_chunks' => $this->receivedChunks,
        ];
    }

    public static function fromArray(array $data): self
    {
        $required = ['upload_id', 'filename', 'total_size', 'chunk_size', 'sha256', 'status', 'created_at', 'received_chunks'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $data)) {
                throw new InvalidArgumentException('Missing field: ' . $k);
            }
        }
        return new self(
            (string) $data['upload_id'],
            (string) $data['filename'],
            (int) $data['total_size'],
            (int) $data['chunk_size'],
            (string) $data['sha256'],
            (string) $data['status'],
            (int) $data['created_at'],
            array_map('intval', (array) $data['received_chunks'])
        );
    }
}
