<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

/**
 * Immutable report produced by running pre-import checks.
 *
 * Each check adds a pass/fail entry with an optional message.
 * The report is considered OK when all checks pass.
 */
final class DryRunReport
{
    /** @var array<array{name:string,passed:bool,message:string}> */
    private array $checks;

    public function __construct()
    {
        $this->checks = [];
    }

    private function __clone() {}

    public function withCheck(string $name, bool $passed, string $message = ''): self
    {
        $clone = clone $this;
        $clone->checks[] = [
            'name'    => $name,
            'passed'  => $passed,
            'message' => $message,
        ];
        return $clone;
    }

    public function ok(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check['passed']) {
                return false;
            }
        }
        return true;
    }

    /** @return array<array{name:string,passed:bool,message:string}> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function toArray(): array
    {
        return [
            'ok'     => $this->ok(),
            'checks' => $this->checks,
        ];
    }
}
