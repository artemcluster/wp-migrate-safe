<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMigrateSafe\Import\DryRun\ArchiveValidityCheck;
use WpMigrateSafe\Import\DryRun\DiskSpaceCheck;
use WpMigrateSafe\Import\DryRun\DryRunReport;
use WpMigrateSafe\Import\DryRun\MySqlVersionCheck;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportJob;
use WpMigrateSafe\Import\Snapshot\SnapshotStore;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\JobStore;
use WpMigrateSafe\Job\Exception\JobNotFoundException;
use WpMigrateSafe\Plugin\Paths;

final class ImportController
{
    private const STEP_BUDGET_SECONDS = 20;

    /**
     * POST /import/dry-run { filename, old_url?, new_url? }
     * Runs checks without starting the import job. Returns DryRunReport.
     */
    public function dryRun(WP_REST_Request $request)
    {
        $req = new Request($request);
        $filename = basename($req->getString('filename'));
        $archivePath = Paths::backupsDir() . '/' . $filename;

        if (!is_file($archivePath)) {
            return new WP_Error('wpms_not_found', 'Archive not found: ' . $filename, ['status' => 404]);
        }

        global $wpdb;
        $freeBytes = (int) (@disk_free_space(Paths::backupsDir()) ?: 0);

        $report = DryRunReport::ok()
            ->merge((new DiskSpaceCheck())->run($archivePath, $freeBytes))
            ->merge((new MySqlVersionCheck())->run((string) $wpdb->db_version()))
            ->merge((new ArchiveValidityCheck())->run($archivePath));

        return new WP_REST_Response($report->toArray(), 200);
    }

    public function start(WP_REST_Request $request)
    {
        $req = new Request($request);
        $filename = basename($req->getString('filename'));
        $archivePath = Paths::backupsDir() . '/' . $filename;

        if (!is_file($archivePath)) {
            return new WP_Error('wpms_not_found', 'Archive not found.', ['status' => 404]);
        }

        $job = Job::newImport([
            'archive_path' => $archivePath,
            'filename' => $filename,
            'old_url' => $req->getString('old_url'),
            'new_url' => $req->getString('new_url', home_url()),
            'extract_dir' => Paths::backupsDir() . '/_extract_' . bin2hex(random_bytes(8)),
        ]);

        $this->jobStore()->save($job);

        return new WP_REST_Response([
            'job_id' => $job->id(),
        ], 200);
    }

    public function step(WP_REST_Request $request)
    {
        $req = new Request($request);
        $jobId = $req->getString('job_id');

        try {
            $store = $this->jobStore();
            $job = $store->load($jobId);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }

        if (JobStatus::isTerminal($job->status())) {
            return new WP_REST_Response($job->toArray(), 200);
        }

        $context = $this->contextFromJob($job);

        $job = ImportJob::runSlice($job, $context, self::STEP_BUDGET_SECONDS);
        $store->save($job);

        return new WP_REST_Response($job->toArray(), 200);
    }

    public function status(WP_REST_Request $request)
    {
        $req = new Request($request);
        try {
            $job = $this->jobStore()->load($req->getString('job_id'));
            return new WP_REST_Response($job->toArray(), 200);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }
    }

    public function abort(WP_REST_Request $request)
    {
        $req = new Request($request);
        try {
            $store = $this->jobStore();
            $job = $store->load($req->getString('job_id'));
            if (!JobStatus::isTerminal($job->status())) {
                // If we have a snapshot, run rollback.
                $snapshotId = (string) ($job->meta()['snapshot_id'] ?? '');
                if ($snapshotId !== '') {
                    $context = $this->contextFromJob($job);
                    $snapshot = $context->snapshotStore()->load($snapshotId);
                    (new \WpMigrateSafe\Rollback\RollbackExecutor($context->wpContentDir()))->execute($snapshot);
                }
                $job = $job->withStatus(JobStatus::ABORTED);
                $store->save($job);
            }
            return new WP_REST_Response(['aborted' => true, 'job_id' => $job->id()], 200);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        } catch (\Throwable $e) {
            return new WP_Error('wpms_abort_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    private function jobStore(): JobStore
    {
        return new JobStore(Paths::jobsDir());
    }

    private function contextFromJob(Job $job): ImportContext
    {
        $meta = $job->meta();
        return new ImportContext(
            (string) $meta['archive_path'],
            ABSPATH,
            WP_CONTENT_DIR,
            (string) ($meta['extract_dir'] ?? Paths::backupsDir() . '/_extract_' . $job->id()),
            (string) ($meta['old_url'] ?? ''),
            (string) ($meta['new_url'] ?? home_url()),
            new SnapshotStore(Paths::rollbackDir())
        );
    }
}
