<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WPMS_FILE', __DIR__ . '/wp-migrate-safe.php');
define('WPMS_PATH', __DIR__ . '/');
define('WPMS_URL', plugin_dir_url(WPMS_FILE));
define('WPMS_REST_NAMESPACE', 'wp-migrate-safe/v1');
define('WPMS_CAPABILITY', 'manage_options');

// 5 MB chunks — always below the typical upload_max_filesize=8M on shared hosting.
define('WPMS_CHUNK_SIZE', 5 * 1024 * 1024);

// Directory under wp-content where backups and upload tmp files live.
define('WPMS_BACKUPS_SUBDIR', 'backups/wp-migrate-safe');
