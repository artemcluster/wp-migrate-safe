=== WP Migrate Safe ===
Contributors: artempronin
Tags: migration, backup, restore, wpress, rollback
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Reliable WordPress backup and restore. No file size limits on uploads. Automatic rollback on import failure. Clear error messages — no silent hangs.

== Description ==

WP Migrate Safe creates and restores .wpress backup files (the same format used by All-in-One WP Migration), but with three key differences:

1. **No file size limits.** Chunked upload via the REST API bypasses upload_max_filesize.
2. **Automatic rollback.** If anything fails during import, the site is restored to its exact pre-import state.
3. **Structured error reporting.** Every failure has a code, category, hint, and documentation link. Never just "it hung".

= Features =

* Export WordPress site to .wpress archive (database + plugins + themes + uploads)
* Chunked upload of large backups (5 MB chunks, SHA-256 verified)
* Dry-run checks before import (disk space, MySQL version, archive validity)
* Stepped processing — works on shared hosting with PHP `max_execution_time=30`
* Heartbeat detection — when a PHP worker dies, the UI notices within 60 seconds
* Search-replace URLs safely inside serialized PHP data (WooCommerce, ACF compatible)
* WP-CLI: `wp migrate-safe export|import|status|rollback|backups`
* Global lock prevents concurrent imports destroying the site
* Cron-based cleanup of stale tmp files and old snapshots

= What this plugin does NOT do =

* No cloud storage (S3, GDrive, Dropbox) — local filesystem only
* No scheduled/cron-based automated backups (planned for v1.2)
* No Multisite support (planned for v1.1)

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload.
2. Activate via the Plugins menu.
3. Go to Tools → Migrate Safe.

For WP-CLI:

    wp plugin install wp-migrate-safe --activate
    wp migrate-safe export

== Frequently Asked Questions ==

= Is this compatible with All-in-One WP Migration? =

Yes. We read and write the same .wpress format. You can export with ai1wm and restore with us, or vice versa.

= What happens if my site is very large (50+ GB)? =

Use the WP-CLI command — it streams data without PHP HTTP request time limits.

= Can I undo a restore? =

Yes. Every restore creates a snapshot first. Use `wp migrate-safe rollback <snapshot_id>` or the Tools menu. Snapshots are kept for 7 days.

== Changelog ==

= 0.1.0 =
* Initial release: export, chunked upload, dry-run, import with automatic rollback, WP-CLI, heartbeat detection, error catalog, cleanup cron.

== Upgrade Notice ==

= 0.1.0 =
First release.
