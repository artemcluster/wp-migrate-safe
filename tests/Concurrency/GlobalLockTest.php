<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Concurrency;

use WP_UnitTestCase;
use WpMigrateSafe\Concurrency\GlobalLock;
use WpMigrateSafe\Concurrency\Exception\LockHeldException;

/**
 * Run with: ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class GlobalLockTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        GlobalLock::forceRelease();
    }

    public function testAcquireAndReleaseRoundTrip(): void
    {
        $job = bin2hex(random_bytes(16));
        GlobalLock::acquire($job);
        $current = GlobalLock::current();
        $this->assertSame($job, $current['job_id']);

        GlobalLock::release($job);
        $this->assertNull(GlobalLock::current());
    }

    public function testReentrantAcquireByOwnerIsSilent(): void
    {
        $job = bin2hex(random_bytes(16));
        GlobalLock::acquire($job);
        GlobalLock::acquire($job); // must NOT throw
        $this->assertSame($job, GlobalLock::current()['job_id']);
    }

    public function testSecondJobFailsWhileFirstHolds(): void
    {
        $a = bin2hex(random_bytes(16));
        $b = bin2hex(random_bytes(16));
        GlobalLock::acquire($a);

        $this->expectException(LockHeldException::class);
        GlobalLock::acquire($b);
    }

    public function testReleaseByNonOwnerIsNoOp(): void
    {
        $a = bin2hex(random_bytes(16));
        $b = bin2hex(random_bytes(16));
        GlobalLock::acquire($a);
        GlobalLock::release($b); // different owner — must NOT release
        $this->assertSame($a, GlobalLock::current()['job_id']);
    }

    public function testForceReleaseAlwaysWorks(): void
    {
        GlobalLock::acquire(bin2hex(random_bytes(16)));
        GlobalLock::forceRelease();
        $this->assertNull(GlobalLock::current());
    }
}
