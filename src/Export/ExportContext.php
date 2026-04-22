<?php
declare(strict_types=1);

namespace WpMigrateSafe\Export;

use WpMigrateSafe\Archive\Writer;

/**
 * Context shared across export steps within a single HTTP request.
 *
 * A new ExportContext is constructed per HTTP request — Writer is opened
 * fresh from the partial .wpress file on disk so each step can append more.
 */
final class ExportContext
{
    private string $archivePath;
    private string $wpRoot;
    private string $wpContentDir;
    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $archivePath, string $wpRoot, string $wpContentDir, array $options = [])
    {
        $this->archivePath = $archivePath;
        $this->wpRoot = rtrim($wpRoot, '/\\');
        $this->wpContentDir = rtrim($wpContentDir, '/\\');
        $this->options = $options;
    }

    public function archivePath(): string { return $this->archivePath; }
    public function wpRoot(): string { return $this->wpRoot; }
    public function wpContentDir(): string { return $this->wpContentDir; }
    public function options(): array { return $this->options; }

    /**
     * Open a new Writer positioned at the end of the existing .wpress file,
     * so the next step appends to it. The file must NOT yet have an EOF block —
     * we only write the EOF during the FinalizeArchive step.
     *
     * To support appending across HTTP requests, we open in 'c+b' mode and seek
     * to end; Writer itself uses 'wb' (truncating), so we use a specialized
     * append helper on the PlainWriter subclass defined in Task 4.
     */
    public function archiveWriter(): AppendingWriter
    {
        return new AppendingWriter($this->archivePath);
    }
}
