=== AI1WM Remote Backup ===
Contributors: rsd-systems
Tags: backup, google drive, onedrive, all-in-one wp migration, ai1wm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.7.13
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically uploads All-in-One WP Migration backups to Google Drive or Microsoft OneDrive.

== Description ==

Detects .wpress files produced by the All-in-One WP Migration plugin and uploads them to
Google Drive or OneDrive using chunked, resumable transfers. Supports automatic retention
rotation and optional local file cleanup after upload.

Key features:
* Bring-Your-Own-App OAuth — no broker service required.
* Chunked, resumable uploads — survives PHP timeouts on large files.
* Configurable retention — keep the last N remote backups.
* Action Scheduler for reliable background processing.
* Rolling admin log (never logs tokens or secrets).

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin in WP Admin → Plugins.
3. Go to Settings → Remote Backup and follow the provider setup instructions.

= Action Scheduler (recommended) =

For reliable background uploads on shared hosting, install Action Scheduler:

  cd wp-content/plugins/rsd-remote-backup
  mkdir vendor && cd vendor
  git clone https://github.com/woocommerce/action-scheduler.git action-scheduler

Or install WooCommerce (which bundles Action Scheduler). Without it the plugin falls
back to wp_schedule_single_event, which only fires on traffic.

= Security (optional but recommended) =

Add to wp-config.php to use a dedicated encryption key for stored OAuth tokens:

  define( 'RSD_RB_ENCRYPTION_KEY', 'your-long-random-secret-here' );

== Frequently Asked Questions ==

= Google stops working after a week =

Your OAuth consent screen is in "Testing" mode. Google expires refresh tokens after 7 days
in that state. Publish your consent screen to "In production" in the Google Cloud Console.

== Changelog ==

= 0.7.13 =
* New: Status tab's "Background Processing" panel now also reports whether `DISABLE_WP_CRON` is set, whether the scheduled scan is overdue, and Action Scheduler queue health for this plugin's jobs.
* New: much more detailed logging around upload dispatch — every concurrency-cap check now logs the actual slot count vs the configured cap, every scan tick logs a one-line queue-health snapshot (counts by status), and the Action Scheduler "already scheduled" dedup check now logs the specific action id/status it matched (so a stale/stuck action blocking re-dispatch is directly visible in the log instead of just silently no-op'ing forever).
* No functional/behavior changes — this release is diagnostic logging only, aimed at pinpointing a live-site report of uploads not starting despite WP-Cron and Action Scheduler both appearing healthy.

= 0.7.12 =
* Fix: a backup whose local file has been deleted (and was never confirmed uploaded) no longer shows as permanently "Detected" on the Upload Queue with no way to tell it's actually gone. Both the Upload Queue table and the `backups[]` REST field now flag this ("not found on disk" / `missing_locally: true`).
* Fix: detecting a new backup no longer fails to record it at all if the SHA-256 checksum couldn't be computed (e.g. a very large file hitting a transient I/O or timeout issue) — it's now recorded without a baseline checksum instead, which gets backfilled automatically once verification succeeds later (same as backups discovered via Resync already worked).

= 0.7.11 =
* Changed: "Scan Backup Files" (Status tab, Manual Actions) now also runs the real backup scanner — detecting new/changed files and recording them (manifest + pending job rows) — in addition to its existing raw directory listing. It still deliberately never starts an upload; use "Upload Existing Backups Now" for that. Previously this button was a read-only diagnostic only and had no effect on the Upload Queue.
* Changed: the notice shown after clicking "Scan Backup Files" now also reports how many new backups were detected and queued, separately from the raw file count found on disk.

= 0.7.10 =
* New: `/status` and `/resync` REST endpoints now also return a `backups[]` array alongside the existing `jobs[]` (unchanged) — one entry per detected backup file, present from the moment it's found on disk regardless of upload progress, with the fuller detect/compress/upload lifecycle status and upload mechanics nested underneath. For CRM integrations that want to track backup presence first and upload status second.
* Changed: the admin "Upload Queue" table (Status tab) now lists backups by their fuller detect/compress/upload pipeline status (e.g. "Compressing", "Compression failed — uploading raw") instead of just the narrower upload-job status. Note: a backup uploaded before this plugin's manifest tracking existed (pre-v0.3.10) that never finished uploading will not appear in this list — it's still tracked in the database, just not surfaced here.

= 0.7.9 =
* server-stats' All-in-One WP Migration Unlimited Extension collector now also reports the raw schedule config (exact day-of-month/weekday and time behind the "Per month"/"Per week" summary) and each schedule's real run history (up to 30 timestamped records: when it ran and whether it succeeded or failed) — the previous version's `last_run` field only ever gave the single most-recent status with no timestamp, which couldn't show whether a schedule actually fired on time. Shown as a "Scheduled For" column and an inline "Recent Runs" list on the Status tab's Server Stats panel.

= 0.7.8 =
* New: server-stats now also reports an All-in-One WP Migration Unlimited Extension collector when that paid extension is active — lists every configured backup schedule (title, type, enabled/disabled, period, time, storage destination, last-run outcome, retention), shown as a table on the Status tab's Server Stats panel. The free/core AI1WM plugin has no scheduling feature of its own; this only appears when the Unlimited Extension is installed.

= 0.7.7 =
* Fix: Environment Diagnostics' "Raw object cache round-trip test" no longer reports FAIL on sites with no external object cache active (the common case — WordPress core's default in-memory cache is only ever meant to last one request, so failing to survive a redirect there is normal, not a problem). Now shows "N/A" with an explanation in that case; PASS/FAIL is only shown when an external object cache is actually active.

