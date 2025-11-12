=== CDB Reverse Access Rank (Full) ===
Contributors: cdb
Tags: referrer, analytics, rank, backlinks
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.4
Requires PHP: 7.4
License: GPLv2 or later

A full-featured reverse access ranking plugin for WordPress: logs referrers (domain/page/title), shows period-based rankings, REST/CSV, shortcode & block, bot filtering, daily housekeeping, Slack/Discord notifications, opt-out, and partner badge.

== Description ==
- Logs external referrers using HTTP_REFERER + a client beacon to capture document.title
- Shows domain/page/UTM/destination rankings with filters and CSV
- REST endpoint: /wp-json/cdb-ref/v1/rank
- Admin: Overview, Sources (opt-out toggle), Settings (blocklist, allowlist, webhooks, API)
- Cron: Daily housekeeping + notifications for strong new referrers
- Shortcodes: [cdb_referral_rank], [cdb_referrer_badge]
- Gutenberg block: "CDB 逆アクセスランキング"

== Installation ==
1. Upload the `cdb-reverse-rank` folder to `/wp-content/plugins/`
2. Activate via Plugins
3. Insert `[cdb_referral_rank]` on a page or use the Gutenberg block

== Frequently Asked Questions ==
= Does it store IPs? =
Only hashed IP (sha256) and UA hash for rough dedup/security checks.

== Changelog ==
= 1.0.4 =
- Public UI fully rewritten (no CSV, no legacy markup)
- Ensure modern CSS enqueued
- Highlight Top 5 + partner chip

= 1.0.3 =
- Remove CSV button from public UI
- Add partner badge indicator (allowlist or threshold)
- Highlight top 5
- Modern UI/CSS for public table

= 1.0.2 =
Fix admin submenu permission error by registering menus on admin_menu hook (not init).

= 1.0.1 =
Add pretty admin URL redirects (/wp-admin/cdb-rr-*) and plugin action links.

= 1.0.0 =
Initial full release.
