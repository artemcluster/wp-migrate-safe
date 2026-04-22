<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Upload;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Upload\UploadSession;
use WpMigrateSafe\Upload\UploadStore;
use WpMigrateSafe\Upload\Exception\InsufficientDiskSpaceException;
use WpMigrateSafe\Upload\Exception\InvalidChunkException;

final class UploadStoreTest extends TestCase
{
    private string $workDir;
    private string $finalDir;
    private UploadStore $store;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/wpms_store_sessions_' . uniqid();
        $this->finalDir = sys_get_temp_dir() . '/wpms_store_final_' . uniqid();
        mkdir($this->workDir, 0755, true);
        mkdir($this->finalDir, 0755, true);
        $this->store = new UploadStore($this->workDir, $this->finalDir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $this->rmrf($this->finalDir);
    }

    public function testCreateNewSessionWritesMetadataAndReservesPartFile(): void
    {
        $session = $this->newSession(1000, 100);
        $this->store->create($session);

        $sessionDir = $this->workDir . '/' . $session->uploadId();
        $this->assertDirectoryExists($sessionDir);
        $this->assertFileExists($sessionDir . '/session.json');
        $this->assertFileExists($sessionDir . '/upload.part');

        // Metadata round-trips.
        $reloaded = $this->store->load($session->uploadId());
        $this->assertSame($session->toArray(), $reloaded->toArray());
    }

    public function testWriteChunkAppendsAtCorrectOffset(): void
    {
        $session = $this->newSession(30, 10); // 3 chunks of 10 bytes each
        $this->store->create($session);

        $session = $this->store->writeChunk($session, 0, 'AAAAAAAAAA');
        $session = $this->store->writeChunk($session, 2, 'CCCCCCCCCC');
        $session = $this->store->writeChunk($session, 1, 'BBBBBBBBBB');

        $partFile = $this->workDir . '/' . $session->uploadId() . '/upload.part';
        $this->assertSame('AAAAAAAAAABBBBBBBBBBCCCCCCCCCC', file_get_contents($partFile));
        $this->assertSame([0, 2, 1], $session->receivedChunks());
    }

    public function testWriteChunkRejectsWrongSize(): void
    {
        $session = $this->newSession(30, 10);
        $this->store->create($session);

        $this->expectException(InvalidChunkException::class);
        $this->store->writeChunk($session, 0, 'too short');
    }

    public function testLastChunkCanBeSmallerThanChunkSize(): void
    {
        $session = $this->newSession(25, 10); // 3 chunks: 10+10+5
        $this->store->create($session);

        $this->store->writeChunk($session, 0, 'AAAAAAAAAA');
        $this->store->writeChunk($session, 1, 'BBBBBBBBBB');
        $session = $this->store->writeChunk($session, 2, 'CCCCC'); // final short chunk

        $this->assertTrue($session->isComplete());
    }

    public function testFinalizeMovesFileAndVerifiesHash(): void
    {
        $payload = 'Hello, chunked world!';
        $sha = hash('sha256', $payload);
        $session = $this->newSession(strlen($payload), 10, $sha);
        $this->store->create($session);

        $chunks = str_split($payload, 10);
        foreach ($chunks as $i => $chunk) {
            $session = $this->store->writeChunk($session, $i, $chunk);
        }

        $finalPath = $this->store->finalize($session);

        $this->assertFileExists($finalPath);
        $this->assertSame($payload, file_get_contents($finalPath));
        $this->assertStringStartsWith($this->finalDir, $finalPath);

        // Session directory is removed after finalize.
        $this->assertDirectoryDoesNotExist($this->workDir . '/' . $session->uploadId());
    }

    public function testFinalizeRejectsWrongHash(): void
    {
        $payload = 'real content';
        $wrongSha = hash('sha256', 'different content');
        $session = $this->newSession(strlen($payload), 10, $wrongSha);
        $this->store->create($session);

        $session = $this->store->writeChunk($session, 0, substr($payload, 0, 10));
        $session = $this->store->writeChunk($session, 1, substr($payload, 10));

        $this->expectException(InvalidChunkException::class);
        $this->store->finalize($session);
    }

    public function testFinalizeFailsIfSomeChunksMissing(): void
    {
        $session = $this->newSession(30, 10);
        $this->store->create($session);
        $session = $this->store->writeChunk($session, 0, 'AAAAAAAAAA');

        $this->expectException(InvalidChunkException::class);
        $this->store->finalize($session);
    }

    public function testAbortRemovesSessionDirectory(): void
    {
        $session = $this->newSession(30, 10);
        $this->store->create($session);
        $dir = $this->workDir . '/' . $session->uploadId();
        $this->assertDirectoryExists($dir);

        $this->store->abort($session);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCreateRejectsWhenInsufficientDiskSpace(): void
    {
        // Build a store that reports fake free disk space.
        $store = new UploadStore($this->workDir, $this->finalDir, function (): int {
            return 100; // pretend only 100 bytes free
        });

        $this->expectException(InsufficientDiskSpaceException::class);
        $store->create($this->newSession(10_000, 1000)); // needs ~11 KB (1.1× size)
    }

    // ---------- helpers ----------

    private function newSession(int $totalSize, int $chunkSize, ?string $sha256 = null): UploadSession
    {
        return new UploadSession(
            bin2hex(random_bytes(16)),
            'test.wpress',
            $totalSize,
            $chunkSize,
            $sha256 ?? hash('sha256', 'placeholder'),
            UploadSession::STATUS_PENDING,
            time(),
            []
        );
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }
}
