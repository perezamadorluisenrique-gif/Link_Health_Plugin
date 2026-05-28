=== Native Link Health ===
Contributors: [AUTHOR_NAME]
Tags: broken-link-checker, link-checker, seo, maintenance, links
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight broken link scanner for WordPress. Runs locally, never crashes your server, and avoids false positives. No cloud. No paywalls.

== Description ==

**Native Link Health** scans your WordPress site for broken links without touching your server's performance. It runs entirely on your server - no external APIs, no cloud subscriptions, no credit card required.

**Why it exists:**

The most popular broken link checker plugins have a well-documented history of crashing sites, corrupting databases through regex-based URL replacements, and generating false positives that waste administrator time. Others have moved to cloud-only models that gate basic functionality behind paid plans.

Native Link Health is built differently from the ground up:

* **Uses `WP_HTML_Tag_Processor`** - WordPress 6.2's native HTML tokenizer. No regex. No memory leaks. Safe for any content structure including serialized data.
* **Incremental batch scanning** - processes 5 posts per cron cycle. Never a spike. Never a timeout. Safe to leave on permanently.
* **HEAD-only HTTP requests** - checks link status without downloading full pages. Low bandwidth, fast results.
* **Smart transient caching** - valid links are cached for 48 hours. They are never re-checked unnecessarily.
* **Correct collation** - custom database table uses `utf8mb4_unicode_ci`, matching WordPress core standards. No collation conflicts on activation.
* **Intelligent retry logic** - rate-limited responses (429) are retried with backoff before reporting a broken link. This eliminates a major source of false positives.

**Free tier includes:**

* Full scan of all posts and pages
* Dashboard with broken link list, status codes, and affected post
* Manual re-check on demand
* In-place URL correction using `WP_HTML_Tag_Processor` (safe, no regex)
* Scan history and error log

**Pro tier adds:**

* Scan of Custom Post Types and post meta fields
* Configurable scan frequency (hourly, daily, weekly)
* Domain ignore/allowlist with wildcard support
* CSV export of all broken links
* Email notifications on new broken links
* Auto-redirect suggestion via integration with Redirection plugin (if installed)

== Installation ==

1. Upload the `native-link-health` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins menu in WordPress.
3. Navigate to **Tools -> Link Health** to view the dashboard and run your first scan.
4. The background scanner starts automatically via WordPress Cron. No configuration required.

== Frequently Asked Questions ==

= Will this plugin slow down my site? =

No. It processes 5 posts per cron cycle using HEAD-only HTTP requests, and caches valid links for 48 hours. CPU and memory impact is negligible.

= Does it work with WooCommerce or custom post types? =

The free version scans standard Posts and Pages. The Pro version adds Custom Post Types.

= Does it replace URLs using regex? =

Never. URL corrections use WordPress's native `WP_HTML_Tag_Processor`, which tokenizes HTML safely and handles nested tags, Gutenberg blocks, and serialized data without corruption.

= What HTTP status codes are flagged as broken? =

400, 401, 403, 404, 410, 5xx, and connection errors. 301 and 302 redirects are followed (up to 3 hops) and not flagged unless the final destination returns an error.

= Does it require an external API or cloud service? =

No. All scanning runs on your server.

== Screenshots ==

1. Dashboard showing broken links list with status code, URL, and affected post.
2. Detail view for a single broken link with correction input field.
3. Settings page showing scan configuration options (Pro).

== Changelog ==

= 1.0.1 =
* Fixed content comparison always passing on post updates, preventing cache invalidation.
* Fixed bulk replacement URL over-matching hostname in path/query components.
* Fixed path-relative URLs (./, ../) being silently skipped during scanning.
* Fixed IDN hostnames not resolving correctly due to percent-encoding.
* Fixed validate_fragments() returning valid for unverifiable fragment-only links.
* Removed unnecessary transient patterns from uninstall cleanup.
* Removed redundant ternary in run_full_scan().

= 1.0.0 =
* Initial release.
