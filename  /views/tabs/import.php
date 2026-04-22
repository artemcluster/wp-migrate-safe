<?php if (!defined('ABSPATH')) { exit; } ?>

<h2><?php esc_html_e('Import a backup', 'wp-migrate-safe'); ?></h2>
<p class="description">
    <?php esc_html_e('Select a .wpress file that was previously uploaded (see the Upload tab) and import it into this site.', 'wp-migrate-safe'); ?>
</p>

<div id="wpms-import-form">
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label for="wpms-import-filename"><?php esc_html_e('Backup file', 'wp-migrate-safe'); ?></label>
                </th>
                <td>
                    <select id="wpms-import-filename" name="filename">
                        <option value=""><?php esc_html_e('— Select a backup —', 'wp-migrate-safe'); ?></option>
                    </select>
                    <p class="description" id="wpms-import-loading">
                        <?php esc_html_e('Loading available backups...', 'wp-migrate-safe'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpms-import-source-url"><?php esc_html_e('Source site URL', 'wp-migrate-safe'); ?></label>
                </th>
                <td>
                    <input type="url" id="wpms-import-source-url" name="source_url" class="regular-text"
                           placeholder="https://old-site.example.com" />
                    <p class="description">
                        <?php esc_html_e('URL of the site this backup came from (used for search-replace). Leave blank to skip.', 'wp-migrate-safe'); ?>
                    </p>
                </td>
            </tr>
        </tbody>
    </table>

    <button type="button" id="wpms-import-start" class="button button-primary button-hero" disabled>
        <?php esc_html_e('Start import', 'wp-migrate-safe'); ?>
    </button>

    <p class="notice notice-warning inline" style="margin-top: 12px; display: block;">
        <strong><?php esc_html_e('Warning:', 'wp-migrate-safe'); ?></strong>
        <?php esc_html_e('Importing will overwrite the existing database and content. A snapshot is taken automatically before import begins so you can roll back if needed.', 'wp-migrate-safe'); ?>
    </p>
</div>

<div id="wpms-import-progress" class="wpms-progress" style="display:none; margin-top: 16px">
    <div class="wpms-progress-bar">
        <div class="wpms-progress-fill" style="width:0%"></div>
    </div>
    <p class="wpms-progress-label">
        <span class="wpms-progress-text">0%</span> —
        <span class="wpms-progress-detail"></span>
    </p>
    <button type="button" id="wpms-import-abort" class="button button-secondary">
        <?php esc_html_e('Cancel', 'wp-migrate-safe'); ?>
    </button>
</div>

<div id="wpms-import-result" class="wpms-result" style="display:none"></div>
<div id="wpms-import-error" class="notice notice-error" style="display:none"></div>
