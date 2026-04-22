<?php
/**
 * Plugin Name:       WP Migrate Safe
 * Description:       Reliable WordPress backup/restore without file size limits and with automatic rollback.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Artem Pronin
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wp-migrate-safe
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPMS_VERSION')) {
    define('WPMS_VERSION', '0.1.0');
}

require_once __DIR__ . '/constants.php';

// Composer autoload (bundled in distribution zip).
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use WpMigrateSafe\Plugin\Bootstrap;

add_action('plugins_loaded', static function (): void {
    Bootstrap::instance()->boot();
});

register_activation_hook(__FILE__, static function (): void {
    Bootstrap::instance()->onActivate();
});

register_deactivation_hook(__FILE__, static function (): void {
    Bootstrap::instance()->onDeactivate();
});
