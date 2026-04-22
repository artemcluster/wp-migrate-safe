<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Sql;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Sql\SqlRewriter;

final class SqlRewriterTest extends TestCase
{
    public function testRewritesTablePrefix(): void
    {
        $rewriter = new SqlRewriter('wp_', 'wpnew_');
        $result   = $rewriter->rewrite('INSERT INTO `wp_options` VALUES (1)');

        $this->assertStringContainsString('`wpnew_options`', $result);
        $this->assertStringNotContainsString('`wp_options`', $result);
    }

    public function testRewritesUrl(): void
    {
        $rewriter = new SqlRewriter('wp_', 'wp_', 'https://old.example.com', 'https://new.example.com');
        $result   = $rewriter->rewrite("UPDATE `wp_options` SET option_value='https://old.example.com'");

        $this->assertStringContainsString('https://new.example.com', $result);
        $this->assertStringNotContainsString('https://old.example.com', $result);
    }
}
