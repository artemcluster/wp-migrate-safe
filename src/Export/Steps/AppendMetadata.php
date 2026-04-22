<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export\Steps;

use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Write a small metadata.json file into the archive root describing the source site.
 *
 * ArchiveInspector reads this first so restore UI can auto-fill the "Source site URL"
 * input without scanning the SQL dump.
 */
final class AppendMetadata implements ExportStep
{
    public function name(): string { return 'append-metadata'; }

    public function run(ExportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        global $wpdb;
        $sourcePrefix = isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '';

        $payload = [
            'format_version' => 1,
            'created_at'     => gmdate('c'),
            'siteurl'        => function_exists('get_option') ? (string) get_option('siteurl', '') : '',
            'home'           => function_exists('get_option') ? (string) get_option('home', '') : '',
            'source_prefix'  => $sourcePrefix,
            'wp_version'     => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
            'blog_charset'   => function_exists('get_bloginfo') ? (string) get_bloginfo('charset') : '',
            'language'       => function_exists('get_bloginfo') ? (string) get_bloginfo('language') : '',
            'multisite'      => function_exists('is_multisite') && is_multisite(),
            'php_version'    => PHP_VERSION,
            'plugin_version' => defined('WPMS_VERSION') ? (string) WPMS_VERSION : '',
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $json = '{}';
        }

        $context->archiveWriter()->appendBytes('metadata.json', '', $json);

        return StepResult::complete(100, 'Metadata written.', ['metadata_written' => true]);
    }
}
