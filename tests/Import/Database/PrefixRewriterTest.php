<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Database;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Database\PrefixRewriter;

final class PrefixRewriterTest extends TestCase
{
    public function testRewritesBacktickedTableName(): void
    {
        $rw = new PrefixRewriter('wpsp_', 'wp_');
        $this->assertSame(
            'CREATE TABLE `wp_options` (id INT);',
            $rw->rewrite('CREATE TABLE `wpsp_options` (id INT);')
        );
    }

    public function testRewritesMultipleOccurrencesOnOneStatement(): void
    {
        $rw = new PrefixRewriter('wpsp_', 'wp_');
        $sql = "INSERT INTO `wpsp_postmeta` (meta_id, post_id) SELECT id FROM `wpsp_posts`;";
        $expected = "INSERT INTO `wp_postmeta` (meta_id, post_id) SELECT id FROM `wp_posts`;";
        $this->assertSame($expected, $rw->rewrite($sql));
    }

    public function testLeavesStringLiteralsUntouched(): void
    {
        $rw = new PrefixRewriter('wpsp_', 'wp_');
        $sql = "INSERT INTO `wpsp_options` VALUES ('1', 'wpsp_pointers_dismissed', 'a:0:{}');";
        $expected = "INSERT INTO `wp_options` VALUES ('1', 'wpsp_pointers_dismissed', 'a:0:{}');";
        $this->assertSame($expected, $rw->rewrite($sql));
    }

    public function testNoOpWhenPrefixesAreEqual(): void
    {
        $rw = new PrefixRewriter('wp_', 'wp_');
        $this->assertTrue($rw->isNoOp());
        $sql = "CREATE TABLE `wp_options` (id INT);";
        $this->assertSame($sql, $rw->rewrite($sql));
    }

    public function testNoOpWhenSourcePrefixIsEmpty(): void
    {
        $rw = new PrefixRewriter('', 'wp_');
        $this->assertTrue($rw->isNoOp());
        $this->assertSame('raw sql', $rw->rewrite('raw sql'));
    }

    public function testHandlesLongerPrefix(): void
    {
        $rw = new PrefixRewriter('my_custom_', 'wp_');
        $this->assertSame(
            "CREATE TABLE `wp_options` (id INT);",
            $rw->rewrite("CREATE TABLE `my_custom_options` (id INT);")
        );
    }

    public function testHandlesShorterPrefix(): void
    {
        $rw = new PrefixRewriter('wp_', 'wordpress_site1_');
        $this->assertSame(
            "CREATE TABLE `wordpress_site1_options`;",
            $rw->rewrite("CREATE TABLE `wp_options`;")
        );
    }

    public function testDoesNotRewriteTablesWithDifferentPrefix(): void
    {
        $rw = new PrefixRewriter('wpsp_', 'wp_');
        $sql = "CREATE TABLE `otherprefix_data` (id INT);";
        $this->assertSame($sql, $rw->rewrite($sql));
    }

    public function testDoesNotAffectPrefixSubstringInsideOtherIdentifier(): void
    {
        // `some_wpsp_thing` — substring of prefix but not at start
        $rw = new PrefixRewriter('wpsp_', 'wp_');
        $sql = "CREATE TABLE `some_wpsp_thing` (id INT);";
        $this->assertSame($sql, $rw->rewrite($sql));
    }
}
