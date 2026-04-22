<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportJob;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;
use WpMigrateSafe\Plugin\Paths;

/**
 * WP-Integration E2E test for the full import pipeline.
 *
 * Requires WP_TESTS_DIR and a live MySQL connection.
 * DO NOT add to phpunit.xml.dist unit suite.
 *
 * @group wp-integration
 */
final class ImportJobTest extends \WP_UnitTestCase
{
    private string $tmpDir;
    private string $archivePath;

    public function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/wpms_import_job_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    /**
     * Test that a complete import job runs through all 6 steps and reaches COMPLETED status.
     *
     * A minimal .wpress archive is created in setUp, containing only a `database/dump.sql`.
     */
    public function testFullImportPipelineCompletesSuccessfully(): void
    {
        // Build a minimal archive with just a DB dump.
        $this->archivePath = $this->buildMinimalArchive();

        $extractDir  = $this->tmpDir . '/extract';
        $rollbackDir = $this->tmpDir . '/rollback';
        mkdir($rollbackDir, 0755, true);

        $context = new ImportContext(
            $this->archivePath,
            ABSPATH,
            WP_CONTENT_DIR,
            $rollbackDir,
            $extractDir,
            'wp_',
            'wp_',
            get_site_url(),
            get_site_url()
        );

        $job = Job::newImport([
            'filename'      => 'test.wpress',
            'archive_path'  => $this->archivePath,
            'extract_dir'   => $extractDir,
            'source_prefix' => 'wp_',
            'target_prefix' => 'wp_',
            'source_url'    => get_site_url(),
            'target_url'    => get_site_url(),
        ]);

        // Run all 6 steps.
        for ($i = 0; $i < 20; $i++) {
            if (JobStatus::isTerminal($job->status())) {
                break;
            }
            $job = ImportJob::runSlice($job, $context, 30);
        }

        $this->assertSame(JobStatus::COMPLETED, $job->status());
        $this->assertSame(100, $job->progress());
    }

    public function testImportJobTriggersRollbackOnDatabaseFailure(): void
    {
        // Archive with an invalid SQL dump that will cause ImportDatabase to fail.
        $this->archivePath = $this->buildArchiveWithBadSql();

        $extractDir  = $this->tmpDir . '/extract2';
        $rollbackDir = $this->tmpDir . '/rollback2';
        mkdir($rollbackDir, 0755, true);

        $context = new ImportContext(
            $this->archivePath,
            ABSPATH,
            WP_CONTENT_DIR,
            $rollbackDir,
            $extractDir,
            'wp_',
            'wp_',
            get_site_url(),
            get_site_url()
        );

        $job = Job::newImport([
            'filename'      => 'bad.wpress',
            'archive_path'  => $this->archivePath,
            'extract_dir'   => $extractDir,
            'source_prefix' => 'wp_',
            'target_prefix' => 'wp_',
            'source_url'    => get_site_url(),
            'target_url'    => get_site_url(),
        ]);

        // Run until terminal.
        for ($i = 0; $i < 20; $i++) {
            if (JobStatus::isTerminal($job->status())) {
                break;
            }
            $job = ImportJob::runSlice($job, $context, 30);
        }

        // The job should have failed.
        $this->assertSame(JobStatus::FAILED, $job->status());
        $this->assertNotNull($job->error());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildMinimalArchive(): string
    {
        global $wpdb;
        $archivePath = $this->tmpDir . '/test.wpress';

        // Create a minimal SQL dump.
        $dumpPath = $this->tmpDir . '/dump.sql';
        file_put_contents($dumpPath, "-- minimal dump\nSELECT 1;\n");

        // Write archive.
        $writer = new \WpMigrateSafe\Archive\Writer($archivePath);
        $writer->appendFile($dumpPath, 'dump.sql', 'database');
        $writer->close();

        return $archivePath;
    }

    private function buildArchiveWithBadSql(): string
    {
        $archivePath = $this->tmpDir . '/bad.wpress';
        $dumpPath    = $this->tmpDir . '/bad_dump.sql';

        // SQL that will fail.
        file_put_contents($dumpPath, "INVALID SQL STATEMENT THAT WILL FAIL;\n");

        $writer = new \WpMigrateSafe\Archive\Writer($archivePath);
        $writer->appendFile($dumpPath, 'dump.sql', 'database');
        $writer->close();

        return $archivePath;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
