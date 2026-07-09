=== AI1WM Remote Backup ===
Contributors: rsd-systems
Tags: backup, google drive, onedrive, all-in-one wp migration, ai1wm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.7.6
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
