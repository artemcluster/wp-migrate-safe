<?php
declare(strict_types=1);

namespace WpMigrateSafe\Plugin;

use WpMigrateSafe\Rest\RestRouter;

/**
 * Plugin lifecycle & hook registration.
 */
final class Bootstrap
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_action('rest_api_init', [RestRouter::class, 'registerRoutes']);
        add_action('admin_menu', [AdminMenu::class, 'register']);
        add_action('admin_enqueue_scripts', [AdminMenu::class, 'enqueueAssets']);
    }

    public function onActivate(): void
    {
        // Ensure directories exist immediately so web-accessible .htaccess is in place.
        Paths::backupsDir();
        Paths::uploadsTmpDir();
        Paths::jobsDir();
        Paths::rollbackDir();
    }

    public function onDeactivate(): void
    {
        // Intentionally leave backups in place — they are user data.
    }
}
