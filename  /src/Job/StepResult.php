<?php
declare(strict_types=1);

namespace WpMigrateSafe\Job;

/**
 * Result returned from running one slice of a step.
 *
 * - If done=true → move to the next step, cursor is ignored.
 * - If done=false → call the same step again with the returned cursor.
 */
final class StepResult
{
    private bool $done;
    /** @var array<string, mixed> */
    private array $cursor;
    private int $progress;
    private string $message;
    /** @var array<string, mixed> */
    private array $meta;

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, mixed> $meta
     */
    public function __construct(bool $done, array $cursor, int $progress, string $message, array $meta = [])
    {
        $this->done = $done;
        $this->cursor = $cursor;
        $this->progress = max(0, min(100, $progress));
        $this->message = $message;
        $this->meta = $meta;
    }

    public static function advance(array $cursor, int $progress, string $message, array $meta = []): self
    {
        return new self(false, $cursor, $progress, $message, $meta);
    }

    public static function complete(int $progress, string $message, array $meta = []): self
    {
        return new self(true, [], $progress, $message, $meta);
    }

    public function done(): bool { return $this->done; }
    public function cursor(): array { return $this->cursor; }
    public function progress(): int { return $this->progress; }
    public function message(): string { return $this->message; }
    public function meta(): array { return $this->meta; }
}
