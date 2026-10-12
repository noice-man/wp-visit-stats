=== WP Visit Stats ===
Contributors: noice-man
Tags: analytics, visitors, statistics, pageviews, privacy
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

First-party WordPress analytics with visitors, sessions, pageviews and sortable visit metrics for content. Backup for basic Yandex Metrika traffic metrics.

== Description ==
WP Visit Stats provides independent visitor, session and pageview metrics using a WordPress database table and first-party identifiers. It does not use remote analytics APIs. Admin reports include trends, date filters, visits by page/post/CPT, referring domains, campaign source and device types. Browser events require JavaScript, and consent may be required by applicable law.

== Installation ==
Install the plugin ZIP via the WordPress Plugins screen, activate and open "Статистика визитов". Configure privacy settings before use.

== Frequently Asked Questions ==
= Is this a full Yandex Metrika replacement? =
No. It is a fallback for basic visits, sessions and views, not advanced attribution or replay.

= Does it work if WordPress is down? =
No. Tracking requires your WordPress server, PHP and database to work.

= Is cookie consent supported? =
Yes, use the consent_required option and the JavaScript API documented in docs/SETUP.md.

== Changelog ==
= 1.0.5 =
* Add traffic channels widget: search engines, social networks, referral websites, direct, paid ads, email and UTM campaigns.
* Retroactive session attribution from stored referrer and UTM data, no database migration.
* Integrate WPR Update Hub SDK v2; preserves independent visitor tracking.
* Identify Yandex Browser separately from Chrome.
* Show native WP auto-update toggle without forcing auto-update on, including when hub has no new release.

= 1.0.4 =
* Add WPR Update Hub client v2 for WordPress native plugin updates.

= 1.0.3 =
* Add Today preset and interactive chart tooltips.

= 1.0.2 =
* Add GitHub release checks (superseded by WPR Hub in 1.0.4).

= 1.0.1 =
* Fix zero visitor and pageview columns on the WordPress post/page/CPT list screens.
* Server-side permalink attribution even if cached pages send an outdated post ID.
* Display legacy unassigned pageviews and incrementally recover their post IDs.

= 1.0.0 =
* Initial release.
