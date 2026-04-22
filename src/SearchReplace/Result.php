<?php
declare(strict_types=1);

namespace WpMigrateSafe\SearchReplace;

/**
 * Result of applying a Replacer to a single value.
 *
 * Immutable value object.
 */
final class Result
{
    private string $value;
    private int $replacements;

    public function __construct(string $value, int $replacements)
    {
        $this->value = $value;
        $this->replacements = $replacements;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function replacements(): int
    {
        return $this->replacements;
    }

    public function changed(): bool
    {
        return $this->replacements > 0;
    }
}
