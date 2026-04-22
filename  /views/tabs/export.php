<?php if (!defined('ABSPATH')) { exit; } ?>

<h2><?php esc_html_e('Export this site', 'wp-migrate-safe'); ?></h2>
<p class="description">
    <?php esc_html_e('Creates a .wpress backup containing the database, plugins, themes, and uploads. The file will appear in the Backups tab when complete.', 'wp-migrate-safe'); ?>
</p>

<button type="button" id="wpms-export-start" class="button button-primary">
    <?php esc_html_e('Start export', 'wp-migrate-safe'); ?>
</button>

<div id="wpms-export-progress" class="wpms-progress" style="display:none; margin-top: 16px">
    <div class="wpms-progress-bar">
        <div class="wpms-progress-fill" style="width:0%"></div>
    </div>
    <p class="wpms-progress-label">
        <span class="wpms-progress-text">0%</span> —
        <span class="wpms-progress-detail"></span>
    </p>
    <button type="button" id="wpms-export-abort" class="button button-secondary">
        <?php esc_html_e('Cancel', 'wp-migrate-safe'); ?>
    </button>
</div>

<div id="wpms-export-result" class="wpms-result" style="display:none"></div>
<div id="wpms-export-error" class="notice notice-error" style="display:none"></div>