= 0.7.6 =
* Fix: OAuth CSRF state (Google Drive/OneDrive connect) and the one-time API key reveal now use a plain WordPress option with a manually-checked expiry instead of a transient. On sites with a misconfigured or unreachable persistent object cache, transients can silently vanish before a request completes its round trip (confirmed on a client site where wp_cache_get()/wp_cache_set() themselves didn't round-trip) — causing "OAuth state mismatch" on every connect attempt and the revealed API key never displaying. Plain options always write through to the DB regardless of object cache health, so both features now work correctly regardless of the site's cache setup.

= 0.7.5 =
* Diagnostics: Environment Diagnostics panel now identifies the active object-cache.php drop-in (name/version from its own header, plus the loaded PHP cache class) and adds a raw wp_cache_set()/wp_cache_get() round-trip test alongside the existing transient round-trip test — separates "the cache backend itself doesn't persist" from "something about how Transients uses it doesn't persist."
* Diagnostics: "Force Check Now" (update checker) now captures and displays any API error hit while contacting GitHub (e.g. a blocked/failing outbound request to api.github.com), persisted so it survives the redirect.

= 0.7.4 =
* New: "Environment Diagnostics" panel on the Status tab — reports whether an external object cache is active, whether an object-cache.php drop-in is present, a cross-request transient round-trip test, and a live page-render timestamp. For diagnosing sites where OAuth connect or the API key reveal silently fail due to transients not persisting between requests.
* Diagnostic logging added to OAuth state validation (logs whether the stored state was missing/expired vs. present-but-mismatched) to help pin down the same class of issue.

= 0.7.2 =
* New: server-stats now also reports a Wordfence collector when Wordfence is active — reimplements Wordfence's own "Firewall Summary" widget (attacks blocked, grouped into Complex/Brute Force/Blocklist over 24h/7d/30d), shown as a table on the Status tab's Server Stats panel.

= 0.7.1 =
* New: "Keep Local Backups" retention setting (replaces the old "Delete Local Backup After Upload" checkbox) — keeps the N most recent confirmed-uploaded backups on this server, deleting older ones once a newer backup is confirmed uploaded. Set to 0 for the old immediate-delete behavior.
* Runs automatically after every successful upload, alongside the existing remote retention pruning.

= 0.7.0 =
* New: GET /wp-json/rsd-rb/v1/server-stats endpoint — core WP/server health (PHP/WP/MySQL version, disk space, memory limit, active theme/plugin count) plus an extensible plugin-stats collector, starting with WP Rocket's Rocket Insights score when WP Rocket is active.
* New: "Server Stats" panel on the Status tab showing the same data, with the Insights score rendered as a color-coded badge.
* License-gated, like /trigger and /resync.

= 0.1.0 =
* Phase 0: scaffold — plugin header, activator, settings, logger, admin UI skeleton.
