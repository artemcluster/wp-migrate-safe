<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportJob;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\JobStore;
use WpMigrateSafe\Job\Exception\JobNotFoundException;
use WpMigrateSafe\Plugin\Paths;

/**
 * REST controller for the import pipeline.
 *
 * Routes (registered in RestRouter):
 *   POST /import/start  — create a new import job from an uploaded .wpress file
 *   POST /import/step   — advance the import job one slice
 *   GET  /import/status — poll job status
 *   POST /import/abort  — abort a running import
 */
final class ImportController
{
    private const STEP_BUDGET_SECONDS = 20;

    public function start(WP_REST_Request $request)
    {
        $req      = new Request($request);
        $filename = $req->getString('filename');

        if ($filename === '') {
            return new WP_Error('wpms_invalid_params', 'Missing filename.', ['status' => 400]);
        }

        // Locate the uploaded file in the backups directory.
        $archivePath = Paths::backupsDir() . '/' . basename($filename);
        if (!is_file($archivePath)) {
            return new WP_Error('wpms_file_not_found', 'Uploaded archive not found: ' . $filename, ['status' => 404]);
        }

        // Prepare an extract directory inside the rollback dir.
        $extractDir = Paths::rollbackDir() . '/_extract_' . bin2hex(random_bytes(8));

        $job = Job::newImport([
            'filename'     => $filename,
            'archive_path' => $archivePath,
            'extract_dir'  => $extractDir,
            'source_prefix' => $req->getString('source_prefix') ?: 'wp_',
            'target_prefix' => $req->getString('target_prefix') ?: (defined('$table_prefix') ? $GLOBALS['table_prefix'] : 'wp_'),
            'source_url'   => $req->getString('source_url'),
            'target_url'   => $req->getString('target_url') ?: get_site_url(),
        ]);

        $this->jobStore()->save($job);

        return new WP_REST_Response([
            'job_id'   => $job->id(),
            'filename' => $filename,
        ], 200);
    }

    public function step(WP_REST_Request $request)
    {
        $req   = new Request($request);
        $jobId = $req->getString('job_id');

        try {
            $store = $this->jobStore();
            $job   = $store->load($jobId);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }

        if (JobStatus::isTerminal($job->status())) {
            return new WP_REST_Response($job->toArray(), 200);
        }

        $context = $this->buildContext($job);
        if ($context === null) {
            return new WP_Error('wpms_invalid_job', 'Missing required meta fields.', ['status' => 400]);
        }

        $job = ImportJob::runSlice($job, $context, self::STEP_BUDGET_SECONDS);
        $store->save($job);

        return new WP_REST_Response($job->toArray(), 200);
    }

    public function status(WP_REST_Request $request)
    {
        $req   = new Request($request);
        $jobId = $req->getString('job_id');
        try {
            $job = $this->jobStore()->load($jobId);
            return new WP_REST_Response($job->toArray(), 200);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }
    }

    public function abort(WP_REST_Request $request)
    {
        $req   = new Request($request);
        $jobId = $req->getString('job_id');

        try {
            $store = $this->jobStore();
            $job   = $store->load($jobId);

            if (!JobStatus::isTerminal($job->status())) {
                $job = $job->withStatus(JobStatus::ABORTED);
                $store->save($job);
            }

            // Clean up extract directory if it exists.
            $extractDir = (string) ($job->meta()['extract_dir'] ?? '');
            if ($extractDir !== '' && is_dir($extractDir)) {
                $this->removeDirectory($extractDir);
            }

            return new WP_REST_Response(['aborted' => true, 'job_id' => $jobId], 200);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }
    }

    private function buildContext(Job $job): ?ImportContext
    {
        $meta = $job->meta();

        $archivePath = (string) ($meta['archive_path'] ?? '');
        $extractDir  = (string) ($meta['extract_dir'] ?? '');

        if ($archivePath === '' || $extractDir === '') {
            return null;
        }

        return new ImportContext(
            $archivePath,
            ABSPATH,
            WP_CONTENT_DIR,
            Paths::rollbackDir(),
            $extractDir,
            (string) ($meta['source_prefix'] ?? 'wp_'),
            (string) ($meta['target_prefix'] ?? 'wp_'),
            (string) ($meta['source_url'] ?? ''),
            (string) ($meta['target_url'] ?? get_site_url())
        );
    }

    private function jobStore(): JobStore
    {
        return new JobStore(Paths::jobsDir());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
