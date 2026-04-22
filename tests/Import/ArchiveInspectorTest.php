<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\Import;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\Archive\Writer;
use WpMigrateSafe\Import\ArchiveInspector;

final class ArchiveInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpms_inspector_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testExtractsSiteUrlAndHomeFromDump(): void
    {
        $dump = $this->buildDumpWith([
            ['siteurl', 'https://kindrd.me'],
            ['home', 'https://kindrd.me'],
            ['blogname', 'Kindrd'],
        ]);
        $archive = $this->buildArchive($dump);

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('https://kindrd.me', $result['source_url']);
        $this->assertSame('https://kindrd.me', $result['home_url']);
    }

    public function testHandlesSiteurlAndHomeWithDifferentValues(): void
    {
        $dump = $this->buildDumpWith([
            ['siteurl', 'https://old.example.com/wp'],
            ['home',    'https://old.example.com'],
        ]);
        $archive = $this->buildArchive($dump);

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('https://old.example.com/wp', $result['source_url']);
        $this->assertSame('https://old.example.com', $result['home_url']);
    }

    public function testReturnsEmptyStringsWhenArchiveHasNoDatabaseDump(): void
    {
        $archive = $this->buildArchive(null); // no database/database.sql

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('', $result['source_url']);
        $this->assertSame('', $result['home_url']);
    }

    public function testHandlesBackslashInUrl(): void
    {
        // Real-world URLs won't contain quotes, but may have backslashes after _real_escape.
        // Input value "https://example.com\\path" — raw backslash in value.
        // In SQL dump, _real_escape produces "\\\\" (two backslashes for one literal).
        $dump = "INSERT INTO `wp_options` VALUES ('1', 'siteurl', 'https://example.com\\\\path', 'yes');\n";
        $archive = $this->buildArchive($dump);

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('https://example.com\\path', $result['source_url']);
    }

    public function testIgnoresSiteurlAppearingInsideOtherOptionValues(): void
    {
        // An option value that happens to mention 'siteurl' should NOT be parsed as siteurl.
        $dump = "INSERT INTO `wp_options` VALUES ('1', 'blogdescription', 'our siteurl is here', 'yes');\n"
              . "INSERT INTO `wp_options` VALUES ('2', 'siteurl', 'https://real.example.com', 'yes');\n";
        $archive = $this->buildArchive($dump);

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('https://real.example.com', $result['source_url']);
    }

    public function testReturnsEmptyWhenArchiveIsValidButEmptyDump(): void
    {
        $archive = $this->buildArchive('');

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('', $result['source_url']);
        $this->assertSame('', $result['home_url']);
    }

    public function testExtractsSourcePrefixFromCreateTable(): void
    {
        $dump = "-- header\nDROP TABLE IF EXISTS `wpsp_options`;\n"
              . "CREATE TABLE `wpsp_options` (option_id BIGINT, option_name VARCHAR(191), option_value LONGTEXT, autoload VARCHAR(20));\n"
              . "INSERT INTO `wpsp_options` VALUES ('1', 'siteurl', 'https://example.com', 'yes');\n";
        $archive = $this->buildArchive($dump);

        $result = (new ArchiveInspector())->inspect($archive);

        $this->assertSame('wpsp_', $result['source_prefix']);
    }

    public function testExtractsSourcePrefixFromMetadataJson(): void
    {
        // Archive with metadata.json AND database.sql; metadata should win.
        $archive = $this->dir . '/meta_' . uniqid() . '.wpress';
        $writer = new \WpMigrateSafe\Archive\Writer($archive);

        $metaPath = $this->dir . '/metadata.json';
        file_put_contents($metaPath, json_encode([
            'source_prefix' => 'custom_',
            'siteurl' => 'https://example.com',
            'home' => 'https://example.com',
        ]));
        $writer->appendFile($metaPath, 'metadata.json', '');

        $sqlPath = $this->dir . '/database.sql';
        file_put_contents($sqlPath, "CREATE TABLE `wpsp_options` ();\n");
        $writer->appendFile($sqlPath, 'database.sql', 'database');
        $writer->close();

        $result = (new ArchiveInspector())->inspect($archive);

        // metadata.json takes precedence.
        $this->assertSame('custom_', $result['source_prefix']);
    }

    private function buildArchive(?string $dumpContent): string
    {
        $archive = $this->dir . '/fixture_' . uniqid() . '.wpress';
        $writer = new Writer($archive);
        if ($dumpContent !== null) {
            $sqlPath = $this->dir . '/database.sql';
            file_put_contents($sqlPath, $dumpContent);
            $writer->appendFile($sqlPath, 'database.sql', 'database');
        } else {
            // Still add one non-DB file so the archive is non-empty.
            $f = $this->dir . '/file.txt';
            file_put_contents($f, 'x');
            $writer->appendFile($f, 'file.txt', 'wp-content');
        }
        $writer->close();
        return $archive;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $options
     */
    private function buildDumpWith(array $options): string
    {
        $lines = [];
        $lines[] = '-- header comment';
        $lines[] = 'DROP TABLE IF EXISTS `wp_options`;';
        $lines[] = 'CREATE TABLE `wp_options` (option_id BIGINT, option_name VARCHAR(191), option_value LONGTEXT, autoload VARCHAR(20));';
        $id = 1;
        foreach ($options as [$name, $value]) {
            $escaped = str_replace("'", "\\'", $value);
            $lines[] = "INSERT INTO `wp_options` VALUES ('$id', '$name', '$escaped', 'yes');";
            $id++;
        }
        return implode("\n", $lines) . "\n";
    }
}
