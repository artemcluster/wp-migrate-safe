<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

/**
 * Immutable result of running all dry-run checks against an archive.
 *
 * Warnings allow the user to proceed at their own risk; errors block import.
 */
final class DryRunReport
{
    /** @var array<int, array{code: string, message: string, hint: string}> */
    private array $errors;
    /** @var array<int, array{code: string, message: string, hint: string}> */
    private array $warnings;

    /**
     * @param array<int, array{code: string, message: string, hint: string}> $errors
     * @param array<int, array{code: string, message: string, hint: string}> $warnings
     */
    public function __construct(array $errors, array $warnings)
    {
        $this->errors = $errors;
        $this->warnings = $warnings;
    }

    public static function ok(): self { return new self([], []); }

    public function errors(): array { return $this->errors; }
    public function warnings(): array { return $this->warnings; }

    public function hasErrors(): bool { return count($this->errors) > 0; }
    public function hasWarnings(): bool { return count($this->warnings) > 0; }
    public function canProceed(): bool { return !$this->hasErrors(); }

    public function merge(DryRunReport $other): self
    {
        return new self(
            array_merge($this->errors, $other->errors()),
            array_merge($this->warnings, $other->warnings())
        );
    }

    public function toArray(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'can_proceed' => $this->canProceed(),
        ];
    }
}
