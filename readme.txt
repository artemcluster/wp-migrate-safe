=== WP Migrate Safe ===
Contributors: artempronin
Tags: migration, backup, restore, .wpress
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Reliable WordPress backup and restore with no file size limits, automatic rollback on failure, and clear error messages — not silent hangs.

== Description ==

Alpha release (Plan 3 complete): chunked upload of large .wpress backup files.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate via the Plugins menu.
3. Go to Tools → Migrate Safe.

== Changelog ==

= 0.1.0 =
* Alpha: plugin skeleton, chunked upload via REST API, admin UI.

== Running integration tests ==

Integration tests require the WordPress test suite:

    git clone --depth=1 https://github.com/WordPress/wordpress-develop.git /tmp/wordpress-develop
    export WP_TESTS_DIR=/tmp/wordpress-develop/tests/phpunit
    ./vendor/bin/phpunit -c phpunit-wp.xml.dist

Or via wp-env (recommended):

    npx wp-env start
    wp-env run tests-cli ./vendor/bin/phpunit -c phpunit-wp.xml.dist
