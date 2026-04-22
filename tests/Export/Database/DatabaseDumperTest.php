<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Export\Database;

use WP_UnitTestCase;
use WpMigrateSafe\Export\Database\DatabaseDumper;

/**
 * Integration test — run with:
 *   ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class DatabaseDumperTest extends WP_UnitTestCase
{
    public function testDumpsAllWpTablesProducingValidSql(): void
    {
        $target = sys_get_temp_dir() . '/wpms_dump_' . uniqid() . '.sql';

        $dumper = new DatabaseDumper();
        $cursor = [];
        $totalBytes = 0;

        while (true) {
            $cursor = $dumper->dumpChunk($target, $cursor, 2); // 2 second budget per chunk
            $totalBytes = filesize($target);
            if ($cursor['done']) break;
        }

        $this->assertGreaterThan(1000, $totalBytes);
        $sql = file_get_contents($target);
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);

        unlink($target);
    }

    public function testResumeFromCursor(): void
    {
        $target = sys_get_temp_dir() . '/wpms_resume_' . uniqid() . '.sql';
        $dumper = new DatabaseDumper();

        // First chunk.
        $cursor = $dumper->dumpChunk($target, [], 1);
        $this->assertFalse($cursor['done'], 'More work should remain for non-trivial DB.');
        $bytesAfter1 = filesize($target);

        // Second chunk picks up where we left off.
        $cursor = $dumper->dumpChunk($target, $cursor, 5);
        $bytesAfter2 = filesize($target);

        $this->assertGreaterThan($bytesAfter1, $bytesAfter2);

        unlink($target);
    }
}
