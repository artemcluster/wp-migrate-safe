<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

use WpMigrateSafe\Archive\Reader;

final class ArchiveValidityCheck
{
    public function run(string $archivePath): DryRunReport
    {
        try {
            $reader = new Reader($archivePath);
            if (!$reader->isValid()) {
                return new DryRunReport(
                    [[
                        'code' => 'WPRESS_TRUNCATED',
                        'message' => 'Archive has no valid EOF block — file may be truncated.',
                        'hint' => 'Re-upload the backup.',
                    ]],
                    []
                );
            }

            $hasDatabase = false;
            foreach ($reader->listFiles() as [$header, $offset]) {
                if ($header->name() === 'database.sql' && $header->prefix() === 'database') {
                    $hasDatabase = true;
                    break;
                }
            }

            if (!$hasDatabase) {
                return new DryRunReport([], [[
                    'code' => 'ARCHIVE_NO_DATABASE',
                    'message' => 'Archive does not contain a database dump.',
                    'hint' => 'Restore will only recover files, not database.',
                ]]);
            }

            return DryRunReport::ok();
        } catch (\Throwable $e) {
            return new DryRunReport(
                [[
                    'code' => 'WPRESS_CORRUPTED',
                    'message' => 'Archive is corrupted: ' . $e->getMessage(),
                    'hint' => 'Re-upload the backup or use another copy.',
                ]],
                []
            );
        }
    }
}
