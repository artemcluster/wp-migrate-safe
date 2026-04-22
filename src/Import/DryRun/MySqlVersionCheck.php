<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

/**
 * Check the current MySQL/MariaDB version against a minimum requirement.
 *
 * The "expected" version is stored in archive meta if Plan 4's exporter recorded it;
 * for MVP we simply check that the current server is at least MySQL 5.7 / MariaDB 10.3,
 * which is required by WordPress 6.x anyway.
 */
final class MySqlVersionCheck
{
    private const MIN_MYSQL  = '5.7.0';
    private const MIN_MARIADB = '10.3.0';

    public function run(string $serverVersion): DryRunReport
    {
        $isMariaDb = stripos($serverVersion, 'mariadb') !== false;
        $numeric = $this->extractNumericVersion($serverVersion);
        $min = $isMariaDb ? self::MIN_MARIADB : self::MIN_MYSQL;

        if (version_compare($numeric, $min, '<')) {
            return new DryRunReport(
                [],
                [[
                    'code' => 'MYSQL_VERSION_OLD',
                    'message' => sprintf('Server version %s is older than recommended %s.', $numeric, $min),
                    'hint' => 'Consider upgrading MySQL/MariaDB before import; some CREATE TABLE statements may fail.',
                ]]
            );
        }

        return DryRunReport::ok();
    }

    private function extractNumericVersion(string $version): string
    {
        if (preg_match('/(\d+\.\d+\.\d+)/', $version, $m)) {
            return $m[1];
        }
        return '0.0.0';
    }
}
