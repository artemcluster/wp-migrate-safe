<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Export;

use WP_UnitTestCase;
use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Export\ExportContext;
use WpMigrateSafe\Export\ExportJob;
use WpMigrateSafe\Job\Job;
use WpMigrateSafe\Job\JobStatus;

/**
 * End-to-end export integration test.
 * Run with: ./vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
final class ExportJobTest extends WP_UnitTestCase
{
    public function testFullExportProducesValidWpressFile(): void
    {
        $tmp = sys_get_temp_dir() . '/wpms_exp_' . uniqid();
        mkdir($tmp);
        $archive = $tmp . '/out.wpress';

        $context = new ExportContext($archive, ABSPATH, WP_CONTENT_DIR);
        $job = Job::newExport(['archive_path' => $archive]);

        $maxIterations = 200;
        $iteration = 0;
        while (!JobStatus::isTerminal($job->status())) {
            $job = ExportJob::runSlice($job, $context, 2);
            if (++$iteration >= $maxIterations) {
                $this->fail(sprintf(
                    'Export did not complete within %d iterations. Current status: %s, step: %d',
                    $maxIterations,
                    $job->status(),
                    $job->stepIndex()
                ));
            }
        }

        $this->assertSame(JobStatus::COMPLETED, $job->status());
        $this->assertFileExists($archive);

        $reader = new Reader($archive);
        $this->assertTrue($reader->isValid());

        $entries = iterator_to_array($reader->listFiles());
        $paths = array_map(fn($e) => $e[0]->path(), $entries);

        $this->assertContains('database/database.sql', $paths);

        // Cleanup
        @unlink($archive);
        foreach ((array) glob($tmp . '/*') as $f) @unlink($f);
        @rmdir($tmp);
    }
}
