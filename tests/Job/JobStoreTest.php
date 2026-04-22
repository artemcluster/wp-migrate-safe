<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Job;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\JobStore;
use WpMigrateSafe\Job\Exception\JobNotFoundException;

final class JobStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_jobs_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $store = new JobStore($this->dir);
        $job = Job::newExport(['filename' => 'out.wpress']);
        $store->save($job);

        $loaded = $store->load($job->id());
        $this->assertSame($job->toArray(), $loaded->toArray());
    }

    public function testLoadThrowsForMissing(): void
    {
        $store = new JobStore($this->dir);
        $this->expectException(JobNotFoundException::class);
        $store->load(bin2hex(random_bytes(16)));
    }

    public function testFindActiveReturnsRunningJobs(): void
    {
        $store = new JobStore($this->dir);
        $finished = Job::newExport()->withStatus(JobStatus::COMPLETED);
        $running = Job::newExport()->withStepIndex(1, []);
        $store->save($finished);
        $store->save($running);

        $active = $store->findActive();
        $this->assertCount(1, $active);
        $this->assertSame($running->id(), $active[0]->id());
    }

    public function testPurgeOldRemovesTerminalJobsOlderThanCutoff(): void
    {
        $store = new JobStore($this->dir);
        $old = Job::newExport()->withStatus(JobStatus::COMPLETED);
        $store->save($old);
        // Touch file to make it old.
        $path = $this->dir . '/' . $old->id() . '.json';
        touch($path, time() - 8 * 86400);

        $recent = Job::newExport()->withStatus(JobStatus::COMPLETED);
        $store->save($recent);

        $removed = $store->purgeOld(7 * 86400);
        $this->assertSame(1, $removed);
        $this->assertFileExists($this->dir . '/' . $recent->id() . '.json');
        $this->assertFileDoesNotExist($path);
    }
}
