<?php
declare(strict_types=1);

namespace WpMigrateSafe\Concurrency;

use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\JobStore;

/**
 * Auto-release the global lock when its holder job is gone or terminal.
 *
 * Scenarios that leave a stale lock:
 *   - PHP-FPM killed mid-step (OOM, timeout) — the job file may show 'running' but
 *     no request will ever touch it again
 *   - an earlier buggy code path set the lock with a placeholder id (e.g. "pending-xxx")
 *     and never cleared it
 *   - an upgrade dropped jobs but kept the transient
 *
 * We check:
 *   - lock holder id doesn't match any job in JobStore → release (zombie)
 *   - lock holder job exists but is terminal (completed/failed/aborted) → release
 *
 * Heartbeat-based release for "running" jobs that went silent is handled separately
 * by Heartbeat::isStale() in status endpoints; we don't touch those here to avoid
 * interrupting a slow-but-alive worker.
 */
final class ZombieLock
{
    public static function release(JobStore $jobStore): void
    {
        $current = GlobalLock::current();
        if ($current === null) {
            return;
        }

        $holderId = (string) ($current['job_id'] ?? '');
        if ($holderId === '' || !preg_match('/^[a-f0-9]{32}$/', $holderId)) {
            // Non-job holder (e.g. "pending-xxx" from older buggy code).
            GlobalLock::forceRelease();
            return;
        }

        try {
            $job = $jobStore->load($holderId);
        } catch (\Throwable $e) {
            GlobalLock::forceRelease();
            return;
        }

        if (JobStatus::isTerminal($job->status())) {
            GlobalLock::forceRelease();
        }
    }
}
