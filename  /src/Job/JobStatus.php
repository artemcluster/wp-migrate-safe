<?php
declare(strict_types=1);

namespace WpMigrateSafe\Job;

final class JobStatus
{
    public const PENDING   = 'pending';
    public const RUNNING   = 'running';
    public const PAUSED    = 'paused';
    public const COMPLETED = 'completed';
    public const FAILED    = 'failed';
    public const ABORTED   = 'aborted';

    public const ALL = [
        self::PENDING, self::RUNNING, self::PAUSED,
        self::COMPLETED, self::FAILED, self::ABORTED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::COMPLETED, self::FAILED, self::ABORTED], true);
    }
}
