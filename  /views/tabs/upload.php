<?php if (!defined('ABSPATH')) { exit; } ?>

<h2><?php esc_html_e('Upload backup file', 'wp-migrate-safe'); ?></h2>
<p class="description">
    <?php esc_html_e('Upload a .wpress backup file. Large files are uploaded in 5 MB chunks, so PHP upload limits do not apply.', 'wp-migrate-safe'); ?>
</p>

<div id="wpms-upload-dropzone" class="wpms-dropzone">
    <p><?php esc_html_e('Drag a .wpress file here, or click to select.', 'wp-migrate-safe'); ?></p>
    <input type="file" id="wpms-upload-input" accept=".wpress" style="display:none" />
</div>

<div id="wpms-upload-progress" class="wpms-progress" style="display:none">
    <div class="wpms-progress-bar">
        <div class="wpms-progress-fill" style="width:0%"></div>
    </div>
    <p class="wpms-progress-label"><span class="wpms-progress-text">0%</span> — <span class="wpms-progress-detail"></span></p>
    <button type="button" id="wpms-upload-abort" class="button button-secondary">
        <?php esc_html_e('Cancel', 'wp-migrate-safe'); ?>
    </button>
</div>

<div id="wpms-upload-result" class="wpms-result" style="display:none"></div>
<div id="wpms-upload-error" class="wpms-error notice notice-error" style="display:none"></div>
