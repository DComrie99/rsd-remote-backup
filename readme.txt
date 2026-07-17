=== AI1WM Remote Backup ===
Contributors: rsd-systems
Tags: backup, google drive, onedrive, all-in-one wp migration, ai1wm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.8.7
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

= 0.8.7 =
* New: Disk Usage tab's "(files directly in this folder)" row is now clickable — drills into a per-file listing (name + size, biggest first) of that folder's loose files, capped at the 500 largest on folders with an unusually large number of them. Computed on demand when clicked, not during the main scan — the scan itself still only ever records a per-folder total, so the persisted scan state stays proportional to folder count rather than ballooning to cover every individual file on the site.

= 0.8.6 =
* Fix: Disk Usage tab's live progress no longer works by repeatedly reloading the whole page — found via live testing where the reload-based version kept navigating back to itself every ~1-2 seconds regardless of which tab was actually being looked at, hijacking navigation away from the Comments tab and requiring a hard refresh to escape. Now uses a background AJAX poll (this plugin's first) that updates just the file/folder counters in place; leaving or switching tabs no longer fights the scan for control, and normal navigation away from the page now stops it (pauses, resumable later) the way closing the tab always was meant to.
* Changed: each scan chunk now runs for 5 seconds server-side (up from 3) with no artificial delay between chunks — the previous version added a fixed pause between page reloads for readability, which only slowed the scan down for no benefit once progress updates happen in place instead of via reload.

= 0.8.5 =
* New: "Disk Usage" tab added to Maintenance — walks the WordPress install (from its root down) and reports total size per folder, cPanel-style, for tracking down sudden backup/disk-usage growth without needing the host's own file manager. Deliberately a one-off manual scan, not a scheduled background task: click Start, leave the tab open, and it advances itself a few seconds at a time (via the browser reloading its own progress) until the whole tree is measured, then lets you drill into any folder to see its children's sizes, biggest first. Closing the tab pauses it — reopening the tab resumes exactly where it left off. No WP-Cron/Action Scheduler involved at all, so there's nothing to go stale in the background between runs.

= 0.8.4 =
* New: Maintenance → Comments now shows a full raw breakdown by `comment_type` — every row actually in the comments table, including anything the "Delete All Comments" button leaves alone — with a Yes/No column for whether each type gets deleted. Answers "what's actually in here, and what's this button going to touch" directly on-screen, instead of needing to run the equivalent SQL query by hand against the database.

= 0.8.3 =
* Fix (safety): "Delete All Comments" counted and would have deleted every row in the comments table regardless of what it actually was, not just genuine visitor comments. Found via a live site where the count showed 531 while wp-admin's own Comments screen showed only 1 — 530 of those rows were something else entirely stored in the same table under a different comment_type (most likely WooCommerce order notes, or a similar plugin reusing this table), which wp-admin already knows to hide from its own Comments screen. Now restricted to a whitelist of genuine comment_type values ('', 'comment', 'pingback', 'trackback') for both the displayed count and the actual deletion — anything else (order notes, or any other plugin's own comment-table data, known or not) is left completely untouched. Also added a status breakdown (approved/pending/spam/trash) with an explanatory note so a gap against the Comments screen's own count is never confusing again.

= 0.8.2 =
* Fix: "Maintenance" was showing as its own separate top-level admin menu item instead of a submenu under "RSD Backup" (alongside Settings and Backups), as intended. Now registered as a proper submenu.
* Fix: the Delete All Comments count could look wrong compared to the Comments screen (e.g. showing 1,243 when Comments only listed 1,080) — that's not a bug, wp-admin's own "All" view (and its sidebar bubble count) excludes spam and trashed comments by default, while this total deliberately counts every row, since that's exactly what gets deleted. Added a status breakdown (approved/pending/spam/trash) directly under the count, with an explanatory note, so the discrepancy is no longer confusing.

