<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Upload;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Upload\UploadSession;

final class UploadSessionTest extends TestCase
{
    private function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function sha256(): string
    {
        return hash('sha256', 'test-payload');
    }

    public function testConstructAndAccessors(): void
    {
        $s = new UploadSession(
            $this->id(),
            'backup.wpress',
            500 * 1024 * 1024,
            5 * 1024 * 1024,
            $this->sha256(),
            UploadSession::STATUS_PENDING,
            time(),
            []
        );

        $this->assertSame('backup.wpress', $s->filename());
        $this->assertSame(500 * 1024 * 1024, $s->totalSize());
        $this->assertSame(5 * 1024 * 1024, $s->chunkSize());
        $this->assertSame(UploadSession::STATUS_PENDING, $s->status());
        $this->assertSame(100, $s->expectedChunkCount());
        $this->assertFalse($s->isComplete());
    }

    public function testWithChunkReceivedIsImmutable(): void
    {
        $s = new UploadSession($this->id(), 'x.wpress', 1000, 100, $this->sha256(), 'pending', time(), []);
        $s2 = $s->withChunkReceived(0);

        $this->assertNotSame($s, $s2);
        $this->assertSame([], $s->receivedChunks());
        $this->assertSame([0], $s2->receivedChunks());
        $this->assertSame(UploadSession::STATUS_UPLOADING, $s2->status());
    }

    public function testWithChunkReceivedIsIdempotent(): void
    {
        $s = new UploadSession($this->id(), 'x.wpress', 1000, 100, $this->sha256(), 'pending', time(), [0, 1]);
        $s2 = $s->withChunkReceived(1); // duplicate

        $this->assertSame([0, 1], $s2->receivedChunks());
    }

    public function testIsCompleteWhenAllChunksReceived(): void
    {
        $s = new UploadSession(
            $this->id(), 'x.wpress', 300, 100, $this->sha256(), 'uploading', time(), [0, 1, 2]
        );
        $this->assertTrue($s->isComplete());
    }

    public function testRejectsInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadSession('short', 'x.wpress', 10, 5, $this->sha256(), 'pending', time(), []);
    }

    public function testRejectsNonWpressFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadSession($this->id(), 'backup.zip', 10, 5, $this->sha256(), 'pending', time(), []);
    }

    public function testRejectsFilenameWithPathSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadSession($this->id(), '../evil.wpress', 10, 5, $this->sha256(), 'pending', time(), []);
    }

    public function testRejectsOutOfRangeChunkIndex(): void
    {
        $s = new UploadSession($this->id(), 'x.wpress', 100, 100, $this->sha256(), 'pending', time(), []);
        $this->expectException(\InvalidArgumentException::class);
        $s->withChunkReceived(5);
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $original = new UploadSession(
            $this->id(), 'ok.wpress', 999, 100, $this->sha256(), 'uploading', 1700000000, [0, 3, 5]
        );
        $copy = UploadSession::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $copy->toArray());
    }
}
