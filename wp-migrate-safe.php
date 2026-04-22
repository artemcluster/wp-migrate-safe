<?php
/**
 * Plugin Name:       WP Migrate Safe
 * Plugin URI:        https://github.com/artemcluster/wp-migrate-safe
 * Description:       Reliable WordPress backup & restore in the .wpress format. No file-size limits on upload, automatic database-prefix rewriting, typed error messages. Interoperable with All-in-One WP Migration archives.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Artem Pronin
 * Author URI:        https://github.com/artemcluster
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wp-migrate-safe
 * Domain Path:       /languages
 *
 * WP Migrate Safe — open-source WordPress migration tool.
 * Copyright (C) 2026 Artem Pronin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The .wpress archive format was originally developed by ServMask Inc.
 * for their All-in-One WP Migration plugin. This plugin implements the
 * format independently from the published specification; no ServMask
 * code is bundled. This plugin is not affiliated with ServMask Inc.
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
    load_plugin_textdomain('wp-migrate-safe', false, dirname(plugin_basename(__FILE__)) . '/languages');
    Bootstrap::instance()->boot();
});

register_activation_hook(__FILE__, static function (): void {
    Bootstrap::instance()->onActivate();
});

register_deactivation_hook(__FILE__, static function (): void {
    Bootstrap::instance()->onDeactivate();
});
