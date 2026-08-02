# native-link-health — key conventions

Scans posts/pages — and optionally CPTs, media, comments, and navigation menus — for broken links via `WP_HTML_Tag_Processor` plus incremental WP Cron (5 posts every 15 min, `NLH_BATCH_SIZE=5`). Also ships a **Link Juice** module (offline internal-link map + PageRank authority analysis). Requires PHP 8.0+ / WP 6.2+.

**Before editing this plugin, read `AGENTS.md` in this directory** — it is the single source of truth for its conventions (scanner pipeline, DB schema, caching, fragment healing, Link Juice, Pro gating, i18n). Non-negotiable invariants:

- Schema changes only via the versioned upgrade router in `class-nlh-activator.php` (`NLH_DB_VERSION` bump + `upgrade_to_X_X()`).
- HTML parsing exclusively via `WP_HTML_Tag_Processor` (regex only for bare URLs in text).
- All URL checking goes through the shared gated checker `NLH_Scanner::check_and_record_url()` — never reintroduce an inline pipeline; it owns the no-false-positive confirmation gate, soft codes, and caches.
- Security baseline in every new AJAX/admin path: `check_ajax_referer()`, `current_user_can('manage_options')`, `wp_safe_remote_*`, prepared `$wpdb`.
- i18n: keep `.pot`/`.po`/`.mo` in sync when strings change (text domain `native-link-health`).

Pending work is tracked in `Native Link Health - Pending Work Plan.md` (repo root) — check it before starting new NLH work.
