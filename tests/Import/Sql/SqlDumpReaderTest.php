<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Sql;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Sql\SqlDumpReader;

final class SqlDumpReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_sql_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function writeDump(string $sql): string
    {
        $path = $this->dir . '/dump.sql';
        file_put_contents($path, $sql);
        return $path;
    }

    public function testReadsMultipleStatements(): void
    {
        $path   = $this->writeDump("CREATE TABLE foo (id INT);\nINSERT INTO foo VALUES (1);\n");
        $reader = new SqlDumpReader($path);
        $stmts  = iterator_to_array($reader->statements());

        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('CREATE TABLE', $stmts[0]);
        $this->assertStringContainsString('INSERT INTO', $stmts[1]);
    }

    public function testSkipsSingleLineComments(): void
    {
        $path   = $this->writeDump("-- comment\nSELECT 1;\n");
        $reader = new SqlDumpReader($path);
        $stmts  = iterator_to_array($reader->statements());

        $this->assertCount(1, $stmts);
        $this->assertSame('SELECT 1', $stmts[0]);
    }

    public function testHandlesQuotedSemicolon(): void
    {
        $path   = $this->writeDump("INSERT INTO t VALUES ('a;b');\n");
        $reader = new SqlDumpReader($path);
        $stmts  = iterator_to_array($reader->statements());

        $this->assertCount(1, $stmts);
        $this->assertStringContainsString("'a;b'", $stmts[0]);
    }

    public function testThrowsForMissingFile(): void
    {
        $reader = new SqlDumpReader('/nonexistent/file.sql');
        $this->expectException(\RuntimeException::class);
        iterator_to_array($reader->statements());
    }
}
