<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportJob;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Job\JobStore;
use WpMigrateSafe\Job\Exception\JobNotFoundException;
use WpMigrateSafe\Plugin\Paths;

final class ExportController
{
    private const STEP_BUDGET_SECONDS = 20;

    public function start(WP_REST_Request $request)
    {
        try {
            \WpMigrateSafe\Concurrency\GlobalLock::acquire('pending-' . bin2hex(random_bytes(8)));
        } catch (\WpMigrateSafe\Concurrency\Exception\LockHeldException $e) {
            return \WpMigrateSafe\Errors\ErrorResponse::fromCode(
                'GLOBAL_LOCK_HELD',
                409,
                ['holder_job_id' => $e->holderJobId()]
            );
        }

        $filename = $this->generateFilename();
        $archivePath = Paths::backupsDir() . '/' . $filename;

        $job = Job::newExport([
            'filename' => $filename,
            'archive_path' => $archivePath,
        ]);

        $this->jobStore()->save($job);
        \WpMigrateSafe\Concurrency\GlobalLock::acquire($job->id());

        return new WP_REST_Response([
            'job_id' => $job->id(),
            'filename' => $filename,
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

        $archivePath = (string) ($job->meta()['archive_path'] ?? '');
        if ($archivePath === '') {
            return new WP_Error('wpms_invalid_job', 'Missing archive_path in job meta', ['status' => 400]);
        }

        $context = new ExportContext($archivePath, ABSPATH, WP_CONTENT_DIR);
        $job = ExportJob::runSlice($job, $context, self::STEP_BUDGET_SECONDS);
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
        $jobId = $req->getString('job_id');
        try {
            $store = $this->jobStore();
            $job = $store->load($jobId);
            if (!JobStatus::isTerminal($job->status())) {
                $job = $job->withStatus(JobStatus::ABORTED);
                $store->save($job);
            }
            // Clean up partial archive file.
            $archivePath = (string) ($job->meta()['archive_path'] ?? '');
            if ($archivePath !== '' && is_file($archivePath)) {
                @unlink($archivePath);
            }
            // Clean up any intermediate manifests/dumps.
            foreach ((array) glob($archivePath . '.*') as $intermediate) {
                @unlink($intermediate);
            }
            return new WP_REST_Response(['aborted' => true, 'job_id' => $jobId], 200);
        } catch (JobNotFoundException $e) {
            return new WP_Error('wpms_job_not_found', $e->getMessage(), ['status' => 404]);
        }
    }

    private function jobStore(): JobStore
    {
        return new JobStore(Paths::jobsDir());
    }

    private function generateFilename(): string
    {
        $siteName = sanitize_title(get_bloginfo('name')) ?: 'site';
        $date = gmdate('Y-m-d-His');
        return sprintf('%s-%s.wpress', $siteName, $date);
    }
}
