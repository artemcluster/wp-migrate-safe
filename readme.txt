=== WP Migrate Safe ===
Contributors: artempronin
Tags: migration, backup, restore, export, import, wpress
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Reliable WordPress backup and restore with no file-size limits, automatic database-prefix rewriting, and honest error messages instead of silent hangs.

== Description ==

WP Migrate Safe exports and restores complete WordPress sites using the `.wpress` archive format. It's an independent open-source alternative focused on reliability on shared hosting and clear user feedback.

= Key features =

* **Chunked upload** — 5 MB chunks via REST API, bypasses `upload_max_filesize` entirely. SHA-256 verified.
* **Automatic DB prefix rewriting** — import a `wpsp_` archive into a `wp_` site (or any combination) without corruption.
* **Serialize-aware URL replacement** — safely rewrites URLs inside PHP-serialized options, usermeta, postmeta. WooCommerce and ACF tested.
* **Stepped processing** — long operations split into 20-second slices to survive `max_execution_time=30` on shared hosts.
* **Heartbeat detection** — when a PHP worker dies mid-import, the UI knows within 60 seconds instead of spinning forever.
* **Typed error codes** — every failure has a code (e.g. `DISK_FULL`, `DB_IMPORT_SYNTAX`), a hint, a docs link. No "it hung".
* **Dry-run checks** — validates disk space, MySQL version, archive integrity before touching anything.
* **Global lock** — prevents two concurrent imports from destroying the site.
* **Metadata in exports** — new backups include `metadata.json` with source URL, prefix, WP version for faster future imports.
* **WP-CLI** — `wp migrate-safe export|import|status|backups` with no browser in the loop for huge sites.

= Why another migration plugin =

If you've used All-in-One WP Migration, you know the pain points:

1. File-size limits with a paywall
2. Silent failures mid-import
3. Prefix mismatches that leave the site broken without clear messaging

WP Migrate Safe addresses each of these without asking you to pay for a bigger upload quota.

= .wpress format — compatibility =

WP Migrate Safe reads and writes the `.wpress` format originally developed by ServMask for their All-in-One WP Migration plugin (GPL v3). Our implementation is an independent, clean-room reimplementation — no code from ServMask is bundled. This means:

* Your existing `.wpress` backups from All-in-One WP Migration can be restored by this plugin
* Exports made with WP Migrate Safe can be restored by All-in-One WP Migration
* Users are not locked into a single tool

This plugin is **not affiliated with, endorsed by, or connected to ServMask Inc. or All-in-One WP Migration** in any way. See the README on the source repository for full legal context.

= What this plugin does NOT do (yet) =

* No built-in cloud storage (S3, GDrive, Dropbox) — local filesystem only in v0.1.x
* No scheduled backups (planned for v1.2)
* No WordPress Multisite support (planned for v1.1)
* No automatic rollback after failed import — see FAQ

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload, or extract to `wp-content/plugins/wp-migrate-safe/`
2. Activate the plugin
3. Go to **Tools → Migrate Safe**

For developers installing from source:

    cd wp-content/plugins
    git clone https://github.com/artemcluster/wp-migrate-safe.git
    cd wp-migrate-safe
    composer install --no-dev --optimize-autoloader

== Usage ==

= Export this site =

Tools → Migrate Safe → Export tab → Start export. The `.wpress` file appears in the Backups tab when complete.

= Restore from a backup =

Tools → Migrate Safe → Upload tab to add a `.wpress` file, then Import tab to restore it. The source URL is detected automatically from the archive metadata.

= WP-CLI =

    wp migrate-safe export --output=/tmp/site-backup.wpress
    wp migrate-safe import /tmp/site-backup.wpress --old-url=http://old.com --new-url=https://new.com
    wp migrate-safe backups
    wp migrate-safe status

== Frequently Asked Questions ==

= Is this compatible with All-in-One WP Migration? =

Yes. Both plugins read/write the same `.wpress` format. You can move archives freely between them.

= What if my site is 50+ GB? =

Use the WP-CLI commands. CLI avoids PHP HTTP request time limits completely.

= What if the import fails halfway? =

You'll see a specific error code (e.g. `DISK_FULL`, `DB_IMPORT_SYNTAX`) with a hint on how to fix it. The site may be in a partial state — in that case restore from another backup or re-run the import after fixing the underlying issue. This plugin does not automatically roll back: we found that snapshot-and-rollback adds more risk than it removes on shared hosting.

= Does it handle different database table prefixes? =

Yes. If the backup has `wpsp_` tables and your target site uses `wp_`, the importer rewrites backtick-quoted table names during SQL import. You don't need to pre-process the dump.

= Will I stay logged in after restore? =

Not yet. Because the import replaces the users/usermeta tables, your browser's session becomes invalid. You'll be prompted to log back in once the restore completes (this is also how other migration tools behave). Automatic re-authentication is on the roadmap.

= Who made this plugin? =

Artem Pronin. Released as open-source under GPL-3.0-or-later. Contributions and bug reports welcome at the GitHub repository.

== Screenshots ==

1. Upload tab — chunked upload of large `.wpress` files
2. Export tab — progress bar through all pipeline steps
3. Import tab — auto-detected source URL and typed error messages
4. Backups tab — list, download, delete

== Changelog ==

= 0.1.0 =
* Initial alpha release
* Chunked upload with SHA-256 verification
* Stepped export pipeline
* Stepped import pipeline with automatic DB prefix rewriting
* Serialize-aware URL search-replace
* Heartbeat-based stale-worker detection
* WP-CLI commands: export, import, status, backups
* Global lock to prevent concurrent operations
* Typed error codes with hints and doc links
* Cron cleanup of stale tmp files, sessions, and jobs
* Archive metadata (source URL, DB prefix, WP version) embedded in every export

== Upgrade Notice ==

= 0.1.0 =
First public release. Alpha status — test thoroughly on staging before using in production.
