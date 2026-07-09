=== AI1WM Remote Backup ===
Contributors: rsd-systems
Tags: backup, google drive, onedrive, all-in-one wp migration, ai1wm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.7.3
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
