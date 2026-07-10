# NLH Settings — Unified Help System — Design

Date: 2026-07-10
Status: Approved

## Context

The frontend review (`docs/frontend-review-2026-07-10.md`) confirmed `admin/partials/nlh-settings.php` has no "how to use this plugin" content — only the settings form, CSV export, and (when Pro is active) the Pro feature card + Email Notifications. Native Link Health has 4 admin screens (Dashboard, SEO Audit, Link Juice, Settings) with real feature depth (blended health score, 12 SEO checks, PageRank-style link authority, Pro-gated automation) and no single place a site owner can read to understand what any of it means. This closes Requirement 3 of the `nlh-frontend-review` skill's rubric: "a unified system with full info about how to use the plugin inserted on settings."

## Non-goals

- Not a rewrite/redesign of the settings form itself, CSV export, or the Pro card — those stay as-is.
- Not a searchable knowledge base or external docs site — this is inline reference content, static per release.
- No new AJAX endpoints, DB options, or per-user preference state (e.g. remembering which accordion panel was last open) — every panel resets closed on page load, by design (YAGNI).
- Does not fix the other two frontend-review findings (Link Juice's disabled Insert-link button, the foreign admin-notice pollution on all 4 screens) — unrelated fixes, tracked separately.
- Does not touch `native-link-health-pro` — Pro-only topics are described in the free plugin's help copy (labeled "PRO"), but no Pro-plugin code changes.

## Architecture

New partial: `admin/partials/nlh-help.php`, `include`'d from `admin/partials/nlh-settings.php` immediately after the `<h1>` and before the `<form>` (top-of-page placement).

Markup: a `<div class="nlh-help">` wrapping five `<details class="nlh-help-panel">` elements (Overview, Dashboard, SEO Audit, Link Juice, Settings), each with a `<summary>` title and a body `<div>`. Native `<details>`/`<summary>` — no JS, no build step, keyboard/screen-reader accessible by default. All panels closed by default (no `open` attribute).

Pro-labeled lines use an inline `<span class="nlh-help-pro-badge">PRO</span>` (new CSS in `nlh-admin.css`, visually consistent with but not identical markup to the existing Pro-card badge, since this one sits inline in body copy rather than a card heading). No `NLH_Pro::can()` gating on the badge itself — it's static descriptive copy explaining the product, always shown regardless of whether Pro is active, so free users know what Pro adds.

## Content (5 panels — copy finalized at implementation time, scope fixed here)

1. **Overview** — what NLH scans, how the blended health score works (60% broken-link detection / 40% link authority, per `NLH_Admin::render_health_overview()`).
2. **Dashboard** (`tools.php?page=nlh-dashboard`) — scan cadence (cron every 15 min, 5 posts/batch, configurable), manual "Scan Now", reading the health score card, and explicitly distinguishing "Sugerencias de corrección" (auto-detected domain-level suggestions) from "Bulk Fix & Find-Replace" (manual, any URL) — closes the should-fix ambiguity finding from the review.
3. **SEO Audit** (`tools.php?page=nlh-seo-audit`) — what the 12 checks look for, in plain language, grouped (orphans/redirects/mixed-content/canonicals/links, then image checks, then on-page checks) rather than an undifferentiated list of 12.
4. **Link Juice** (`tools.php?page=nlh-link-juice`) — what "juice"/authority means here, how to read the flow diagram and site graph, what orphan/dead-end/diluted mean, **[PRO]** one-click link insertion from Recommendations.
5. **Settings** (this page) — scan scope, batch size, what an auto-fix rule does, CSV export, **[PRO]** Bulk Fix & Find-Replace, Redirect Manager, Email Notifications.

## i18n

Every string wrapped in `esc_html__( '...', 'native-link-health' )` (or `esc_html_e()` where a direct echo reads cleaner) — plain text only, no markup inside translatable strings, so no `wp_kses_post()` question arises. After copy is finalized:

- `wp i18n make-pot . languages/native-link-health.pot`
- Translate the new msgids in `languages/native-link-health-es_ES.po`
- `wp i18n make-mo languages/native-link-health-es_ES.po`

This ordering matters: the frontend review's #1 must-fix was *existing* untranslated strings on this exact page. The new section ships with Spanish translations in the same commit — not as a follow-up.

## Wiring

- `admin/partials/nlh-settings.php` — one new `include NLH_PLUGIN_DIR . 'admin/partials/nlh-help.php';` line after the `<h1>`.
- `admin/css/nlh-admin.css` — new `.nlh-help`, `.nlh-help-panel`, `.nlh-help-pro-badge` rules only. No changes to existing selectors.
- No changes to `class-nlh-admin.php`, no new hooks, no new JS file.

## Docs & versioning

- `AGENTS.md` — note the new inline help accordion under the Settings/dashboard section.
- `readme.txt` — changelog entry; version bump (additive UI, so minor per the project's existing precedent with the image/SEO-checks release — exact number is the implementer's call at plan time).
- `native-link-health.php` — `NLH_VERSION` bumped to match.

## Testing

No new branching logic — this is static template content, not worth a `tests/test-*.php` harness entry (contrast with the SEO-checks design, which added real parsing/classification logic). Verification is manual/visual:

- Re-run the `nlh-frontend-review` skill against Settings — confirm Requirement 3 now reads as satisfied.
- `wp-verify`-style pass: load Settings, expand/collapse all 5 panels, confirm zero console errors, confirm the existing form/CSV export/Pro card still render unchanged below the new section.
- Switch the site locale to es_ES, reload, confirm every new string is translated — not a repeat of the must-fix bug this design exists to avoid.

## Out of scope

- Fixing the Link Juice disabled-Insert-button bug and the foreign admin-notice pollution — separate must-fix/should-fix items in `docs/frontend-review-2026-07-10.md`.
- Cross-linking from Dashboard/SEO Audit/Link Juice back to this help section (the review's should-fix suggestion) — a reasonable fast-follow, but not required for this design to satisfy Requirement 3, which only requires the info to exist on Settings.
