<?php
declare(strict_types=1);

namespace WpMigrateSafe\Plugin;

final class AdminMenu
{
    public const PAGE_SLUG = 'wp-migrate-safe';

    public static function register(): void
    {
        add_management_page(
            __('Migrate Safe', 'wp-migrate-safe'),
            __('Migrate Safe', 'wp-migrate-safe'),
            WPMS_CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(__('Insufficient permissions.', 'wp-migrate-safe'));
        }
        include WPMS_PATH . 'views/admin-page.php';
    }

    public static function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'tools_page_' . self::PAGE_SLUG) {
            return;
        }
        wp_enqueue_style(
            'wpms-admin',
            WPMS_URL . 'assets/css/admin.css',
            [],
            WPMS_VERSION
        );
        wp_enqueue_script(
            'wpms-admin',
            WPMS_URL . 'assets/js/admin.js',
            [],
            WPMS_VERSION,
            true
        );
        wp_enqueue_script(
            'wpms-upload',
            WPMS_URL . 'assets/js/upload.js',
            ['wpms-admin'],
            WPMS_VERSION,
            true
        );
        wp_localize_script('wpms-upload', 'WPMS', [
            'restUrl' => rest_url(WPMS_REST_NAMESPACE . '/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'chunkSize' => WPMS_CHUNK_SIZE,
        ]);
    }
}
