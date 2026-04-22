<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Database;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Database\SqlRewriter;
use WpMigrateSafe\SearchReplace\Replacer;

final class SqlRewriterTest extends TestCase
{
    public function testInsertRewritesUrlInsideStringLiteral(): void
    {
        $rewriter = new SqlRewriter(new Replacer('http://old.com', 'https://new.com'));
        $sql = "INSERT INTO wp_options VALUES (1, 'siteurl', 'http://old.com', 'yes');";
        $out = $rewriter->rewrite($sql);
        $this->assertStringContainsString("'https://new.com'", $out);
        $this->assertStringNotContainsString('http://old.com', $out);
    }

    public function testCreateTableIsNotTouched(): void
    {
        $rewriter = new SqlRewriter(new Replacer('http://old.com', 'https://new.com'));
        $sql = "CREATE TABLE `wp_options` ( ... );";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    public function testNoOpWhenSearchNotFound(): void
    {
        $rewriter = new SqlRewriter(new Replacer('http://old.com', 'https://new.com'));
        $sql = "INSERT INTO foo VALUES ('unrelated');";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }
}
