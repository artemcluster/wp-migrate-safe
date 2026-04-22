# Manual Verification — Plan 3 (Plugin Skeleton + Chunked Upload)

Run these before marking Plan 3 complete.

## Setup

1. Start a local WP site (wp-env, Local, Docker…).
2. Ensure PHP `upload_max_filesize` and `post_max_size` are set small (e.g. 8 MB) to prove chunked upload bypass works.
3. Activate the plugin.

## Automated gate

- [ ] `./vendor/bin/phpunit` passes (all unit + library tests)
- [ ] If WP test suite available: `./vendor/bin/phpunit -c phpunit-wp.xml.dist` passes

## UI

- [ ] Tools → Migrate Safe menu appears
- [ ] Page loads without PHP errors (check `wp-content/debug.log`)
- [ ] Tabs "Upload" and "Backups" switch correctly
- [ ] `/wp-content/backups/wp-migrate-safe/` exists with `.htaccess` denying direct access
- [ ] Direct GET to `/wp-content/backups/wp-migrate-safe/any-file.wpress` returns 403 (Apache) or goes via the plugin only

## Upload Flow

- [ ] Drag-and-drop a small `.wpress` file (~1 MB) → upload succeeds → file appears in `wp-content/backups/wp-migrate-safe/`
- [ ] SHA-256 of uploaded file matches SHA-256 of source file
- [ ] Click-to-select via `<input type=file>` also works
- [ ] Uploading a non-`.wpress` file → error "Only .wpress files are allowed"
- [ ] Upload a 100 MB `.wpress` with `upload_max_filesize=8M` → succeeds (proof chunked bypass works)
- [ ] Cancel button mid-upload → session aborted, no file appears in final dir, tmp dir cleaned up
- [ ] Refresh page mid-upload → tmp session still in `_uploads/` (cleanup cron will remove after 24h)

## Backups Tab

- [ ] Uploaded files appear in backups list
- [ ] Size and modified time display correctly
- [ ] Delete button removes the file after confirmation
- [ ] `is_valid` column shows ✅ for properly-closed `.wpress` files (Archive Reader validation)

## Permissions & Security

- [ ] Non-admin user gets 401 / 403 on REST endpoints
- [ ] Invalid upload_id on `/chunk` returns 404-like error
- [ ] Wrong SHA-256 at `/complete` rejects (422) and aborts session
- [ ] Uploading a file with `../` in the filename sanitizes the name (WP's `sanitize_file_name`)

## Edge Cases

- [ ] Low disk space: temporarily fill disk to <1 GB free → `/upload/init` returns 507 `wpms_disk_full`
- [ ] Network blip: use DevTools to throttle network → chunk retry 3× works; if all 3 fail, UI shows error
- [ ] Closing browser mid-upload → server keeps session; reopening tab starts fresh (resume UX deferred to Plan 6)

## Logs

- [ ] No PHP notices/warnings in `wp-content/debug.log` during normal flow
- [ ] Errors are JSON-serializable (no HTML in REST error responses)

## Plan 4 — Export (manual)

- [ ] Export tab → Start export → progress bar advances smoothly to 100%
- [ ] Completed .wpress appears in Backups tab
- [ ] Export produces a valid archive (Backups tab shows ✅ valid)
- [ ] Export cancel mid-way → partial file cleaned up
- [ ] WP-CLI: `wp migrate-safe export` completes and writes to backups dir

## Plan 5 — Import (manual, on a test site!)

⚠ **Only test on disposable WP installs.** These procedures replace site content.

- [ ] Restore tab → backup selected → Run checks → all green
- [ ] Start restore → progress advances through all 6 steps
- [ ] After completion, homepage loads with imported content
- [ ] URLs in DB are updated if old-url/new-url provided
- [ ] Deliberately break an archive (hex-edit last 1000 bytes) → import fails with `WPRESS_TRUNCATED` or rollback message
- [ ] Verified: after a forced failure, site still works (rollback succeeded)
- [ ] WP-CLI: `wp migrate-safe import backup.wpress --old-url=http://x --new-url=http://y` completes

## Plan 6 — Polish (manual)

- [ ] Try starting a second import while one is running → `GLOBAL_LOCK_HELD` error
- [ ] Kill PHP-FPM mid-import (on dev setup) → within 65 sec UI shows "⚠ No heartbeat for >60s"
- [ ] `wp cron event list --hook=wpms_cleanup_tick` shows the hourly event after activation
- [ ] `wp migrate-safe status` shows active jobs
- [ ] `wp migrate-safe backups` lists files
- [ ] `wp migrate-safe rollback` lists snapshots; supplying an id restores
- [ ] Every error in the UI shows: code, message, hint, doc link
- [ ] Doc links in UI resolve (at least to the anchor in ERROR-CODES.md)
- [ ] Deactivate plugin → `wpms_cleanup_tick` cron is removed (`wp cron event list` confirms)
