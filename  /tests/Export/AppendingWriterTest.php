<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Export;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Reader;
use WpMigrateSafe\Export\AppendingWriter;

final class AppendingWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_aw_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testMultiStepAppendsProduceValidArchive(): void
    {
        $archive = $this->dir . '/out.wpress';

        // Simulate two HTTP requests each opening a new writer and appending one file.
        $src1 = $this->dir . '/a.txt';
        file_put_contents($src1, 'AAAAA');
        (new AppendingWriter($archive))->appendFile($src1, 'a.txt', '');

        $src2 = $this->dir . '/b.txt';
        file_put_contents($src2, 'BBBBBBBBBB');
        (new AppendingWriter($archive))->appendFile($src2, 'b.txt', 'sub');

        (new AppendingWriter($archive))->finalize();

        // Reader should see both files with correct content.
        $reader = new Reader($archive);
        $this->assertTrue($reader->isValid());

        $dest = $this->dir . '/extracted';
        mkdir($dest);
        $count = $reader->extractAll($dest);
        $this->assertSame(2, $count);
        $this->assertSame('AAAAA', file_get_contents($dest . '/a.txt'));
        $this->assertSame('BBBBBBBBBB', file_get_contents($dest . '/sub/b.txt'));
    }

    public function testAppendBytesStoresInlineContent(): void
    {
        $archive = $this->dir . '/bytes.wpress';
        (new AppendingWriter($archive))->appendBytes('dump.sql', 'database', 'CREATE TABLE foo;');
        (new AppendingWriter($archive))->finalize();

        $reader = new Reader($archive);
        $entries = iterator_to_array($reader->listFiles());
        $this->assertCount(1, $entries);
        $this->assertSame('database/dump.sql', $entries[0][0]->path());
    }
}
