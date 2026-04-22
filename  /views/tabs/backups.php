<?php if (!defined('ABSPATH')) { exit; } ?>

<h2><?php esc_html_e('Existing backups', 'wp-migrate-safe'); ?></h2>
<p class="description">
    <?php
    echo esc_html(sprintf(
        /* translators: %s: path */
        __('Files stored in %s', 'wp-migrate-safe'),
        \WpMigrateSafe\Plugin\Paths::backupsDir()
    ));
    ?>
</p>

<table class="widefat striped" id="wpms-backups-table">
    <thead>
        <tr>
            <th><?php esc_html_e('Filename', 'wp-migrate-safe'); ?></th>
            <th><?php esc_html_e('Size', 'wp-migrate-safe'); ?></th>
            <th><?php esc_html_e('Modified', 'wp-migrate-safe'); ?></th>
            <th><?php esc_html_e('Valid', 'wp-migrate-safe'); ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <tr><td colspan="5"><?php esc_html_e('Loading…', 'wp-migrate-safe'); ?></td></tr>
    </tbody>
</table>
