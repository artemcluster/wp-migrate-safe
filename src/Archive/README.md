# Archive Layer

Streaming reader and writer for the `.wpress` archive format, byte-compatible with All-in-One WP Migration (ai1wm).

## Public API

### `WpMigrateSafe\Archive\Header`

Immutable value object for a file's metadata inside an archive.

```php
$h = new Header('hello.txt', 13, 1700000000, 'wp-content');
$h->name();   // 'hello.txt'
$h->size();   // 13
$h->mtime();  // 1700000000
$h->prefix(); // 'wp-content'
$h->path();   // 'wp-content/hello.txt'
$h->pack();   // 4377-byte binary header block
Header::unpack($block);     // Header | throws CorruptedArchiveException
Header::isEofBlock($block); // bool (true for v1 or v2 EOF)
```

### `WpMigrateSafe\Archive\Writer`

Streaming archive writer.

```php
$w = new Writer('/path/to/archive.wpress');
$w->appendFile('/src/hello.txt', 'hello.txt', 'greetings');
$w->appendFile('/src/wp-config.php', 'wp-config.php', '');
$w->close(); // writes v2 EOF block with CRC32
```

Throws `NotWritableException` on I/O errors.

### `WpMigrateSafe\Archive\Reader`

Streaming archive reader.

```php
$r = new Reader('/path/to/archive.wpress');

// Iterate without extracting:
foreach ($r->listFiles() as [$header, $contentOffset]) {
    echo $header->path() . ' (' . $header->size() . " bytes)\n";
}

// Extract all:
$count = $r->extractAll('/destination/dir');

// Extract one by known header + offset:
$r->extractFile($header, $offset, '/destination/dir');

// Validation:
$r->isValid();    // EOF block present at end
$r->verifyCrc();  // v2 CRC matches content (or v1 archive with no CRC)
```

Throws: `NotReadableException`, `CorruptedArchiveException`, `TruncatedArchiveException`.

## Safety Guarantees

- **Streaming:** No operation loads the full archive into memory. Safe for archives far larger than `memory_limit`.
- **Path traversal refused:** Entries containing `..` or absolute paths in `prefix`/`name` throw `CorruptedArchiveException` during extraction.
- **Truncation detected:** `listFiles()` verifies declared content size does not exceed file size.

## Format Reference

Each file entry is a 4377-byte header followed by exactly `size` bytes of content. The archive ends with an EOF block (v1 = all-NUL, v2 = contains archive size and CRC32 hex).

Header layout:

| Field  | Length | Content |
|--------|-------:|---------|
| name   | 255    | Filename only (no path), NUL-padded |
| size   | 14     | ASCII decimal size |
| mtime  | 12     | ASCII decimal Unix timestamp |
| prefix | 4088   | Directory path, forward slashes |
| crc32  | 8      | Empty for file headers; hex for v2 EOF |
