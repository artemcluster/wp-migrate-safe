<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\DryRun;

/**
 * Verifies that the target MySQL server is version 5.6+ (minimum for utf8mb4).
 */
final class MySqlVersionCheck
{
    private const MIN_VERSION = '5.6.0';

    private ?string $serverVersion;

    public function __construct(?string $serverVersion)
    {
        $this->serverVersion = $serverVersion;
    }

    public function run(DryRunReport $report): DryRunReport
    {
        $name = 'mysql_version';

        if ($this->serverVersion === null) {
            return $report->withCheck($name, false, 'Could not determine MySQL server version.');
        }

        // Extract numeric version prefix (e.g. "5.7.33-log" → "5.7.33").
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $this->serverVersion, $m)) {
            return $report->withCheck($name, false, 'Unrecognised MySQL version string: ' . $this->serverVersion);
        }

        $version = $m[1];
        $passed  = version_compare($version, self::MIN_VERSION, '>=');
        $message = $passed
            ? sprintf('MySQL %s meets minimum requirement (%s).', $version, self::MIN_VERSION)
            : sprintf('MySQL %s is below minimum requirement (%s).', $version, self::MIN_VERSION);

        return $report->withCheck($name, $passed, $message);
    }
}
