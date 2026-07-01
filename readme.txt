=== Native Link Health ===
Contributors: nativelinkhealth
Tags: broken-link-checker, link-checker, internal-links, seo, maintenance
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.3.2
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A local broken-link scanner and internal-link authority analyzer for WordPress. Runs on your server, avoids false positives, never crashes your site. No cloud, no accounts.

== Description ==

**Native Link Health** finds broken links *and* maps your internal link authority, entirely on your own server. No external API, no cloud subscription, no account to connect.

**Why it exists:**

The most popular broken link checkers have a well-documented history of flagging working links as broken (false positives), spiking server load, and pushing intrusive upsells. Others moved to cloud-only models that send your content to a third party and gate basic functionality behind monthly credits.

Native Link Health is built differently from the ground up:

* **Native HTML parsing** — uses `WP_HTML_Tag_Processor`, WordPress's own HTML tokenizer. No regex on your content. No memory leaks. Safe for Gutenberg blocks and serialized data.
* **No false positives by design** — a brand-new failure must fail several spaced-out probes before it is ever reported, so transient timeouts, rate limits and bot-blocks (429/403/999) don't get flagged as broken.
* **Incremental, gentle scanning** — processes a few posts per cron cycle using HEAD requests (with a GET fallback). Never a spike, never a timeout. Safe to leave on permanently.
* **Stays on your server** — no content ever leaves your site. No cloud, no third-party account.

**What it does:**

*Broken link health*

* Scans posts and pages out of the box; optionally Custom Post Types, media, **approved comments**, and **navigation menus**.
* Dashboard with status codes, affected content, and grouping by domain or error type (4xx / 5xx / DNS / SSL / timeout / fragment).
* Safe in-place URL correction using `WP_HTML_Tag_Processor` — no regex, no database corruption.
* Anchor-fragment (`#section`) validation with automatic self-healing once the anchor resolves.
* On-record links re-checked on demand; CSV export with formula-injection protection.
* Optional JSON auto-fix rules to rewrite known bad domains during scans.

*Internal link authority (Link Juice)*

* Builds an offline internal-link map and computes a PageRank-style authority score per page — no HTTP, fully local.
* Flags **orphan** pages (no inbound links), **dead ends** (no internal outbound), and **diluted** pages (too many outbound links).
* Per-page link flow diagram, a site-wide authority graph, and a global Authority Health Score.
* Plain-language recommendations that deep-link you to the editor to improve your structure.

*SEO audit*

* Checks for orphan pages, mixed content, invalid canonicals and redundant links.

== Installation ==

1. Upload the `native-link-health` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins menu in WordPress.
3. Go to **Tools → Link Health** to view the dashboard and run your first scan.
4. The background scanner starts automatically via WordPress Cron. No configuration required.

== Frequently Asked Questions ==

= Will this plugin slow down my site? =

No. It processes a few posts per cron cycle using HEAD requests and caches valid links for 48 hours. CPU and memory impact is negligible, even on large sites.

= Does it work with WooCommerce or custom post types? =

Yes. Posts and pages are always scanned. You can opt into Custom Post Types, media, comments and navigation menus under **Tools → Link Health → Settings → Scan Scope**.

= Does it replace URLs using regex? =

Never. URL corrections use WordPress's native `WP_HTML_Tag_Processor`, which tokenizes HTML safely and handles nested tags, Gutenberg blocks and serialized data without corruption.

= What HTTP status codes are flagged as broken? =

400, 401, 403, 404, 410, 5xx, and connection errors — but only after a link fails several spaced-out probes, so transient blips are not reported. Redirects (301/302) are followed (up to 5 hops) and not flagged unless the final destination returns an error. Soft codes such as 429/999 are treated as "could not verify", not broken.

= What is the Authority Health Score? =

It is an offline analysis of how link authority flows between your own pages (internal links). It highlights orphan pages, dead ends and over-diluted pages so you can strengthen your internal linking for SEO. It runs entirely on your server and makes no HTTP requests.

= Does it require an external API or cloud service? =

No. All scanning and analysis runs on your server. Nothing is sent anywhere.

== Screenshots ==

1. Dashboard showing broken links with status code, error type, and affected content.
2. Detail view for a single broken link with a safe correction field.
3. Link Juice authority graph and the global Authority Health Score.
4. Settings page with scan scope (post types, comments, menus) and auto-fix rules.

== Changelog ==

= 1.3.2 =
* Fixed false-positive "broken" results on Cloudflare-protected sites: JS bot-challenge pages ("Just a moment...") are now detected and treated as "could not verify" instead of being flagged as broken.
* Fixed internationalized domain name (IDN) links being silently skipped when the server environment can't convert them to ASCII; they are now surfaced as a clear error instead of disappearing from scan results.
* Dashboard: added a banner showing scan progress until the first full scan completes.
* Dashboard: added explanatory tooltips to status, impact, source, and regression badges.

= 1.3.1 =
* Added an automated test suite covering the anti-false-positive scanning logic and URL-parsing helpers.
* Added a WordPress Coding Standards ruleset for the codebase (development-only, no functional change).

= 1.3.0 =
* Faster first value: the background scanner now prioritises recently-modified and never-scanned content first, and the full-scan progress bar shows a real time-remaining estimate. Scan a single post instantly from the editor sidebar.
* New unified **Link Health Score** on the dashboard: one headline that blends broken-link detection with internal-link authority, with drill-downs into each module.
* Redirect-chain detection is now real (and free): the SEO audit follows your internal links and flags multi-hop chains and redirects that dead-end — no more placeholder.
* Records a daily Link Health Score history (groundwork for trends).
* Clarified the difference between the Link Juice and SEO Audit orphan counts.
* Added a Freemius-based licensing layer that ships **disabled** — no upsells and no external calls on the free build. Every premium capability is gated behind WordPress filters so the optional Pro add-on can extend the plugin without touching the free core. Detection is never gated.

= 1.2.0 =
* Internal-link authority module (Link Juice): offline link map, PageRank scores, orphan/dead-end/diluted detection, per-page flow diagram, site authority graph, recommendations, and a global Authority Health Score.
* Broken-link data is now surfaced inside the authority graph.
* Extended scan scope: opt into Custom Post Types, media, approved comments, and navigation menus.
* Removed non-functional "Available in Pro" settings stubs (scan frequency, ignored domains, email) that had no backend.
* Corrected documentation: redirect following is 5 hops; Custom Post Types are supported.
* Uninstall now removes all plugin tables, options, and cached transients.

= 1.0.1 =
* Fixed content comparison always passing on post updates, preventing cache invalidation.
* Fixed bulk replacement URL over-matching hostname in path/query components.
* Fixed path-relative URLs (./, ../) being silently skipped during scanning.
* Fixed IDN hostnames not resolving correctly due to percent-encoding.
* Fixed validate_fragments() returning valid for unverifiable fragment-only links.

= 1.0.0 =
* Initial release.
