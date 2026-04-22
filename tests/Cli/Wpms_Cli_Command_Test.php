<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Cli;

use WP_UnitTestCase;

/**
 * Smoke test: registers the CLI command so we get a syntax/autoload error early
 * in CI even if we can't invoke WP-CLI proper here.
 *
 * Run with: ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class Wpms_Cli_Command_Test extends WP_UnitTestCase
{
    public function testCliClassLoadsWithoutFatal(): void
    {
        $this->assertTrue(class_exists(\WpMigrateSafe\Cli\Wpms_Cli_Command::class));
    }
}
