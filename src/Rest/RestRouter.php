<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

/**
 * Central registry for all REST routes exposed by the plugin.
 *
 * Called on `rest_api_init`.
 */
final class RestRouter
{
    public static function registerRoutes(): void
    {
        $ns = WPMS_REST_NAMESPACE;

        $upload = new UploadController();

        register_rest_route($ns, '/upload/init', [
            'methods'             => 'POST',
            'callback'            => [$upload, 'init'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'filename' => ['type' => 'string', 'required' => true],
                'total_size' => ['type' => 'integer', 'required' => true],
                'sha256' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route($ns, '/upload/chunk', [
            'methods'             => 'POST',
            'callback'            => [$upload, 'chunk'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'upload_id' => ['type' => 'string', 'required' => true],
                'chunk_index' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route($ns, '/upload/complete', [
            'methods'             => 'POST',
            'callback'            => [$upload, 'complete'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'upload_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route($ns, '/upload/abort', [
            'methods'             => 'POST',
            'callback'            => [$upload, 'abort'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'upload_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        $backups = new BackupsController();

        register_rest_route($ns, '/backups', [
            'methods'             => 'GET',
            'callback'            => [$backups, 'index'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route($ns, '/backups/(?P<filename>[^/]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$backups, 'delete'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route($ns, '/backups/download', [
            'methods'             => 'GET',
            'callback'            => [$backups, 'download'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => ['filename' => ['type' => 'string', 'required' => true]],
        ]);

        $export = new ExportController();

        register_rest_route($ns, '/export/start', [
            'methods' => 'POST',
            'callback' => [$export, 'start'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route($ns, '/export/step', [
            'methods' => 'POST',
            'callback' => [$export, 'step'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => ['job_id' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route($ns, '/export/status', [
            'methods' => 'GET',
            'callback' => [$export, 'status'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => ['job_id' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route($ns, '/export/abort', [
            'methods' => 'POST',
            'callback' => [$export, 'abort'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => ['job_id' => ['type' => 'string', 'required' => true]],
        ]);

        $import = new ImportController();

        register_rest_route($ns, '/import/inspect', [
            'methods'             => 'GET',
            'callback'            => [$import, 'inspect'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => ['filename' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route($ns, '/import/start', [
            'methods'             => 'POST',
            'callback'            => [$import, 'start'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'filename' => ['type' => 'string', 'required' => true],
                'old_url'  => ['type' => 'string', 'required' => false],
                'new_url'  => ['type' => 'string', 'required' => false],
            ],
        ]);

        register_rest_route($ns, '/import/step', [
            'methods'             => 'POST',
            'callback'            => [$import, 'step'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => ['job_id' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route($ns, '/import/status', [
            'methods'             => 'GET',
            'callback'            => [$import, 'status'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => ['job_id' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route($ns, '/import/abort', [
            'methods'             => 'POST',
            'callback'            => [$import, 'abort'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => ['job_id' => ['type' => 'string', 'required' => true]],
        ]);
    }

    public static function checkPermission(): bool
    {
        return current_user_can(WPMS_CAPABILITY);
    }
}
