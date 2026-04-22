<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\DryRun;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Import\DryRun\ArchiveValidityCheck;
use WpMigrateSafe\Import\DryRun\DiskSpaceCheck;
use WpMigrateSafe\Import\DryRun\DryRunReport;
use WpMigrateSafe\Import\DryRun\MySqlVersionCheck;
use WpMigrateSafe\Archive\Writer;

final class DryRunTest extends TestCase
{
    // -------------------------------------------------------------------------
    // DryRunReport
    // -------------------------------------------------------------------------

    public function testReportIsOkWhenEmpty(): void
    {
        $report = new DryRunReport();
        $this->assertTrue($report->ok());
        $this->assertCount(0, $report->checks());
    }

    public function testReportFailsWhenAnyCheckFails(): void
    {
        $report = (new DryRunReport())
            ->withCheck('a', true)
            ->withCheck('b', false, 'bad thing');

        $this->assertFalse($report->ok());
        $this->assertCount(2, $report->checks());
    }

    public function testReportToArrayShape(): void
    {
        $report = (new DryRunReport())->withCheck('x', true, 'fine');
        $arr = $report->toArray();
        $this->assertArrayHasKey('ok', $arr);
        $this->assertArrayHasKey('checks', $arr);
        $this->assertTrue($arr['ok']);
    }

    // -------------------------------------------------------------------------
    // DiskSpaceCheck
    // -------------------------------------------------------------------------

    public function testDiskSpacePassesWhenEnoughSpace(): void
    {
        $archiveBytes = 100 * 1024 * 1024; // 100 MB
        $freeBytes    = 400 * 1024 * 1024; // 400 MB (>=3x)

        $check  = new DiskSpaceCheck($archiveBytes, $freeBytes);
        $report = $check->run(new DryRunReport());

        $this->assertTrue($report->ok());
    }

    public function testDiskSpaceFailsWhenTooLittle(): void
    {
        $archiveBytes = 100 * 1024 * 1024; // 100 MB
        $freeBytes    = 200 * 1024 * 1024; // 200 MB (< 300 MB required)

        $check  = new DiskSpaceCheck($archiveBytes, $freeBytes);
        $report = $check->run(new DryRunReport());

        $this->assertFalse($report->ok());
    }

    public function testDiskSpaceSkippedWhenUnknown(): void
    {
        $check  = new DiskSpaceCheck(1024, null);
        $report = $check->run(new DryRunReport());

        // Unknown free space → skip (pass) with informational message.
        $this->assertTrue($report->ok());
        $this->assertStringContainsString('skipping', $report->checks()[0]['message']);
    }

    // -------------------------------------------------------------------------
    // MySqlVersionCheck
    // -------------------------------------------------------------------------

    public function testMysqlVersionPassesFor57(): void
    {
        $check  = new MySqlVersionCheck('5.7.33-log');
        $report = $check->run(new DryRunReport());
        $this->assertTrue($report->ok());
    }

    public function testMysqlVersionPassesFor80(): void
    {
        $check  = new MySqlVersionCheck('8.0.26');
        $report = $check->run(new DryRunReport());
        $this->assertTrue($report->ok());
    }

    public function testMysqlVersionFailsForNull(): void
    {
        $check  = new MySqlVersionCheck(null);
        $report = $check->run(new DryRunReport());
        $this->assertFalse($report->ok());
    }

    // -------------------------------------------------------------------------
    // ArchiveValidityCheck
    // -------------------------------------------------------------------------

    public function testArchiveValidityFailsForMissingFile(): void
    {
        $check  = new ArchiveValidityCheck('/nonexistent/path/archive.wpress');
        $report = $check->run(new DryRunReport());
        $this->assertFalse($report->ok());
    }

    public function testArchiveValidityPassesForValidArchive(): void
    {
        $dir     = sys_get_temp_dir() . '/wpms_dryrun_' . uniqid();
        mkdir($dir, 0755, true);
        $archive = $dir . '/test.wpress';

        // Build a minimal valid archive.
        $src = $dir . '/hello.txt';
        file_put_contents($src, 'hello');
        $writer = new Writer($archive);
        $writer->appendFile($src, 'hello.txt', '');
        $writer->close();

        $check  = new ArchiveValidityCheck($archive);
        $report = $check->run(new DryRunReport());

        @unlink($src);
        @unlink($archive);
        @rmdir($dir);

        $this->assertTrue($report->ok());
    }
}
