<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Cron;

use WP_UnitTestCase;
use WpMigrateSafe\Cron\Cleanup;

/**
 * Run with: ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class CleanupTest extends WP_UnitTestCase
{
    public function testSchedulingRegistersHook(): void
    {
        Cleanup::unschedule();
        Cleanup::schedule();
        $this->assertNotFalse(wp_next_scheduled(Cleanup::HOOK));
        Cleanup::unschedule();
    }

    public function testUnscheduleRemovesHook(): void
    {
        Cleanup::schedule();
        Cleanup::unschedule();
        $this->assertFalse(wp_next_scheduled(Cleanup::HOOK));
    }

    public function testRunRemovesOldExtractDirs(): void
    {
        $base = \WpMigrateSafe\Plugin\Paths::backupsDir();
        $old = $base . '/_extract_old_' . uniqid();
        mkdir($old);
        touch($old, time() - 48 * 3600);

        $fresh = $base . '/_extract_fresh_' . uniqid();
        mkdir($fresh);

        Cleanup::run();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($fresh);

        @rmdir($fresh);
    }
}
