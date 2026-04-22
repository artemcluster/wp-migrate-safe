<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rollback;

/**
 * Thrown when the rollback process itself fails, leaving the site in an
 * inconsistent state. This is a critical error that requires manual intervention.
 */
final class RollbackFailedException extends \RuntimeException
{
    /** @var string[] */
    private array $failedSteps;

    /**
     * @param string[] $failedSteps
     */
    public function __construct(string $message, array $failedSteps = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->failedSteps = $failedSteps;
    }

    /** @return string[] */
    public function failedSteps(): array
    {
        return $this->failedSteps;
    }
}
