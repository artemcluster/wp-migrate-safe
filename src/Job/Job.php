<?php
declare(strict_types=1);

namespace WpMigrateSafe\Job;

use InvalidArgumentException;

/**
 * Immutable value object representing one unit of work (export or import).
 *
 * Persisted as JSON by JobStore between HTTP requests.
 */
final class Job
{
    public const KIND_EXPORT = 'export';
    public const KIND_IMPORT = 'import';

    private string $id;
    private string $kind;
    private string $status;
    private int $stepIndex;
    /** @var array<string, mixed> */
    private array $cursor;
    private int $progress;
    /** @var array<string, mixed> */
    private array $meta;
    private int $heartbeatAt;
    private int $createdAt;
    /** @var array{code:string,message:string,hint:string,step:string,context:array<string,mixed>}|null */
    private ?array $error;

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, mixed> $meta
     * @param array{code:string,message:string,hint:string,step:string,context:array<string,mixed>}|null $error
     */
    public function __construct(
        string $id,
        string $kind,
        string $status,
        int $stepIndex,
        array $cursor,
        int $progress,
        array $meta,
        int $heartbeatAt,
        int $createdAt,
        ?array $error = null
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new InvalidArgumentException('Invalid job id.');
        }
        if (!in_array($kind, [self::KIND_EXPORT, self::KIND_IMPORT], true)) {
            throw new InvalidArgumentException('Invalid job kind: ' . $kind);
        }
        if (!JobStatus::isValid($status)) {
            throw new InvalidArgumentException('Invalid status: ' . $status);
        }
        if ($progress < 0 || $progress > 100) {
            throw new InvalidArgumentException('Progress must be 0..100');
        }

        $this->id = $id;
        $this->kind = $kind;
        $this->status = $status;
        $this->stepIndex = $stepIndex;
        $this->cursor = $cursor;
        $this->progress = $progress;
        $this->meta = $meta;
        $this->heartbeatAt = $heartbeatAt;
        $this->createdAt = $createdAt;
        $this->error = $error;
    }

    public static function newExport(array $meta = []): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            self::KIND_EXPORT,
            JobStatus::PENDING,
            0,
            [],
            0,
            $meta,
            time(),
            time(),
            null
        );
    }

    public static function newImport(array $meta = []): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            self::KIND_IMPORT,
            JobStatus::PENDING,
            0,
            [],
            0,
            $meta,
            time(),
            time(),
            null
        );
    }

    public function id(): string { return $this->id; }
    public function kind(): string { return $this->kind; }
    public function status(): string { return $this->status; }
    public function stepIndex(): int { return $this->stepIndex; }
    public function cursor(): array { return $this->cursor; }
    public function progress(): int { return $this->progress; }
    public function meta(): array { return $this->meta; }
    public function heartbeatAt(): int { return $this->heartbeatAt; }
    public function createdAt(): int { return $this->createdAt; }
    public function error(): ?array { return $this->error; }

    public function withStatus(string $status): self
    {
        return new self(
            $this->id, $this->kind, $status, $this->stepIndex, $this->cursor,
            $this->progress, $this->meta, $this->heartbeatAt, $this->createdAt, $this->error
        );
    }

    public function withStepIndex(int $index, array $cursor): self
    {
        return new self(
            $this->id, $this->kind, JobStatus::RUNNING, $index, $cursor,
            $this->progress, $this->meta, time(), $this->createdAt, null
        );
    }

    public function withCursor(array $cursor, int $progress): self
    {
        return new self(
            $this->id, $this->kind, JobStatus::RUNNING, $this->stepIndex, $cursor,
            $progress, $this->meta, time(), $this->createdAt, null
        );
    }

    public function withHeartbeat(int $timestamp): self
    {
        return new self(
            $this->id, $this->kind, $this->status, $this->stepIndex, $this->cursor,
            $this->progress, $this->meta, $timestamp, $this->createdAt, $this->error
        );
    }

    public function withError(array $error): self
    {
        return new self(
            $this->id, $this->kind, JobStatus::FAILED, $this->stepIndex, $this->cursor,
            $this->progress, $this->meta, time(), $this->createdAt, $error
        );
    }

    public function withMeta(array $meta): self
    {
        return new self(
            $this->id, $this->kind, $this->status, $this->stepIndex, $this->cursor,
            $this->progress, array_merge($this->meta, $meta),
            $this->heartbeatAt, $this->createdAt, $this->error
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'step_index' => $this->stepIndex,
            'cursor' => $this->cursor,
            'progress' => $this->progress,
            'meta' => $this->meta,
            'heartbeat_at' => $this->heartbeatAt,
            'created_at' => $this->createdAt,
            'error' => $this->error,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['kind'],
            (string) $data['status'],
            (int) $data['step_index'],
            (array) ($data['cursor'] ?? []),
            (int) ($data['progress'] ?? 0),
            (array) ($data['meta'] ?? []),
            (int) ($data['heartbeat_at'] ?? 0),
            (int) ($data['created_at'] ?? 0),
            isset($data['error']) && is_array($data['error']) ? $data['error'] : null
        );
    }
}
