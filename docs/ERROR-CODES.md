# Error Codes Reference

When something goes wrong, the plugin reports a specific error code. Use this page to look up what each code means and how to fix it.

---

## Behind Cloudflare or other reverse proxy: large download fails

**Symptoms:** Downloading a backup from the Backups tab fails with "Failed - Unknown server error" in the browser, especially for files over ~50 MB.

**Cause:** Cloudflare's free plan terminates origin connections after 100 seconds (HTTP 524). PHP-streamed downloads of large files often exceed that on slow uplinks.

**Fix, in order of preference:**

1. **Install `mod_xsendfile`** (Apache hosts).
   With it enabled, the plugin asks Apache to stream the file directly, bypassing PHP entirely. No code change needed — the plugin auto-detects the module.

2. **Configure nginx X-Accel-Redirect** (nginx hosts that have shell access).
   Add an internal location to your nginx config:
   ```nginx
   location ^~ /wpms-protected/ {
       internal;
       alias /var/www/your-site/wp-content/backups/wp-migrate-safe/;
   }
   ```
   Then in `wp-config.php`:
   ```php
   define('WPMS_NGINX_INTERNAL_LOCATION', '/wpms-protected');
   ```

3. **Bypass Cloudflare for the download URL** (no server changes).
   In Cloudflare → Rules → Page Rules, add:
   - URL: `*example.com/wp-json/wp-migrate-safe/v1/backups/download*`
   - Setting: **Cache Level → Bypass**
   This keeps Cloudflare's edge from buffering the response, removing the 100-second cap.

4. **Use SSH/SFTP/cPanel** to copy the file out of `wp-content/backups/wp-migrate-safe/` directly. The file's already there — the download endpoint is just a convenience.

5. **WP-CLI export to a custom path:** `wp migrate-safe export --output=/tmp/site.wpress`, then SCP it out.

---

## DISK_FULL

**What happened:** The server does not have enough free disk space to perform the requested operation.

**Fix:** Delete old backups from the Backups tab, then retry. Imports require roughly 2–3× the archive size in free space (snapshot + extraction + new data).

---

## PHP_MEMORY_LOW

**What happened:** PHP's `memory_limit` is too low.

**Fix:** Increase memory in one of:
- `wp-config.php`: `define('WP_MEMORY_LIMIT', '256M');`
- `php.ini`: `memory_limit = 256M`

---

## MYSQL_VERSION_OLD

**What happened:** Your MySQL/MariaDB version is older than what the archive was created on. Modern SQL features (e.g. JSON columns, window functions) may be missing.

**Fix:** Proceed at your own risk via dry-run confirmation. If import fails on a specific statement, upgrade your database server.

---

## WPRESS_CORRUPTED / WPRESS_TRUNCATED

**What happened:** The archive file is damaged or incomplete.

**Fix:** Re-download or re-upload the archive. Verify the SHA-256 matches the source.

---

## DB_IMPORT_SYNTAX

**What happened:** A SQL statement in the archive's database dump failed to execute.

**Fix:** Check the error context for the failing statement. Common causes:
- MySQL version mismatch (see `MYSQL_VERSION_OLD`)
- Invalid character encoding (try archive with `--default-character-set=utf8mb4`)

The site was rolled back automatically.

---

## DB_CONNECTION_LOST

**What happened:** MySQL dropped the connection mid-operation.

**Fix:**
- Check server's `wait_timeout` (increase to 300+)
- Check `max_allowed_packet` (increase to 64M+)
- Retry the operation — stepped processing will resume from the last checkpoint.

---

## DB_ROW_TOO_LARGE

**What happened:** A single database row is too large to fit in PHP memory during import.

**Fix:** Use the WP-CLI command — it streams rows without PHP's memory_limit:
```
wp migrate-safe import your-backup.wpress --old-url=http://old --new-url=http://new
```

---

## FS_PERMISSION

**What happened:** PHP user cannot write to `wp-content/`.

**Fix:** Ensure the directory is writable:
```
chmod 755 wp-content/
chown -R www-data:www-data wp-content/
```
Exact user/group depends on your hosting.

---

## UPLOAD_CHUNK_HASH

**What happened:** A chunk of the uploaded file was corrupted in transit.

**Fix:** Automatic retry (up to 3 attempts). If all retries fail, re-upload the entire file.

---

## STEP_TIMEOUT

**What happened:** A processing step is taking longer than expected (possibly hitting `max_execution_time`).

**Fix:** Use WP-CLI for large sites:
```
wp migrate-safe export --output=/path/to/backup.wpress
wp migrate-safe import backup.wpress
```

---

## JOB_HEARTBEAT_LOST

**What happened:** The server stopped sending progress updates. The PHP worker may have been killed (OOM, timeout).

**Fix:**
- Check server's `error.log` for PHP fatal errors
- Increase `memory_limit` and retry
- Use WP-CLI if the web worker keeps dying

The job can be resumed via the "Continue" button, or aborted + restarted.

---

## ROLLBACK_FAILED ⚠ CRITICAL

**What happened:** Import failed AND the automatic rollback itself failed. Your site is in an indeterminate state.

**Fix:** Follow the **manual recovery steps** shown in the error details. They include the exact paths to your DB dump and the rollback directories.

Typical manual recovery:
```
# 1. Restore database
mysql -u YOUR_USER -p YOUR_DB < wp-content/backups/wp-migrate-safe/_rollback/{id}/database.sql

# 2. Restore content dirs
cd wp-content/
rm -rf plugins themes uploads
mv plugins.rollback.{id} plugins
mv themes.rollback.{id} themes
mv uploads.rollback.{id} uploads
```

Contact support with your snapshot ID.

---

## IMPORT_FAILED_ROLLED_BACK

**What happened:** Import failed, automatic rollback succeeded. Site is restored to its pre-import state.

**Fix:** Read the original error's `hint` and `context` fields, fix the underlying cause (MySQL version, disk space, etc.), and retry.

---

## IMPORT_FAILED

**What happened:** Import failed before the snapshot was taken (e.g. during initial validation). Site has not been modified.

**Fix:** Address the reported issue. No rollback needed.

---

## GLOBAL_LOCK_HELD

**What happened:** Another import or export is already running. Only one operation at a time is allowed site-wide.

**Fix:** Wait for the other operation to finish, or abort it from the Tools → Migrate Safe menu. If the lock is stale (prior worker crashed), force-release via:
```
wp migrate-safe force-unlock
```
(Or delete the `wpms_global_lock` transient manually.)
