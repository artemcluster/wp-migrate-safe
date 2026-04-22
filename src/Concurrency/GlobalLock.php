<?php
declare(strict_types=1);

namespace WpMigrateSafe\Concurrency;

use WpMigrateSafe\Concurrency\Exception\LockHeldException;

/**
 * Mutex ensuring only one export or import runs at a time site-wide.
 *
 * Backed by WP transients (uses the object cache if available, otherwise
 * the options table). TTL prevents a dead job from permanently blocking.
 */
final class GlobalLock
{
    private const KEY = 'wpms_global_lock';
    private const TTL_SECONDS = 2 * HOUR_IN_SECONDS;

    /**
     * Attempt to acquire the lock on behalf of $jobId.
     *
     * @throws LockHeldException if another job already holds it.
     */
    public static function acquire(string $jobId): void
    {
        $existing = get_transient(self::KEY);
        if (is_array($existing) && isset($existing['job_id']) && $existing['job_id'] !== $jobId) {
            throw new LockHeldException(
                sprintf('Lock is held by another job (%s).', $existing['job_id']),
                (string) $existing['job_id']
            );
        }
        set_transient(self::KEY, ['job_id' => $jobId, 'acquired_at' => time()], self::TTL_SECONDS);
    }

    /**
     * Release if the lock is held by $jobId (no-op otherwise so a retry is safe).
     */
    public static function release(string $jobId): void
    {
        $existing = get_transient(self::KEY);
        if (is_array($existing) && ($existing['job_id'] ?? '') === $jobId) {
            delete_transient(self::KEY);
        }
    }

    /**
     * @return array{job_id: string, acquired_at: int}|null
     */
    public static function current(): ?array
    {
        $existing = get_transient(self::KEY);
        return is_array($existing) ? $existing : null;
    }

    /**
     * Force-release regardless of holder (used by admin "force unlock" action).
     */
    public static function forceRelease(): void
    {
        delete_transient(self::KEY);
    }
}