= 0.8.1 =
* New: "Maintenance" — a separate top-level admin menu (own icon, next to "RSD Backup") for one-off site-maintenance tasks, organized into tabs the same way the Settings screen is. First tab: "Delete All Comments", aimed at sites on hosting that gets flooded with spam comments — shows the current total comment count, then wipes every comment on the site (approved, pending, spam, and trash alike) in a single confirmed click. Implemented as a direct bulk SQL delete rather than looping WordPress's own per-comment deletion API, so it completes in one request regardless of how many comments exist (no background job, no progress bar) — deliberately trading off the normal per-comment deletion hooks (e.g. Akismet's stats aren't notified) for reliability on hosts where this plugin's own diagnostics already show WP-Cron/Action Scheduler can be unreliable.
* Fix: found via a live site where a OneDrive connection silently "went stale" over time and reconnecting failed claiming there was no valid secret. Root cause: the encryption key used for stored OAuth tokens and provider client secrets was derived partly from `home_url()`, which is not actually stable over a site's lifetime (an HTTP→HTTPS migration, a domain change, a www/non-www change) — any such change silently made every previously-stored secret undecryptable, and the old fallback logic wrongly treated that decrypt failure as "legacy plaintext," returning the raw ciphertext blob as if it were the real secret. The key formula no longer includes `home_url()`; values encrypted under the old formula are still read correctly via an automatic one-time legacy-key fallback and re-saved under the new formula, so no site needs to manually reconnect or re-enter credentials to pick this up.
* New: `/server-stats` now includes a `provider_connection` field that makes one real, uncached API call to the currently configured provider (Google Drive or OneDrive) to confirm the stored credentials actually work right now — not just that a token is stored. Surfaces the exact class of failure above proactively, on the CRM's own polling cadence, rather than only when the next real upload attempt happens to hit it.
* New: substantially more detailed logging around the scan/upload pipeline — every scheduled scan tick now logs entry, a queue-health snapshot (job counts by status), and an exit summary (stalled reset / queued / scheduled counts); every concurrency-slot check and Action Scheduler dedup check logs the specific counts/action ids involved. Aimed at answering "why isn't this uploading" directly from the log on sites with no DB access.

= 0.8.0 =
* New: `/server-stats` now includes a top-level `ssl` field reporting this site's own certificate status (issuer, expiry, days remaining), checked directly from the site itself. This lets certificate expiry be monitored even for sites whose TLS version can't be checked remotely by the monitoring host.

= 0.7.19 =
* Fix: saving OneDrive (or Google Drive) credentials could fatal with "Undefined constant AUTH_KEY" on a site whose wp-config.php doesn't define all four of WordPress's standard AUTH_KEY/SECURE_AUTH_KEY/LOGGED_IN_KEY/NONCE_KEY security keys. The plugin's at-rest encryption now uses WordPress's own `wp_salt()` accessor instead of referencing those constants directly — identical result (and no re-entry needed) on every site that already has all four defined; sites missing one no longer fatal.

= 0.7.18 =
* Fix: found via a brand-new install still showing "Scheduled Scan: Not scheduled at all" even on v0.7.14+ (which fixed this for the version-bump case only) — a fresh plugin activation never goes through the code path that registers the custom "every 15 minutes" cron interval at all (activation runs in its own request, separate from the normal plugins_loaded bootstrap), so `wp_schedule_event()` silently failed on every first-time install, not just after the event was somehow cleared. The scan scheduler now registers its own interval immediately before use, regardless of which code path (fresh activation or version-bump) calls it.

= 0.7.17 =
* Fix: the new Files/Upload Queue tabs on the Backups screen (added in 0.7.16) didn't switch when clicked — the URL hash changed but the visible content never did. Root cause: `admin_enqueue_scripts` matched against a hand-built guess at the Backups page's hook suffix, which didn't actually match what WordPress generates for it, so the tab-switching script was never loading on that screen at all. Now uses the real hook suffixes returned directly by `add_menu_page()`/`add_submenu_page()` instead of guessing.

= 0.7.16 =
* New: "Files" tab added to the Backups admin screen — one row per detected backup with a simplified status (Detected, Uploading, or Uploaded) and its current location. Once a backup is gone from both the local server and the remote provider (e.g. pruned by retention), it now disappears from this list instead of sitting stuck showing "Detected" or "Uploading" forever.
* Changed: the Backups admin screen is now organized into 3 tabs — "Backups" (unchanged download/restore screen), the new "Files" tab, and "Upload Queue" (moved here from Settings → Status &amp; Log, unchanged otherwise).
* No REST API changes.

= 0.7.15 =
* Fix: found via a live-site report — the Status tab's "Scheduled Scan" next-due time and the "Next scheduled scan" note were shown in this site's own configured WordPress timezone, while the log timestamps are (and remain) fixed UTC. On a site whose WordPress timezone isn't UTC, this made the two look inconsistent with each other even though both were individually correct. Both displays are now fixed UTC, explicitly labeled, matching the log — always directly comparable regardless of this site's timezone setting.
* Fix: log lines now explicitly say "UTC" next to their timestamp, removing any ambiguity when comparing logs across sites in different timezones.

= 0.7.14 =
* Fix: found via the new v0.7.13 diagnostics on a live site — the scheduled scan/upload event (`rsd_rb_scan`) could end up permanently unscheduled (`wp_next_scheduled()` finding nothing) even with WP-Cron and Action Scheduler both healthy. Root cause: the plugin registered its custom "every 15 minutes" cron interval *after* already trying to schedule an event using it on the same request, so `wp_schedule_event()` silently failed. Fixed by registering the interval first. Updating to this version automatically re-schedules the event if it was missing — no manual action needed.
* Fix: `wp_schedule_event()` failures are no longer silent — a failure to schedule the scan is now logged with the reason.

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
