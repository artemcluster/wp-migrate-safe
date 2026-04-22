<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Snapshot;

/**
 * Immutable value object describing one rollback snapshot.
 *
 * A snapshot records what was backed up before an import, enabling
 * RollbackExecutor to restore the site to its pre-import state.
 */
final class Snapshot
{
    private string $id;
    private int $createdAt;
    /** @var string[] Absolute paths of content directories that were copied. */
    private array $contentPaths;
    /** @var string Absolute path to the SQL dump file. */
    private string $sqlDumpPath;
    private string $dbPrefix;

    /**
     * @param string[] $contentPaths
     */
    public function __construct(
        string $id,
        int $createdAt,
        array $contentPaths,
        string $sqlDumpPath,
        string $dbPrefix
    ) {
        $this->id           = $id;
        $this->createdAt    = $createdAt;
        $this->contentPaths = $contentPaths;
        $this->sqlDumpPath  = $sqlDumpPath;
        $this->dbPrefix     = $dbPrefix;
    }

    public function id(): string { return $this->id; }
    public function createdAt(): int { return $this->createdAt; }
    /** @return string[] */
    public function contentPaths(): array { return $this->contentPaths; }
    public function sqlDumpPath(): string { return $this->sqlDumpPath; }
    public function dbPrefix(): string { return $this->dbPrefix; }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'created_at'    => $this->createdAt,
            'content_paths' => $this->contentPaths,
            'sql_dump_path' => $this->sqlDumpPath,
            'db_prefix'     => $this->dbPrefix,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (int)    $data['created_at'],
            (array)  ($data['content_paths'] ?? []),
            (string) $data['sql_dump_path'],
            (string) ($data['db_prefix'] ?? 'wp_')
        );
    }
}
