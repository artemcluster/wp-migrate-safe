<?php
/**
 * Bootstrap for WordPress-integration PHPUnit runs.
 *
 * Expects the WP test suite to be installed via bin/install-wp-tests.sh or wp-env.
 * The environment variable WP_TESTS_DIR must point at the tests/phpunit folder
 * of a cloned wordpress-develop repository.
 */
declare(strict_types=1);

$testsDir = getenv('WP_TESTS_DIR');
if ($testsDir === false || !is_dir($testsDir)) {
    fwrite(STDERR, "WP_TESTS_DIR is not set or does not point to a valid directory.\n");
    fwrite(STDERR, "Install WP test suite with: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n");
    exit(1);
}

require_once $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require __DIR__ . '/../wp-migrate-safe.php';
});

require $testsDir . '/includes/bootstrap.php';
require __DIR__ . '/../vendor/autoload.php';
