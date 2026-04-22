<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rollback\Exception;

use RuntimeException;

/**
 * The nuclear outcome: rollback itself failed. The site is in an indeterminate state.
 * User must be shown manual recovery instructions.
 */
final class RollbackFailedException extends RuntimeException
{
    /** @var array<int, string> */
    private array $manualSteps;

    /**
     * @param array<int, string> $manualSteps
     */
    public function __construct(string $message, array $manualSteps, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->manualSteps = $manualSteps;
    }

    /** @return array<int, string> */
    public function manualSteps(): array
    {
        return $this->manualSteps;
    }
}
