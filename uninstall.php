<?php
/**
 * Uninstall handler. Fires when the user deletes the plugin from wp-admin.
 *
 * We deliberately DO NOT remove the backups directory — user data is sacred.
 * We only clear options and transients that we created.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clear any options we may have created (none in v1.0 MVP).
// delete_option('wpms_example_option');

// Clear transients matching our prefix.
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wpms\_%' ESCAPE '\\\\'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_wpms\_%' ESCAPE '\\\\'");
