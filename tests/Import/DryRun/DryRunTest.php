<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import\DryRun;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Writer;
use WpMigrateSafe\Import\DryRun\ArchiveValidityCheck;
use WpMigrateSafe\Import\DryRun\DiskSpaceCheck;
use WpMigrateSafe\Import\DryRun\DryRunReport;
use WpMigrateSafe\Import\DryRun\MySqlVersionCheck;

final class DryRunTest extends TestCase
{
    // -------------------------------------------------------------------------
    // DryRunReport
    // -------------------------------------------------------------------------

    public function testReportOkHasNoErrorsOrWarnings(): void
    {
        $report = DryRunReport::ok();
        $this->assertFalse($report->hasErrors());
        $this->assertFalse($report->hasWarnings());
        $this->assertTrue($report->canProceed());
    }

    public function testReportWithErrorsCannotProceed(): void
    {
        $report = new DryRunReport(
            [['code' => 'DISK_FULL', 'message' => 'No space.', 'hint' => '']],
            []
        );
        $this->assertTrue($report->hasErrors());
        $this->assertFalse($report->canProceed());
    }

    public function testReportWithWarningsCanProceed(): void
    {
        $report = new DryRunReport(
            [],
            [['code' => 'DISK_SPACE_TIGHT', 'message' => 'Tight.', 'hint' => '']]
        );
        $this->assertFalse($report->hasErrors());
        $this->assertTrue($report->hasWarnings());
        $this->assertTrue($report->canProceed());
    }

    public function testMerge(): void
    {
        $a = new DryRunReport(
            [['code' => 'ERR1', 'message' => 'e1', 'hint' => '']],
            [['code' => 'WARN1', 'message' => 'w1', 'hint' => '']]
        );
        $b = new DryRunReport(
            [['code' => 'ERR2', 'message' => 'e2', 'hint' => '']],
            []
        );
        $merged = $a->merge($b);
        $this->assertCount(2, $merged->errors());
        $this->assertCount(1, $merged->warnings());
    }

    public function testToArrayShape(): void
    {
        $report = DryRunReport::ok();
        $arr = $report->toArray();
        $this->assertArrayHasKey('errors', $arr);
        $this->assertArrayHasKey('warnings', $arr);
        $this->assertArrayHasKey('can_proceed', $arr);
        $this->assertTrue($arr['can_proceed']);
    }

    // -------------------------------------------------------------------------
    // DiskSpaceCheck
    // -------------------------------------------------------------------------

    public function testDiskSpaceOkWithAbundantSpace(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wpms_');
        file_put_contents($tmp, str_repeat('x', 1000));
        $report = (new DiskSpaceCheck())->run($tmp, 10000);
        $this->assertFalse($report->hasErrors());
        $this->assertFalse($report->hasWarnings());
        @unlink($tmp);
    }

    public function testDiskSpaceErrorWhenInsufficientSpace(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wpms_');
        file_put_contents($tmp, str_repeat('x', 1000)); // 1000 bytes
        $report = (new DiskSpaceCheck())->run($tmp, 500); // 500 < 2000 required
        $this->assertTrue($report->hasErrors());
        $this->assertSame('DISK_FULL', $report->errors()[0]['code']);
        @unlink($tmp);
    }

    public function testDiskSpaceWarningWhenTight(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wpms_');
        file_put_contents($tmp, str_repeat('x', 1000));
        $report = (new DiskSpaceCheck())->run($tmp, 2500); // > 2x but < 3x
        $this->assertFalse($report->hasErrors());
        $this->assertTrue($report->hasWarnings());
        $this->assertSame('DISK_SPACE_TIGHT', $report->warnings()[0]['code']);
        @unlink($tmp);
    }

    public function testDiskSpaceMissingArchiveIsError(): void
    {
        $report = (new DiskSpaceCheck())->run('/nonexistent/file', 10000000);
        $this->assertTrue($report->hasErrors());
        $this->assertSame('ARCHIVE_MISSING', $report->errors()[0]['code']);
    }

    // -------------------------------------------------------------------------
    // MySqlVersionCheck
    // -------------------------------------------------------------------------

    public function testMysqlVersionOkFor57(): void
    {
        $report = (new MySqlVersionCheck())->run('5.7.33-log');
        $this->assertFalse($report->hasErrors());
        $this->assertFalse($report->hasWarnings());
    }

    public function testMysqlVersionOkFor80(): void
    {
        $report = (new MySqlVersionCheck())->run('8.0.26');
        $this->assertFalse($report->hasErrors());
        $this->assertFalse($report->hasWarnings());
    }

    public function testMysqlVersionWarningForOld(): void
    {
        $report = (new MySqlVersionCheck())->run('5.6.0');
        $this->assertFalse($report->hasErrors());
        $this->assertTrue($report->hasWarnings());
        $this->assertSame('MYSQL_VERSION_OLD', $report->warnings()[0]['code']);
    }

    // -------------------------------------------------------------------------
    // ArchiveValidityCheck
    // -------------------------------------------------------------------------

    public function testArchiveValidityPassesForValidArchiveWithDatabase(): void
    {
        $dir = sys_get_temp_dir() . '/wpms_avc_' . uniqid();
        mkdir($dir, 0755, true);
        $archive = $dir . '/good.wpress';
        $src = $dir . '/database.sql';
        file_put_contents($src, 'CREATE TABLE foo;');
        $w = new Writer($archive);
        $w->appendFile($src, 'database.sql', 'database');
        $w->close();

        $report = (new ArchiveValidityCheck())->run($archive);
        $this->assertFalse($report->hasErrors());
        $this->assertFalse($report->hasWarnings());

        @unlink($src);
        @unlink($archive);
        @rmdir($dir);
    }

    public function testArchiveValidityWarningWhenNoDatabaseFile(): void
    {
        $dir = sys_get_temp_dir() . '/wpms_avc_' . uniqid();
        mkdir($dir, 0755, true);
        $archive = $dir . '/nodb.wpress';
        $src = $dir . '/file.txt';
        file_put_contents($src, 'content');
        $w = new Writer($archive);
        $w->appendFile($src, 'file.txt', 'wp-content/uploads');
        $w->close();

        $report = (new ArchiveValidityCheck())->run($archive);
        $this->assertFalse($report->hasErrors());
        $this->assertTrue($report->hasWarnings());
        $this->assertSame('ARCHIVE_NO_DATABASE', $report->warnings()[0]['code']);

        @unlink($src);
        @unlink($archive);
        @rmdir($dir);
    }
}
