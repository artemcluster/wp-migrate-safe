<?php
declare(strict_types=1);

namespace WpMigrateSafe\Progress;

use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;

final class Heartbeat
{
    public const STALE_THRESHOLD_SECONDS = 60;

    /**
     * A running job is "stale" if its heartbeat hasn't been updated within STALE_THRESHOLD_SECONDS.
     * Terminal jobs are never stale.
     */
    public static function isStale(Job $job, ?int $now = null): bool
    {
        if (JobStatus::isTerminal($job->status())) {
            return false;
        }
        $now = $now ?? time();
        return ($now - $job->heartbeatAt()) > self::STALE_THRESHOLD_SECONDS;
    }
}
