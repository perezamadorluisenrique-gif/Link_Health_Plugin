# NLH Frontend Review — 2026-07-10

Produced by the `nlh-frontend-review` skill (live Playwright pass over all 4 admin screens on the local `wpprueba` install). Screens covered: Dashboard, SEO Audit, Link Juice, Settings.

## Must-fix

- **[Settings] Hardcoded English strings break the Spanish locale.** The "No rules defined. Rules let the scanner automatically rewrite broken URLs during scans." rule-builder widget and the entire "Email Notifications" section (headings, field labels, helper text, button) render in English while every core-plugin string on the same page is correctly localized (es_ES). **Correction after source verification**: both offending strings live in `native-link-health-pro` (`nlhp-admin.js` for the rule builder, `class-nlhp-notifications.php`/`class-nlhp-settings.php` for Email Notifications) — the free `native-link-health` plugin's own strings on this page are all correctly wrapped and translated. This is a `native-link-health-pro` i18n bug, not a core-plugin bug; fixing it means touching the Pro plugin's own `.pot`/`.po`/`.mo` and wrapping its strings in `__()`/`_e()`, which is a separate, out-of-scope repo for the unified-help-system work. Still worth fixing — the visual effect (English text next to Spanish on the same page) is real regardless of which plugin owns it — but tracked here as a `native-link-health-pro` task, not a `native-link-health` one.

- **[Link Juice] "Insert link (Pro)" opens a fully-interactive modal whose primary action is a dead end.** Clicking it opens a real modal (target dropdown, editable anchor-text field, Preview/Insert/Cancel) — the user does real work selecting a target and editing anchor text — but `Insert` is `disabled` in the DOM with **no explanatory text anywhere in the modal**. The Pro card on Settings confirms Link Insertion isn't part of this install's active Pro features (Bulk Fix, Redirect Manager, and Email Notifications are active; link insertion isn't listed), so this is a real gating gap, not a bug in Pro licensing detection. This directly contradicts NLH's own stated principle (AGENTS.md: "never show disabled inputs" / advisory-only suggestions) and is a worse pattern than a straightforward upsell card — it invites the user to complete a task before failing silently. Fix: either disable/badge the triggering button itself so the modal never opens without entitlement, or show an inline upsell message next to the disabled `Insert` button explaining why it's disabled.

## Should-fix

- **[All screens] A foreign plugin's admin notice pollutes every NLH page.** "Tu sitio no está conectado con Broken Link Checker. Conéctate ahora..." appears at the top of Dashboard, SEO Audit, Link Juice, and Settings alike. Confirmed via source search: this string lives in `broken-link-checker-seo` (AIOSEO), not NLH — it's a global `admin_notices` hook that isn't scoped to that plugin's own screens. Not an NLH code bug, but it's the first thing a user sees on every NLH screen, competing visually with NLH's own health-score banner, and it also throws a console error on load (`TypeError: Cannot read properties of null (reading 'addEventListener') at dismissNotice`) — degrading the "0 JS errors" baseline even though NLH isn't the source. Common WP convention: scope-hide foreign `.notice` elements on your own tool pages (e.g. CSS targeting `get_current_screen()->id`) so third-party upsells can't visually hijack your UI regardless of what else is installed on the site.

- **[Dashboard] Two overlapping bulk-fix mechanisms with no stated relationship.** "Sugerencias de corrección" (domain-level auto-suggestions with an "Aprobar todo" button) sits directly above a separate "Bulk Fix & Find-Replace" form (manual old-URL/new-URL). Both do URL rewriting; nothing on the page explains when to use one over the other. A short one-line distinction ("Suggestions are auto-detected; use Find & Replace for anything not suggested") would remove the ambiguity.

- **[Dashboard → Settings] No cross-link to help.** Once a unified help/info system exists on Settings (see Must-fix... actually see Requirement 3 gap below), Dashboard, SEO Audit, and Link Juice should each carry a lightweight "How this works" link pointing there — otherwise the unified system is unified only if you already know to look in Settings.

## Requirement 3 — Unified help system (confirmed gap)

`admin/partials/nlh-settings.php` contains only: the settings form (Scan Settings, Auto-fix Rules), CSV export, the Pro feature card, and (when Pro is active) Email Notifications. There is **no "how to use this plugin" section** anywhere on Settings — confirmed live, not just from reading the template. This is the clearest actionable target for Requirement 3: one collapsible/tabbed info block on Settings covering what each of the 4 screens does, what the health score means, how Scan Scope/rules work, and what the Pro card unlocks.

## Nice-to-have

- Link Juice's force-directed graph and its "Insert link" modal are visually the most custom/bespoke UI in the plugin (hand-rolled SVG, modal chrome) — worth a pass to confirm they read as part of the same system as the plain WP-admin tables elsewhere, once the must-fix items above are addressed.
- The health-score cards (Dashboard's "42 DEFICIENTE", Link Juice's "40/100 SALUD DE LA AUTORIDAD") use consistent color-banded card language — this is a genuine strength worth preserving as the template for any new dashboard widgets.

## Screens captured

Dashboard, SEO Audit, Link Juice (including the force-directed graph and the Insert-link modal), Settings — all at 1440×900, full-page.
