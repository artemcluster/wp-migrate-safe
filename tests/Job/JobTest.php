<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Job;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;

final class JobTest extends TestCase
{
    public function testNewExportHasSensibleDefaults(): void
    {
        $job = Job::newExport(['filename' => 'out.wpress']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $job->id());
        $this->assertSame(Job::KIND_EXPORT, $job->kind());
        $this->assertSame(JobStatus::PENDING, $job->status());
        $this->assertSame(0, $job->stepIndex());
        $this->assertSame(0, $job->progress());
        $this->assertSame('out.wpress', $job->meta()['filename']);
    }

    public function testWithStepIndexMovesToRunning(): void
    {
        $job = Job::newExport();
        $advanced = $job->withStepIndex(2, ['offset' => 500]);
        $this->assertSame(2, $advanced->stepIndex());
        $this->assertSame(['offset' => 500], $advanced->cursor());
        $this->assertSame(JobStatus::RUNNING, $advanced->status());
    }

    public function testWithErrorMovesToFailed(): void
    {
        $job = Job::newExport()->withStatus(JobStatus::RUNNING);
        $failed = $job->withError([
            'code' => 'DB_CONNECTION_LOST',
            'message' => 'oops',
            'hint' => 'retry',
            'step' => 'dump',
            'context' => ['table' => 'wp_posts'],
        ]);
        $this->assertSame(JobStatus::FAILED, $failed->status());
        $this->assertSame('DB_CONNECTION_LOST', $failed->error()['code']);
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $job = Job::newExport(['foo' => 'bar'])
            ->withStepIndex(1, ['x' => 1])
            ->withCursor(['x' => 10], 42);
        $copy = Job::fromArray($job->toArray());
        $this->assertSame($job->toArray(), $copy->toArray());
    }

    public function testRejectsInvalidProgress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Job(
            bin2hex(random_bytes(16)), Job::KIND_EXPORT, JobStatus::PENDING,
            0, [], 150, [], time(), time()
        );
    }

    public function testIsTerminalForCompletedFailedAborted(): void
    {
        $this->assertTrue(JobStatus::isTerminal(JobStatus::COMPLETED));
        $this->assertTrue(JobStatus::isTerminal(JobStatus::FAILED));
        $this->assertTrue(JobStatus::isTerminal(JobStatus::ABORTED));
        $this->assertFalse(JobStatus::isTerminal(JobStatus::RUNNING));
    }
}
