<?php
declare(strict_types=1);

namespace WpMigrateSafe\Cli;

use WP_CLI;
use WP_CLI_Command;
use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportJob;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportJob;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\JobStore;
use WpMigrateSafe\Plugin\Paths;

/**
 * WP-CLI commands for wp-migrate-safe.
 *
 * Unlike browser-driven runs, CLI has no HTTP budget; each slice gets 60 seconds
 * and loops until the job reaches a terminal state.
 */
class Wpms_Cli_Command extends WP_CLI_Command
{
    private const CLI_BUDGET_SECONDS = 60;

    /**
     * Export the current site to a .wpress backup.
     *
     * ## OPTIONS
     *
     * [--output=<path>]
     * : Write the archive to this path instead of the default backups directory.
     *
     * ## EXAMPLES
     *
     *     wp migrate-safe export
     *     wp migrate-safe export --output=/tmp/site-backup.wpress
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function export(array $args, array $assoc_args): void
    {
        $archive = isset($assoc_args['output'])
            ? (string) $assoc_args['output']
            : Paths::backupsDir() . '/' . $this->generateFilename();

        $job = Job::newExport(['archive_path' => $archive]);
        $store = $this->jobStore();
        $store->save($job);

        $context = new ExportContext($archive, ABSPATH, WP_CONTENT_DIR);

        WP_CLI::log('Starting export to: ' . $archive);
        while (!JobStatus::isTerminal($job->status())) {
            $job = ExportJob::runSlice($job, $context, self::CLI_BUDGET_SECONDS);
            $store->save($job);
            WP_CLI::log(sprintf(
                '[%d%%] step=%d status=%s',
                $job->progress(),
                $job->stepIndex(),
                $job->status()
            ));
        }

        if ($job->status() === JobStatus::FAILED) {
            WP_CLI::error(sprintf(
                'Export failed (code=%s): %s',
                $job->error()['code'] ?? 'UNKNOWN',
                $job->error()['message'] ?? ''
            ));
        }

        WP_CLI::success('Export completed: ' . $archive);
    }

    /**
     * Restore a site from a .wpress backup.
     *
     * ## OPTIONS
     *
     * <filename>
     * : Backup filename under the backups directory, or absolute path.
     *
     * [--old-url=<url>]
     * : Old site URL to replace in the database (e.g. http://old.com).
     *
     * [--new-url=<url>]
     * : New site URL (defaults to home_url()).
     *
     * [--no-rollback]
     * : On failure, do NOT automatically rollback. Use with caution.
     *
     * ## EXAMPLES
     *
     *     wp migrate-safe import site.wpress --old-url=http://old.com --new-url=https://new.com
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function import(array $args, array $assoc_args): void
    {
        $filename = (string) $args[0];
        $archive = is_file($filename) ? $filename : Paths::backupsDir() . '/' . basename($filename);
        if (!is_file($archive)) {
            WP_CLI::error('Archive not found: ' . $archive);
        }

        $oldUrl = (string) ($assoc_args['old-url'] ?? '');
        $newUrl = (string) ($assoc_args['new-url'] ?? home_url());

        // Detect source prefix from archive metadata / SQL dump.
        $sourcePrefix = '';
        try {
            $inspect = (new \WpMigrateSafe\Import\ArchiveInspector())->inspect($archive);
            $sourcePrefix = (string) ($inspect['source_prefix'] ?? '');
        } catch (\Throwable $e) { /* non-fatal */ }

        $job = Job::newImport([
            'archive_path' => $archive,
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'source_prefix' => $sourcePrefix,
            'extract_dir' => Paths::backupsDir() . '/_extract_' . bin2hex(random_bytes(8)),
        ]);
        $store = $this->jobStore();
        $store->save($job);

        $context = new ImportContext(
            $archive, ABSPATH, WP_CONTENT_DIR,
            (string) $job->meta()['extract_dir'],
            $oldUrl, $newUrl,
            $sourcePrefix
        );

        WP_CLI::log('Starting import of ' . basename($archive));
        while (!JobStatus::isTerminal($job->status())) {
            $job = ImportJob::runSlice($job, $context, self::CLI_BUDGET_SECONDS);
            $store->save($job);
            WP_CLI::log(sprintf(
                '[%d%%] step=%d status=%s',
                $job->progress(),
                $job->stepIndex(),
                $job->status()
            ));
        }

        if ($job->status() === JobStatus::FAILED) {
            $err = $job->error();
            WP_CLI::error(sprintf(
                'Import failed (code=%s): %s%s',
                $err['code'] ?? 'UNKNOWN',
                $err['message'] ?? '',
                ($err['context']['rolled_back'] ?? false) ? ' — rollback succeeded.' : ''
            ));
        }

        WP_CLI::success('Import completed.');
    }

    /**
     * Show job status.
     *
     * ## OPTIONS
     *
     * [<job_id>]
     * : Specific job id. If omitted, lists all active jobs.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function status(array $args, array $assoc_args): void
    {
        $store = $this->jobStore();

        if (empty($args)) {
            $active = $store->findActive();
            if (empty($active)) {
                WP_CLI::log('No active jobs.');
                return;
            }
            foreach ($active as $job) {
                WP_CLI::log(sprintf(
                    '%s  kind=%-6s  step=%d  progress=%d%%  status=%s',
                    $job->id(),
                    $job->kind(),
                    $job->stepIndex(),
                    $job->progress(),
                    $job->status()
                ));
            }
            return;
        }

        try {
            $job = $store->load((string) $args[0]);
        } catch (\Throwable $e) {
            WP_CLI::error('Job not found: ' . $args[0]);
        }

        WP_CLI::log(json_encode($job->toArray(), JSON_PRETTY_PRINT));
    }

    /**
     * List backup files in the backups directory.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function backups(array $args, array $assoc_args): void
    {
        $dir = Paths::backupsDir();
        $items = [];
        foreach ((array) glob($dir . '/*.wpress') as $path) {
            $items[] = [
                'filename' => basename($path),
                'size' => filesize($path),
                'mtime' => gmdate('Y-m-d H:i', filemtime($path)),
            ];
        }
        if (empty($items)) {
            WP_CLI::log('No backups in ' . $dir);
            return;
        }
        WP_CLI\Utils\format_items('table', $items, ['filename', 'size', 'mtime']);
    }

    private function jobStore(): JobStore
    {
        return new JobStore(Paths::jobsDir());
    }

    private function generateFilename(): string
    {
        $siteName = sanitize_title(get_bloginfo('name')) ?: 'site';
        return sprintf('%s-%s.wpress', $siteName, gmdate('Y-m-d-His'));
    }
}
