<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Errors;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Errors\ErrorCatalog;

final class ErrorCatalogTest extends TestCase
{
    public function testAllSpecCodesArePresent(): void
    {
        $expected = [
            'DISK_FULL', 'PHP_MEMORY_LOW', 'MYSQL_VERSION_OLD',
            'WPRESS_CORRUPTED', 'WPRESS_TRUNCATED',
            'DB_IMPORT_SYNTAX', 'DB_CONNECTION_LOST', 'DB_ROW_TOO_LARGE',
            'FS_PERMISSION', 'UPLOAD_CHUNK_HASH',
            'STEP_TIMEOUT', 'JOB_HEARTBEAT_LOST',
            'IMPORT_FAILED',
            'GLOBAL_LOCK_HELD',
        ];
        foreach ($expected as $code) {
            $this->assertTrue(ErrorCatalog::has($code), "Missing error code: $code");
        }
    }

    public function testEveryEntryHasRequiredFields(): void
    {
        foreach (ErrorCatalog::all() as $code => $meta) {
            $this->assertArrayHasKey('category', $meta, "$code missing category");
            $this->assertArrayHasKey('message', $meta, "$code missing message");
            $this->assertArrayHasKey('hint', $meta, "$code missing hint");
            $this->assertArrayHasKey('recoverable', $meta, "$code missing recoverable flag");
            $this->assertArrayHasKey('doc_slug', $meta, "$code missing doc_slug");
            $this->assertIsBool($meta['recoverable']);
        }
    }

    public function testCategoriesAreFromKnownSet(): void
    {
        $allowed = ['database', 'filesystem', 'archive', 'environment', 'user', 'critical', 'concurrency'];
        foreach (ErrorCatalog::all() as $code => $meta) {
            $this->assertContains($meta['category'], $allowed, "$code has unknown category: {$meta['category']}");
        }
    }

    public function testLookupReturnsNullForMissing(): void
    {
        $this->assertNull(ErrorCatalog::lookup('NO_SUCH_CODE'));
    }

    public function testLookupReturnsMetaForKnown(): void
    {
        $meta = ErrorCatalog::lookup('DISK_FULL');
        $this->assertIsArray($meta);
        $this->assertSame('filesystem', $meta['category']);
    }
}
