<?php
declare(strict_types=1);

namespace WpMigrateSafe\Job\Exception;

use RuntimeException;

class StepFailedException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
