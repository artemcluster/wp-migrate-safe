<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

final class FinalizeImport implements ImportStep
{
    public function name(): string { return 'finalize'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        // Flush WP caches + rewrite rules. The site is now live on the imported data.
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        if (function_exists('flush_rewrite_rules')) flush_rewrite_rules(false);

        // Clean up extraction directory.
        $this->rmTree($context->extractDir());

        $snapshotId = (string) ($cursor['snapshot_id'] ?? '');
        if ($snapshotId !== '') {
            $store = $context->snapshotStore();
            $snapshot = $store->load($snapshotId);
            $store->save($snapshot->commit());
        }

        return StepResult::complete(100, 'Import complete. Site is live on new data.');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
