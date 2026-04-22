<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

use WpMigrateSafe\Archive\Reader;

/**
 * Extract site metadata (siteurl, home) from a .wpress archive by streaming
 * the first few MB of its embedded SQL dump and grepping for wp_options values.
 *
 * Used to pre-fill the "Source site URL" input when the user selects a backup
 * to restore — saves them from typing the old URL manually.
 *
 * Never loads the whole archive or dump into memory: reads at most MAX_SCAN_BYTES
 * from the database.sql entry (default 5 MB; siteurl/home are nearly always in
 * the first INSERT statements for wp_options, which is dumped alphabetically
 * after wp_commentmeta/wp_comments/wp_links).
 */
final class ArchiveInspector
{
    private const MAX_SCAN_BYTES = 20 * 1024 * 1024; // 20 MB — ai1wm dumps wp_options late (alphabetical after actionscheduler/commentmeta/comments/links)
    private const READ_CHUNK = 64 * 1024;

    /**
     * Candidate paths where the SQL dump might live, in priority order.
     * - our exporter: database/database.sql
     * - ai1wm: ./database.sql (leading dot is preserved by their archiver)
     * - plain: database.sql
     */
    private const DB_DUMP_PATHS = ['database/database.sql', './database.sql', 'database.sql'];
    private const METADATA_PATH = 'metadata.json';

    /**
     * @return array{source_url: string, home_url: string}
     */
    public function inspect(string $archivePath): array
    {
        $result = ['source_url' => '', 'home_url' => ''];

        $reader = new Reader($archivePath);

        // Fast path: archives produced by our exporter include metadata.json at the root.
        $metadataEntry = null;
        $dumpEntry = null;
        foreach ($reader->listFiles() as [$header, $contentOffset]) {
            $path = $header->path();
            if ($path === self::METADATA_PATH) {
                $metadataEntry = [$header, $contentOffset];
            } elseif ($dumpEntry === null && in_array($path, self::DB_DUMP_PATHS, true)) {
                $dumpEntry = [$header, $contentOffset];
            }
            if ($metadataEntry !== null && $dumpEntry !== null) {
                break;
            }
        }

        if ($metadataEntry !== null) {
            [$header, $offset] = $metadataEntry;
            $this->readMetadata($archivePath, $offset, $header->size(), $result);
            if ($result['source_url'] !== '' && $result['home_url'] !== '') {
                return $result;
            }
        }

        // Fallback: parse the SQL dump (works for ai1wm archives and older exports).
        if ($dumpEntry !== null) {
            [$header, $offset] = $dumpEntry;
            $this->scanDumpForOptions($archivePath, $offset, $header->size(), $result);
        }

        return $result;
    }

    /**
     * @param array{source_url: string, home_url: string} $result
     */
    private function readMetadata(string $archivePath, int $offset, int $size, array &$result): void
    {
        if ($size <= 0 || $size > 64 * 1024) {
            return; // metadata.json is small; ignore unreasonable sizes
        }
        $handle = @fopen($archivePath, 'rb');
        if ($handle === false) return;
        try {
            if (fseek($handle, $offset) === -1) return;
            $json = fread($handle, $size);
            if ($json === false) return;
            $data = json_decode($json, true);
            if (!is_array($data)) return;
            if ($result['source_url'] === '' && isset($data['siteurl']) && is_string($data['siteurl'])) {
                $result['source_url'] = $data['siteurl'];
            }
            if ($result['home_url'] === '' && isset($data['home']) && is_string($data['home'])) {
                $result['home_url'] = $data['home'];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{source_url: string, home_url: string} $result
     */
    private function scanDumpForOptions(string $archivePath, int $offset, int $size, array &$result): void
    {
        $handle = @fopen($archivePath, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if (fseek($handle, $offset) === -1) {
                return;
            }

            $budget = min(self::MAX_SCAN_BYTES, $size);
            $buffer = '';
            $read = 0;

            while ($read < $budget && ($result['source_url'] === '' || $result['home_url'] === '')) {
                $want = (int) min(self::READ_CHUNK, $budget - $read);
                $chunk = fread($handle, $want);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $read += strlen($chunk);
                $buffer .= $chunk;

                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    $this->matchLine($line, $result);
                    if ($result['source_url'] !== '' && $result['home_url'] !== '') {
                        return;
                    }
                }
            }

            if ($buffer !== '') {
                $this->matchLine($buffer, $result);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{source_url: string, home_url: string} $result
     */
    private function matchLine(string $line, array &$result): void
    {
        if ($result['source_url'] === '' && strpos($line, "'siteurl'") !== false) {
            $value = $this->extractOptionValue($line, 'siteurl');
            if ($value !== '') {
                $result['source_url'] = $value;
            }
        }
        if ($result['home_url'] === '' && strpos($line, "'home'") !== false) {
            $value = $this->extractOptionValue($line, 'home');
            if ($value !== '') {
                $result['home_url'] = $value;
            }
        }
    }

    private function extractOptionValue(string $line, string $optionName): string
    {
        $escaped = preg_quote($optionName, '/');
        $pattern = "/'{$escaped}'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/";
        if (preg_match($pattern, $line, $m) === 1) {
            return stripcslashes($m[1]);
        }
        return '';
    }
}
