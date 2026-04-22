<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import;

/**
 * Context shared across import steps within a single HTTP request.
 *
 * Constructed once per HTTP request from persisted Job meta.
 */
final class ImportContext
{
    private string $archivePath;
    private string $wpRoot;
    private string $wpContentDir;
    private string $rollbackDir;
    private string $extractDir;
    private string $sourcePrefix;
    private string $targetPrefix;
    private string $sourceUrl;
    private string $targetUrl;
    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $archivePath,
        string $wpRoot,
        string $wpContentDir,
        string $rollbackDir,
        string $extractDir,
        string $sourcePrefix,
        string $targetPrefix,
        string $sourceUrl,
        string $targetUrl,
        array $options = []
    ) {
        $this->archivePath   = $archivePath;
        $this->wpRoot        = rtrim($wpRoot, '/\\');
        $this->wpContentDir  = rtrim($wpContentDir, '/\\');
        $this->rollbackDir   = rtrim($rollbackDir, '/\\');
        $this->extractDir    = rtrim($extractDir, '/\\');
        $this->sourcePrefix  = $sourcePrefix;
        $this->targetPrefix  = $targetPrefix;
        $this->sourceUrl     = rtrim($sourceUrl, '/');
        $this->targetUrl     = rtrim($targetUrl, '/');
        $this->options       = $options;
    }

    public function archivePath(): string { return $this->archivePath; }
    public function wpRoot(): string { return $this->wpRoot; }
    public function wpContentDir(): string { return $this->wpContentDir; }
    public function rollbackDir(): string { return $this->rollbackDir; }
    public function extractDir(): string { return $this->extractDir; }
    public function sourcePrefix(): string { return $this->sourcePrefix; }
    public function targetPrefix(): string { return $this->targetPrefix; }
    public function sourceUrl(): string { return $this->sourceUrl; }
    public function targetUrl(): string { return $this->targetUrl; }
    /** @return array<string, mixed> */
    public function options(): array { return $this->options; }
}
