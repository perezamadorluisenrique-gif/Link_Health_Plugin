# Image Health + On-Page SEO Checks — Design

Date: 2026-07-08
Status: Approved

## Context

`docs/../Notes/NLH - Funcionalidades a Añadir Según la Investigación de Gaps de Mercado.md` (external research note, not in this repo) identified two low-effort additions to Native Link Health's existing SEO audit module (`class-nlh-seo-audit.php`, page `nlh-seo-audit`):

1. Image health checks (alt text, declared-vs-real dimensions, legacy format) — opportunity #4 from the market-gap research.
2. Classic on-page SEO checks (title length, meta description, heading hierarchy, keyword density) — opportunity #5.

Both ship in **native-link-health** (the free plugin), not native-link-health-pro. The existing SEO audit module already has 5 free checks (orphan pages, redirect chains, mixed content, invalid canonicals, redundant links) with no Pro gating — these 7 new checks follow the same pattern: no `NLH_Pro::can()` gate, no upsell.

## Non-goals

- No image compression/optimization (that's the Smush/Imagify/EWWW territory NLH intentionally avoids).
- No dependency on Yoast/RankMath/AIOSEO postmeta — everything is computed from WP core data only.
- No HTTP fetch of external images (dimension/format checks are local-media-only, no cloud calls).
- No "focus keyword" input UI — keyword density auto-detects from the post title.
- No changes to `native-link-health-pro`.

## Section A — Image health checks

Three new public methods on `NLH_SEO_Audit`, following the exact pattern of the 5 existing `audit_*` methods (loop `get_public_posts()`, parse with `WP_HTML_Tag_Processor`, return via the shared `result()` helper).

### Shared helper

```php
private function get_image_tags( string $content ): array
```
One parse of `IMG` tags per post, returning `[{src, alt, width, height}, ...]` (raw attribute values, `null` when absent). Mirrors the existing `extract_attribute_values()` / `extract_src_values()` private helpers already in the file.

```php
private function resolve_local_attachment_file( string $src ): string
```
`attachment_url_to_postid( $src )` → `get_attached_file()` → returns the file path, or `''` if the image isn't in this site's media library (external/CDN images are skipped, no HTTP fetch — same restriction philosophy as `audit_mixed_content()`'s same-host check).

### `audit_missing_alt_text(): array`

Flags `IMG` tags where the `alt` attribute is **absent** (`get_attribute()` returns `null`). `alt=""` is treated as intentional (decorative image, valid per WCAG) and is **not** flagged — flagging it would be a false positive on sites already following accessibility best practice, which contradicts NLH's no-false-positives brand.

Result key: `missing_alt_text`.

### `audit_image_dimension_mismatch(): array`

Only runs on images that resolve to a local attachment. Skips images missing a `width` or `height` HTML attribute, or where either value isn't purely numeric (e.g. `width="100%"`). Compares the declared `width`/`height` against `getimagesize( $file )`; flags a mismatch with both values in the detail text.

Result key: `image_dimension_mismatch`.

### `audit_legacy_image_format(): array`

Only runs on images that resolve to a local attachment. Flags `.jpg` / `.jpeg` / `.png` extensions (not `.gif`, which is often intentionally animated) as "legacy format, consider WebP/AVIF" — no comparison against any other version of the file, just a straightforward extension check.

Result key: `legacy_image_format`.

## Section B — On-page SEO checks

Four new public methods on `NLH_SEO_Audit`, same pattern.

### `audit_title_length(): array`

Measures `post_title` with `mb_strlen()` (guarded by `function_exists( 'mb_strlen' )`, falls back to `strlen()`). Flags titles shorter than `nlh_seo_title_min_length` (default **30**) or longer than `nlh_seo_title_max_length` (default **60**) — both filters.

Result key: `title_length`.

### `audit_meta_description(): array`

Uses `get_the_excerpt( $post )` — WP's own excerpt (manual `post_excerpt` if set, otherwise auto-generated from content). The result message is explicit that this measures the **excerpt** (commonly used by themes/plugins as the meta description), not a literal `<meta name="description">` tag — WP core doesn't render one without an SEO plugin, and the copy must stay honest about what's actually being measured. Flags empty, shorter than `nlh_seo_meta_description_min_length` (default **50**), or longer than `nlh_seo_meta_description_max_length` (default **160**).

Result key: `meta_description`.

### `audit_heading_hierarchy(): array`

Walks `H1`-`H6` tags in `post_content` via `WP_HTML_Tag_Processor` (new private helper `get_heading_levels( string $content ): int[]`). Flags:
- More than one `H1` in the content.
- A forward skip when descending (e.g. `H2` directly to `H4`, skipping `H3`). Ascending back out (`H4` → `H2`) is normal nesting and is not flagged.

Deliberately does **not** flag "no H1 in content" — themes typically render the post title as the page's `H1` outside of `post_content`, so a missing H1 inside the content body is normal on most WordPress sites, and flagging it would be a false positive for the majority case.

Result key: `heading_hierarchy`.

### `audit_keyword_density(): array`

WP core has no "focus keyword" field, so the check auto-detects one: the longest word (≥4 chars) in `post_title` that isn't in a local EN/ES stopword list (a small list duplicated in this class — not shared with `class-nlh-link-recommendations.php`'s similar list, to avoid coupling two independently-evolving classes over a ~50-word array). Posts under 20 words are skipped (too short to measure meaningfully).

Only two states are flagged (informational-only density values are not listed, to keep the check action-oriented):
- The detected keyword never appears in the body content (title/content mismatch).
- The keyword's density exceeds `nlh_seo_keyword_density_max` (default **3.0%**) — possible keyword stuffing.

Result key: `keyword_density`.

## Wiring

- `admin/class-nlh-admin.php::ajax_run_seo_audit()` — add the 7 new keys to the `$results` array (same `nlh_seo_audit_results` transient, 1-day cache, unchanged).
- Same file's `wp_localize_script()` call — add 7 new i18n title strings alongside the existing 5 (`seoOrphanPages` etc.): `seoMissingAltText`, `seoImageDimensionMismatch`, `seoLegacyImageFormat`, `seoTitleLength`, `seoMetaDescription`, `seoHeadingHierarchy`, `seoKeywordDensity`.
- `admin/js/nlh-admin.js::renderSeoResults()` — add the 7 keys to the `titles` map. No other JS changes; the renderer is already generic over `Object.keys(results)`.
- `admin/partials/nlh-seo-dashboard.php` — no changes; it already renders whatever `#nlh-seo-results` receives.
- **No Pro gating** on any of the 7 new checks — free from day one, matching the 5 existing checks. `native-link-health-pro` is not touched.

## Docs & versioning

- `AGENTS.md` — update the "SEO audit: 5 checks" line to reflect 12 checks total, listing the 3 image + 4 on-page checks and their new filters.
- `readme.txt` — version bump to **1.4.0** (new feature, not a bugfix) + changelog entry.
- `native-link-health.php` — `NLH_VERSION` bumped to `1.4.0` to match.
- `languages/native-link-health.pot` — regenerate via `wp i18n make-pot` (new strings only); `languages/native-link-health-es_ES.po` gets the new Spanish translations, `.mo` recompiled via `wp i18n make-mo`.

## Testing

New `tests/test-seo-audit-helpers.php`, mirroring the existing standalone/reflection harness (`tests/test-scanner-helpers.php`) — no WP bootstrap, just the same `__()` i18n stub. Covers the pure, WP-function-light logic:
- Focus-keyword extraction from a title (stopword filtering, length ≥4, longest-candidate selection).
- Heading-level-skip detection (forward skip flagged, ascending-out not flagged, multiple-H1 flagged).
- Legacy-format-by-extension detection (jpg/jpeg/png flagged, gif not).
- Title / meta-description length banding (below-min, in-range, above-max).

Run via `php tests/test-seo-audit-helpers.php`; non-zero exit on failure, same as the scanner harness.

## Out of scope (per the source note)

Item #3 of the research note ("apply launch tactics from the market-gap research") is not code — it's WordPress.org listing/marketing work, tracked separately, not part of this design.
