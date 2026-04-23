<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\Steps;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\Steps\ImportDatabase;

/**
 * Covers the small regex-based helper that filters out SET-session statements
 * embedded in SQL dumps, so they don't clobber our FK/unique-check suspension.
 *
 * The method is private; we call it through Reflection because it's worth
 * testing in isolation (these regexes are easy to get wrong, and a false
 * negative would cause FK errors on import — the exact symptom we fixed).
 */
final class ImportDatabaseFiltersTest extends TestCase
{
    /**
     * @dataProvider sessionSetStatements
     */
    public function testRecognisesSessionSetStatementsWeManage(string $stmt): void
    {
        $this->assertTrue($this->invokeFilter($stmt), "Should have filtered: $stmt");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sessionSetStatements(): array
    {
        return [
            'FOREIGN_KEY_CHECKS = 0'           => ['SET FOREIGN_KEY_CHECKS = 0;'],
            'FOREIGN_KEY_CHECKS = 1'           => ['SET FOREIGN_KEY_CHECKS = 1;'],
            'UNIQUE_CHECKS = 0'                => ['SET UNIQUE_CHECKS=0;'],
            'old-var save pattern (mysqldump)' => ['SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;'],
            'old-var save UNIQUE'              => ['SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;'],
            'sql_mode session'                 => ["SET SESSION sql_mode = 'STRICT_TRANS_TABLES';"],
            'sql_mode with old-var'            => ["SET @OLD_SQL_MODE=@@SQL_MODE, sql_mode='NO_AUTO_VALUE_ON_ZERO';"],
            'SET NAMES utf8mb4'                => ['SET NAMES utf8mb4;'],
            'SET CHARACTER SET'                => ['SET CHARACTER SET utf8mb4;'],
            'SET time_zone'                    => ["SET time_zone = '+00:00';"],
            'lowercase set'                    => ['set foreign_key_checks = 0;'],
            'indented'                         => ['   SET FOREIGN_KEY_CHECKS = 0;'],
        ];
    }

    /**
     * @dataProvider passThroughStatements
     */
    public function testLeavesRegularStatementsAlone(string $stmt): void
    {
        $this->assertFalse($this->invokeFilter($stmt), "Should NOT have filtered: $stmt");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function passThroughStatements(): array
    {
        return [
            'CREATE TABLE'           => ['CREATE TABLE `wp_options` (id INT);'],
            'INSERT'                 => ["INSERT INTO `wp_options` VALUES (1, 'foo', 'bar');"],
            'DROP TABLE'             => ['DROP TABLE IF EXISTS `wp_options`;'],
            'UPDATE with SET kw'     => ["UPDATE `wp_options` SET option_value = 'x';"],
            'ALTER TABLE'            => ['ALTER TABLE `wp_options` ADD COLUMN foo INT;'],
            'SET but data not session' => ["SET @row_number = 0;"],
        ];
    }

    private function invokeFilter(string $stmt): bool
    {
        $step = new ImportDatabase();
        $ref = new \ReflectionMethod($step, 'isSessionSetStatement');
        $ref->setAccessible(true);
        return (bool) $ref->invoke($step, $stmt);
    }
}
