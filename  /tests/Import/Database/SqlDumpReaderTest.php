<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Database;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Database\SqlDumpReader;

final class SqlDumpReaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'wpms_sql_');
    }

    protected function tearDown(): void { @unlink($this->tmp); }

    public function testYieldsStatementsSeparatedBySemicolon(): void
    {
        file_put_contents($this->tmp, <<<SQL
-- comment
CREATE TABLE foo;
INSERT INTO foo VALUES ('a');
INSERT INTO foo VALUES ('b');
SQL);

        $reader = new SqlDumpReader($this->tmp);
        $stmts = iterator_to_array($reader->statements(), false);
        $this->assertCount(3, $stmts);
        $this->assertStringContainsString('CREATE TABLE', $stmts[0]);
        $this->assertStringContainsString("VALUES ('a')", $stmts[1]);
    }

    public function testSkipsBlankLinesAndComments(): void
    {
        file_put_contents($this->tmp, "\n-- header\n\nCREATE TABLE x;\n");
        $reader = new SqlDumpReader($this->tmp);
        $this->assertCount(1, iterator_to_array($reader->statements(), false));
    }
}
