<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMigrateSafe\Import\ArchiveInspector;
use WpMigrateSafe\Import\DryRun\ArchiveValidityCheck;
use WpMigrateSafe\Import\DryRun\DiskSpaceCheck;
use WpMigrateSafe\Import\DryRun\DryRunReport;
use WpMigrateSafe\Import\DryRun\MySqlVersionCheck;
use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportJob;
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

    /**
     * GET /import/inspect?filename=xxx.wpress
     * Returns detected source URLs from the archive (metadata.json fast path + SQL dump fallback).
     */
    public function inspect(WP_REST_Request $request)
    {
        $req = new Request($request);
        $filename = basename($req->getString('filename'));
        $archivePath = Paths::backupsDir() . '/' . $filename;

        if ($filename === '' || !is_file($archivePath)) {
            return new WP_Error('wpms_not_found', 'Archive not found.', ['status' => 404]);
        }

        try {
            $detected = (new ArchiveInspector())->inspect($archivePath);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['source_url' => '', 'home_url' => '', 'error' => $e->getMessage()], 200);
        }

        return new WP_REST_Response($detected, 200);
    }

    public function start(WP_REST_Request $request)
    {
        $req = new Request($request);
        $filename = basename($req->getString('filename'));
        $archivePath = Paths::backupsDir() . '/' . $filename;

        if (!is_file($archivePath)) {
            return new WP_Error('wpms_not_found', 'Archive not found.', ['status' => 404]);
        }

        // Detect source DB prefix so ImportDatabase can rewrite table identifiers
        // to match the target site's prefix. Without this, a wpsp_ archive can't be
        // imported into a wp_ site and vice versa.
        $detected = [];
        try {
            $detected = (new ArchiveInspector())->inspect($archivePath);
        } catch (\Throwable $e) {
            // Non-fatal — fall back to assuming prefix matches.
        }

        $job = Job::newImport([
            'archive_path' => $archivePath,
            'filename' => $filename,
            'old_url' => $req->getString('old_url'),
            'new_url' => $req->getString('new_url', home_url()),
            'source_prefix' => (string) ($detected['source_prefix'] ?? ''),
            'extract_dir' => Paths::backupsDir() . '/_extract_' . bin2hex(random_bytes(8)),
        ]);

        $store = $this->jobStore();
        $store->save($job);

        \WpMigrateSafe\Concurrency\ZombieLock::release($store);

        try {
            \WpMigrateSafe\Concurrency\GlobalLock::acquire($job->id());
        } catch (\WpMigrateSafe\Concurrency\Exception\LockHeldException $e) {
            $store->delete($job->id());
            return \WpMigrateSafe\Errors\ErrorResponse::fromCode(
                'GLOBAL_LOCK_HELD',
                409,
                ['holder_job_id' => $e->holderJobId()]
            );
        }

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

        if (\WpMigrateSafe\Job\JobStatus::isTerminal($job->status())) {
            \WpMigrateSafe\Concurrency\GlobalLock::release($job->id());
        }

        return new WP_REST_Response($job->toArray(), 200);
    }

    public function status(WP_REST_Request $request)
    {
        $req = new Request($request);
        try {
            $job = $this->jobStore()->load($req->getString('job_id'));
            $data = $job->toArray();
            $data['stale'] = \WpMigrateSafe\Progress\Heartbeat::isStale($job);
            return new WP_REST_Response($data, 200);
        } catch (\WpMigrateSafe\Job\Exception\JobNotFoundException $e) {
            return \WpMigrateSafe\Errors\ErrorResponse::fromCode('STEP_TIMEOUT', 404, ['job_id' => $req->getString('job_id')]);
        }
    }

    public function abort(WP_REST_Request $request)
    {
        $req = new Request($request);
        try {
            $store = $this->jobStore();
            $job = $store->load($req->getString('job_id'));
            if (!JobStatus::isTerminal($job->status())) {
                $job = $job->withStatus(JobStatus::ABORTED);
                $store->save($job);
                \WpMigrateSafe\Concurrency\GlobalLock::release($job->id());
            }
            // Best-effort cleanup of extract tmp.
            $extractDir = (string) ($job->meta()['extract_dir'] ?? '');
            if ($extractDir !== '' && is_dir($extractDir) && strpos($extractDir, '_extract_') !== false) {
                $this->rmTree($extractDir);
            }
            return new WP_REST_Response(['aborted' => true, 'job_id' => $job->id()], 200);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
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
            (string) ($meta['source_prefix'] ?? '')
        );
    }
}
