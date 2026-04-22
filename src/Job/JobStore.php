<?php
declare(strict_types=1);

namespace WpMigrateSafe\Job;

use WpMigrateSafe\Job\Exception\JobNotFoundException;

/**
 * Filesystem persistence for Job objects. Each job is stored as a JSON file
 * named `{job_id}.json` under jobsDir.
 */
final class JobStore
{
    private string $dir;

    public function __construct(string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create jobs directory: ' . $dir);
        }
        $this->dir = rtrim($dir, '/\\');
    }

    public function save(Job $job): void
    {
        $path = $this->path($job->id());
        $tmp = $path . '.tmp';
        $json = json_encode($job->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Could not serialize job.');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write job file: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not rename job file into place.');
        }
    }

    public function load(string $jobId): Job
    {
        $path = $this->path($jobId);
        if (!is_file($path)) {
            throw new JobNotFoundException('Job not found: ' . $jobId);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new JobNotFoundException('Job file corrupt: ' . $jobId);
        }
        return Job::fromArray($data);
    }

    public function delete(string $jobId): void
    {
        @unlink($this->path($jobId));
    }

    /**
     * @return Job[]
     */
    public function findActive(): array
    {
        $result = [];
        foreach ((array) glob($this->dir . '/*.json') as $path) {
            if (!is_file($path)) continue;
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !isset($data['status'])) continue;
            if (!JobStatus::isTerminal((string) $data['status'])) {
                $result[] = Job::fromArray($data);
            }
        }
        return $result;
    }

    /**
     * @return Job[] All jobs sorted newest first.
     */
    public function findAll(): array
    {
        $result = [];
        foreach ((array) glob($this->dir . '/*.json') as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $result[] = Job::fromArray($data);
            }
        }
        usort($result, fn(Job $a, Job $b) => $b->createdAt() <=> $a->createdAt());
        return $result;
    }

    public function purgeOld(int $maxAgeSeconds): int
    {
        $cutoff = time() - $maxAgeSeconds;
        $removed = 0;
        foreach ((array) glob($this->dir . '/*.json') as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) continue;
            if (!JobStatus::isTerminal((string) ($data['status'] ?? ''))) continue;
            // Use file mtime so the test can touch() the file to simulate age.
            if (((int) filemtime($path)) < $cutoff) {
                @unlink($path);
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $jobId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new \InvalidArgumentException('Invalid job id.');
        }
        return $this->dir . '/' . $jobId . '.json';
    }
}
