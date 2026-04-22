# WP Migrate Safe

[![CI](https://github.com/artemcluster/wp-migrate-safe/actions/workflows/ci.yml/badge.svg)](https://github.com/artemcluster/wp-migrate-safe/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/artemcluster/wp-migrate-safe?color=blue)](https://github.com/artemcluster/wp-migrate-safe/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-brightgreen)](./LICENSE)
[![PHP](https://img.shields.io/badge/php-7.4%20%7C%208.0%20%7C%208.1%20%7C%208.2%20%7C%208.3-blue)](./composer.json)

**Reliable WordPress backup & restore — no file-size limits, automatic database-prefix rewriting, honest error messages.**

WP Migrate Safe exports and restores complete WordPress sites using the `.wpress` archive format. It's an independent open-source tool aimed at solving the pain points that plague other migration plugins: silent hangs, upload size limits, opaque failures, and broken imports across different database prefixes.

- **Chunked upload** — 5 MB chunks via REST API, bypasses PHP `upload_max_filesize` completely
- **Automatic DB prefix rewriting** — archive with `wpsp_` prefix imports cleanly into a site with `wp_` (or any other)
- **Serialize-aware search-replace** — safely rewrites URLs inside PHP-serialized `wp_options`/ACF/WooCommerce data
- **Stepped processing** — works on shared hosting with `max_execution_time=30`
- **Heartbeat detection** — when a PHP worker dies mid-import, the UI knows within 60 seconds
- **Clear error codes** — every failure has a code (e.g. `DISK_FULL`, `DB_IMPORT_SYNTAX`), category, hint, and doc link
- **WP-CLI commands** — `wp migrate-safe export|import|status|backups`
- **Global lock** — prevents two concurrent imports from destroying the site
- **Metadata embedded** — new exports include `metadata.json` with source URL, prefix, WP version for future imports

**Author:** Artem Pronin
**License:** [GPL-3.0-or-later](./LICENSE)
**Version:** 0.1.0 — first iteration. Export, import, chunked upload, DB prefix rewriting, serialize-aware search-replace, WP-CLI — all covered by 169 passing tests. Multisite, scheduled backups, and cloud storage land in later iterations (see roadmap).

---

## Why this plugin

All-in-One WP Migration is the most popular migration tool for WordPress, but users run into recurring issues:

1. **File-size limits** — the free version caps uploads; the paid "unlimited" extension often breaks silently
2. **Silent failures** — a broken import hangs without telling you what went wrong
3. **No rollback information** — once restore starts, there's no clarity on site state
4. **Prefix mismatches** — importing a `wpsp_` archive into a `wp_` site produces half-working mess

WP Migrate Safe addresses each of these:

| Problem | Our approach |
|---|---|
| Upload size cap | Chunked upload over REST (5 MB chunks, SHA-256 verified, resumable) |
| Silent hang | Heartbeat polling — UI shows "⚠ No heartbeat for >60s" within one minute |
| Vague errors | Typed error codes (`DISK_FULL`, `WPRESS_CORRUPTED`, `DB_IMPORT_SYNTAX`…) with hints and doc links |
| Prefix mismatch | `PrefixRewriter` transparently rewrites backtick-quoted identifiers during SQL import |
| No pre-flight checks | Dry-run validates disk space, MySQL version, archive integrity before touching anything |

---

## About the `.wpress` format

This plugin reads and writes archives in the `.wpress` format originally developed by **ServMask Inc.** for their plugin **All-in-One WP Migration** (GPL v3). We include no code from ServMask — all format handling is an independent, clean-room implementation written from the published specification in their open-source code (see `lib/vendor/servmask/archiver/class-ai1wm-archiver.php` in ai1wm releases).

**Why we chose this format:**

- It's already the de facto standard in the WordPress migration ecosystem
- Users with existing `.wpress` backups (from any source) can restore them with this plugin
- Exports from this plugin can be restored by All-in-One WP Migration, giving users choice
- The format is simple, streaming-friendly, and well-suited for multi-GB archives

**Legal position:**

- File formats are **not subject to copyright** (see *Google LLC v. Oracle America, Inc.* — 2021, U.S. Supreme Court, and *Lotus Development Corp. v. Borland International, Inc.*)
- Our implementation is located entirely under `src/Archive/` (namespace `WpMigrateSafe\Archive\*`) and shares no code with ServMask's implementation
- Both projects are licensed under GPL v3, so even if interpreted restrictively, derivative-work permissions align
- The `.wpress` extension is **not a registered trademark** (verified against USPTO and EUIPO as of April 2026)

**This plugin is not affiliated with, endorsed by, or connected to ServMask Inc. or All-in-One WP Migration.** We gratefully acknowledge their work in establishing the `.wpress` format — without it, users would be locked into the tool that created each backup.

---

## Installation

### Manual
1. Download the latest release zip
2. WP Admin → Plugins → Add New → Upload → select the zip
3. Activate
4. Go to **Tools → Migrate Safe**

### Composer (for developers embedding in a site)
```bash
cd wp-content/plugins
git clone https://github.com/artemcluster/wp-migrate-safe.git
cd wp-migrate-safe
composer install --no-dev --optimize-autoloader
```

### WP-CLI
```bash
wp plugin activate wp-migrate-safe
```

**Requirements:** PHP 7.4+, WordPress 6.0+, MySQL 5.7+ / MariaDB 10.3+.

---

## Usage

### Export the current site
- UI: Tools → Migrate Safe → **Export** tab → Start export
- CLI: `wp migrate-safe export [--output=/path/to/backup.wpress]`

### Upload a `.wpress` from somewhere else
- UI: Tools → Migrate Safe → **Upload** tab → drag & drop the file

### Restore a backup
- UI: Tools → Migrate Safe → **Import** tab → select backup → Source URL is auto-detected → Start import
- CLI: `wp migrate-safe import backup.wpress [--old-url=http://old.com] [--new-url=https://new.com]`

### List backups
- UI: Tools → Migrate Safe → **Backups** tab
- CLI: `wp migrate-safe backups`

### Job status
- CLI: `wp migrate-safe status [<job_id>]`

---

## Architecture

```
src/
├── Archive/              .wpress reader/writer (no WordPress dependency)
├── SearchReplace/        serialize-aware URL replacement
├── Plugin/               WordPress bootstrap, admin menu, paths
├── Rest/                 REST API controllers (upload, export, import, backups)
├── Upload/               chunked upload session management
├── Job/                  state machine for long-running operations
├── Export/               export pipeline + step implementations
├── Import/               import pipeline + step implementations
├── Errors/               error code catalog + response builder
├── Concurrency/          global lock (mutex via WP transients)
├── Cron/                 hourly cleanup of stale tmp files
├── Progress/             heartbeat stale detection
└── Cli/                  WP-CLI command registration

tests/                   169 PHPUnit tests (unit + WP integration)
assets/js/               chunked upload, export/import UI clients
views/                   admin page templates
docs/ERROR-CODES.md      user-facing error reference
```

Each layer has clear boundaries: `Archive/` is pure PHP with no WP dependency, `SearchReplace/` is independent, the WP-specific pieces (Plugin, Rest, Import, Export) sit on top.

---

## Error codes

Every user-facing error has a stable code so you can search for solutions. See [`docs/ERROR-CODES.md`](./docs/ERROR-CODES.md) for the full catalog.

Examples: `DISK_FULL`, `PHP_MEMORY_LOW`, `WPRESS_CORRUPTED`, `DB_IMPORT_SYNTAX`, `JOB_HEARTBEAT_LOST`, `GLOBAL_LOCK_HELD`.

---

## Development

### Run tests
```bash
composer install
./vendor/bin/phpunit                         # unit tests (fast)
./vendor/bin/phpunit -c phpunit-wp.xml.dist  # WP integration tests (requires WP_TESTS_DIR)
```

### Structure principles
- **Bounded contexts** — Archive layer knows nothing about WordPress; Plugin layer knows nothing about chunk byte offsets
- **Stepped processing** — every long operation (export, import, chunked upload) splits into small slices that finish in < 20 seconds
- **Test-driven** — unit tests for pure logic (Archive reader/writer, SearchReplace, PrefixRewriter); WP-integration tests only where we need a live `$wpdb`

---

## Roadmap

- [ ] Multisite support (v1.1)
- [ ] Scheduled backups via cron (v1.2)
- [ ] Incremental exports (only changed files since last backup) (v1.2)
- [ ] Optional cloud storage: S3, SFTP, B2 (v2.0)
- [ ] Full i18n (only scaffold currently)
- [ ] Job-token auth for `/import/step` so users don't have to re-login after import

---

## Contributing

Pull requests welcome. Before submitting:

1. `./vendor/bin/phpunit` must pass
2. For new functionality, add unit tests (or WP-integration if it requires a live database)
3. Follow the existing coding style (`declare(strict_types=1)`, typed properties, `final` classes where practical)
4. Document user-visible changes in `readme.txt` changelog

Bug reports: include your PHP version, MySQL version, WordPress version, and if possible the `code` field from the error you saw.

---

## License

GPL-3.0-or-later. See [LICENSE](./LICENSE) for the full text.

This means you are free to:
- Use this plugin, commercially or privately, on any site
- Modify the source code
- Distribute original or modified versions

Under the condition that:
- Derivative works remain under GPL-3.0-or-later
- The full source of derivatives is made available
- The license notice and copyright are preserved

---

## Acknowledgments

- **ServMask Inc.** for originating the `.wpress` format and the All-in-One WP Migration plugin. The interoperability this plugin provides is only possible because their code is open-source.
- **WordPress contributors** for the REST API infrastructure that makes chunked upload and stepped processing possible on shared hosting.
- **wp-cli/wp-cli** for the command framework underlying the `wp migrate-safe` commands.
