# Fable 5 refinement pass — self-review (2026-07-12)

Gap analysis of **Native Link Health** (core, v1.5.0) and **Native Link Health Pro** (v1.0.0)
against the WordPress.org "Beginner WordPress Developer" course
(`C:\Users\perez\Documents\WordPress-Beginner-Developer-Course\`), followed by targeted
refinements. Course sections read in full: 6 (Plugin Development), 9 (Security),
4 (Hooks), 10 (Internationalization), 8 (REST API), 12 (Debugging), 11 (Multisite).

**Overall verdict first:** this is a mature codebase that already complies with nearly
everything the course teaches. Every AJAX handler in both plugins carries a nonce and a
capability check; all input is sanitized and output escaped; every `$wpdb` query with a
variable is prepared; assets are versioned and conditionally enqueued; every include file
has an ABSPATH guard; there is no debug debris (`error_log`/`var_dump`) anywhere. The gaps
found were at the edges — headers, multisite activation, one nonce ordering, one
safe-redirect interaction, and Pro's translation files lagging its own growth.

---

## 1. What changed and why

### Pro plugin (`native-link-health-pro/`, new git repo)

| Commit | Change | Course grounding |
|---|---|---|
| `8ff88b2` | **Plugin header completed** (`native-link-health-pro.php`): added `Requires Plugins: native-link-health` (WP 6.5+ enforces the core dependency at install time instead of only the runtime `version_compare` notice), `Plugin URI`/`Author URI` matching core, and `Update URI: false` so wordpress.org can never serve an update for this private slug. | §6 *Plugin Requirements* + the Plugin Handbook header-requirements page it links |
| `c27cc7b` | **Nonce verified before capability** in `NLHP_Reporting::render_print_document()`. Was the only handler in the suite checking access control before CSRF. | §9 *Fixing Common Security Vulnerabilities* ("in general, check for CSRF first, and then check for access control") |
| `8a1e268` | **External redirect destinations now work** (`NLHP_Redirects::allow_target_host()`, new tests): `wp_safe_redirect()` silently falls back to wp-admin for hosts not in `allowed_redirect_hosts`, so any managed redirect pointing off-site never reached its destination. The matched record's host — trusted admin input created behind nonce + `manage_options` — is allowed just-in-time, per request. Unrelated hosts stay blocked; `wp_safe_redirect` itself is kept per the lesson's open-redirect guidance. | §9 (Bonus round — Open Redirect) |
| `1dc780d` | **Multisite-aware activation** (`native-link-health-pro.php`): the activation callback now honors `$network_wide` (loops all sites via `get_sites()` + `switch_to_blog()` to create each per-site `nlhp_redirects` table) and a `wp_initialize_site` hook provisions the table for sites created later, guarded by the `active_sitewide_plugins` network option. Previously subsites relied solely on the lazy per-site `maybe_upgrade()` self-heal. This is literally the course's worked example (per-site custom tables). | §11 *Building plugins and themes that support multisite* |
| `ebec322` | **i18n brought in sync**: regenerated `native-link-health-pro.pot` — the shipped one predated the link-insert, multisite, and reporting modules, so **41 of 135 strings were missing**. Shipped a complete **Spanish (es_ES) translation** (`.po` + `.mo`, 138 entries incl. header meta), matching core's existing es_ES terminology. Removed `esc_html__( '50' )` around a literal chart-axis numeral (not translatable content). | §10 *Internationalization* |
| `6d26d81` | **AGENTS.md rewritten to match reality**: it still described the 2026-06-29 scaffold state — features 3–6 marked "not done" although notifications, settings UI, link insertion, reporting, and the multisite network dashboard are all implemented and tested; file map, nonce inventory, and boot sequence updated; refinement-pass changes documented. | Task requirement; accuracy of the single source of truth |

### Core plugin (`native-link-health/`, existing repo)

| Commit | Change | Course grounding |
|---|---|---|
| `c52cf74` | **AGENTS.md accuracy fixes**: version said 1.3.3 (actual 1.5.0), POT count said 318 (actual 362), and the "handful of placeholder strings still lack translators comments" caveat was replaced — a static check now finds every placeholder string carries one within two lines of its gettext call. | §10; doc accuracy |
| (this commit) | This review document. | Task requirement |

No core *code* was changed: the audit found nothing in core that the course contradicts
(see section 2).

---

## 2. What was evaluated and deliberately left unchanged

- **Plugin header placeholders (core).** `[PLUGIN_URI]`/`[AUTHOR_NAME]`/`[UPGRADE_URL]` from
  the old v1.0.1 notes are **already resolved**: the header carries real Plugin URI, Author,
  License, Text Domain, Domain Path, Requires fields. `NLH_UPGRADE_URL = ''` and
  `NLH_AUTHOR_WP_HANDLE = '[WP_ORG_USERNAME]'` are *intentional* placeholders documented in
  AGENTS.md's monetization section (owner fills them at commercial launch). Left as designed.

- **AJAX → REST migration: recommend against, with one future exception.** The course
  (§8, §12) shows custom REST routes with `permission_callback`. NLH's 14 core + 12 Pro
  handlers are all admin-only, nonce-guarded, `manage_options`/`manage_network`-gated
  operations driving wp-admin UI — exactly the shape admin-ajax serves fine. Converting
  them would churn ~26 endpoints and their JS for no security or functional gain (REST's
  cookie auth still needs the same nonce, sent as `X-WP-Nonce`). **Recommendation:** keep
  admin-ajax; if/when the Pro reporting or multisite features need external automation
  (agencies polling site health via application passwords — §8's authentication lesson),
  add a small read-only `nlh/v1` namespace with a real `permission_callback` at that point.
  Not implemented now: no current consumer, and the hard limits rightly discourage
  speculative rewrites.

- **JS i18n via `wp.i18n`/`wp_set_script_translations` (§10's JS pattern).** The plugins
  pass server-side-translated strings to JS via `wp_localize_script` — a sanctioned pattern.
  The historically-flagged `.replace()` word-order fragility is already mitigated: strings
  use *numbered* placeholders (`%1$d`, `%2$d`), which translators can reorder freely
  (`admin/js/nlh-admin.js:435,485`, `nlh-juice.js:103`). Residual limitation: JS
  `String.replace()` with a string pattern substitutes only the first occurrence, so a
  translation repeating the same placeholder twice would misrender — no current translation
  does. Migrating to `wp.i18n` would require JSON translation files (`wp i18n make-json`)
  and wp-cli, which this machine lacks. Documented recommendation, not forced.

- **Scanner uses `wp_remote_head/get` instead of `wp_safe_remote_*`**
  (`class-nlh-scanner.php:1963,1972`; `class-nlh-seo-audit.php:233,237`). Deliberate and
  correctly compensated: `wp_safe_remote_*` rejects non-resolvable hosts, which are precisely
  the broken links the scanner must detect. The SSRF guard the safe variants would provide
  exists in `is_scannable_url()` (localhost + private/reserved IP ranges,
  `class-nlh-scanner.php:1606-1613`), and the SEO-audit redirect tracer only probes the
  site's own host. Matches AGENTS.md's documented security model. No change.

- **Core security posture.** All 14 core AJAX handlers route through
  `verify_ajax_request()` (nonce → capability, `class-nlh-admin.php:1538`); `ajax_scan_post`
  correctly uses the finer-grained `edit_post` capability; CSV export neutralizes formula
  injection; settings use `register_setting` with strict sanitize callbacks; partials showed
  no unescaped output. Nothing to fix.

- **Debugging lessons (§12).** No `error_log`/`var_dump`/`print_r` debris in either plugin;
  no deprecated function usage found. Query Monitor needs no explicit integration — its
  value here (HTTP API calls, queries, hooks) comes free. `WP_DEBUG` stays off in
  `wp-config.php` per project convention. No change.

- **Pro version number.** Header still says 1.0.0 though features 3–6 landed after. Whether
  the first public release is "1.0.0 with everything" or a bumped number is the owner's
  product decision — noted in Pro AGENTS.md's "What is NOT done".

- **Pro reporting page enqueue, multisite batching, notifications queue cap** — audited
  against the lessons (selective enqueue via hook suffix; 10-site `switch_to_blog()` AJAX
  batches; 500-item forced flush): all already correct.

## 3. Test / lint evidence

**Core test harness** — identical before and after (run at baseline, after each core-repo
commit, and at the end):

```
$ php tests/test-scanner-helpers.php
classify_error_type():   6 PASS
trim_url_punctuation():  3 PASS
parse_srcset():          2 PASS
Ran 11 assertions, 0 failure(s).   (exit 0)
```

**phpcs**: not installed on this machine (`which phpcs` → not found), so no
WordPress-Coding-Standards run was possible. `php -l` was run on every touched PHP file
(all: "No syntax errors detected").

**Pro PHPUnit suite**: the WP test suite (`/tmp/wordpress-tests-lib`) is not installed on
this Windows/XAMPP machine, so the 15 existing + 2 new tests could not be executed here.
The two new tests (`test_allow_target_host_permits_external_destination`,
`test_allow_target_host_ignores_unparseable_destination`) follow the file's existing
Reflection pattern and use only WP-test-suite APIs already used by their neighbours.

**Translation verification** (substitute for `wp i18n make-mo`): the generated
`native-link-health-pro-es_ES.mo` was compiled with WordPress's own bundled `pomo`
library and round-trip verified by re-importing it — singular, plural (n=1 and n>1),
and multibyte entries all translate correctly; 138/138 entries present. The regenerated
`.pot` was diffed against a token-level extraction of the source: 0 strings missing,
0 stale.

## 4. Git state

**`native-link-health`** (pre-existing repo, branch `master`) — 2 new commits on top of
the 6 already-unpushed local commits (still 8 ahead of `origin/master` in total):

```
(this commit)  Add the Fable 5 refinement-pass self-review
c52cf74        Correct stale version and POT figures in AGENTS.md
```

**`native-link-health-pro`** (repo initialized this pass, branch `master`, no remote):

```
6d26d81  Bring AGENTS.md up to date with the implemented feature set
ebec322  Regenerate the POT and ship a complete Spanish (es_ES) translation
1dc780d  Create the redirects table per site on network-wide activation
8a1e268  Allow external redirect destinations through wp_safe_redirect
c27cc7b  Verify the nonce before the capability check in the PDF report handler
8ff88b2  Complete the plugin header per the WP header requirements
affa282  Baseline before Fable 5 refinement pass
```

**Nothing was pushed.** The Pro repo has no remote configured; core's `origin` was not
touched.

## 5. Not fully confident about

1. **The es_ES translation quality is mine, not a native reviewer's.** Terminology was
   matched to core's existing es_ES file (informal "tú", "Enlaces rotos", "Escanear"), but
   the 138 Pro strings should get a human read before shipping. Specific judgment calls:
   "Bulk Fix" → "Corrección masiva", "Hits" → "Visitas", "digest" → "resumen",
   "dashboard" → "escritorio" (wp.org convention).

2. **The hand-rolled `.pot` regeneration.** My extractor mimics `wp i18n make-pot`
   (token-based, plurals, contexts, translators comments, header entries) and was verified
   by an independent diff against source strings, but ordering/formatting details may differ
   slightly from the real tool. Re-running `wp i18n make-pot` when wp-cli is available
   should produce no *content* change — if it reorders lines, prefer its output.

3. **The new Pro tests are unexecuted here** (no WP test suite on this machine). They are
   syntax-checked and pattern-matched to passing neighbours, but "written, not run" — run
   `WP_TESTS_DIR=... phpunit` in a suitable environment before trusting them.

4. **`wp_initialize_site` guard choice.** I check `active_sitewide_plugins` directly
   rather than `is_plugin_active_for_network()` (which needs `wp-admin/includes/plugin.php`
   in some contexts). This is a common, dependency-free pattern, but it means a
   *per-site-activated* Pro on a multisite does not auto-provision tables for brand-new
   sites — correct behavior in my reading (per-site activation implies per-site opt-in),
   and the lazy `maybe_upgrade()` still covers any site that later activates it.

5. **`Requires Plugins` on a non-wp.org plugin.** The header key is designed around
   wp.org slugs; for a ZIP-distributed Pro it correctly blocks activation when
   `native-link-health/` is absent, but the "install this dependency" UI link will point
   at the wp.org slug. Harmless today (the slug is core's real target), worth remembering
   if the core plugin is ever renamed.

---

## Addendum — recommendations applied (same day, follow-up session)

- **Live end-to-end verification** replaced the unrunnable PHPUnit suite: a script loading
  the real `wp-load.php` (XAMPP MySQL started for the run) exercised the new
  `allow_target_host()` against `wp_validate_redirect()`, loaded the es_ES `.mo` through
  `load_textdomain()` and verified singular + plural (n=1, n=5) translations via the real
  `__()`/`_n()` stack, and confirmed the multisite activation structure —
  **12/12 checks passed**. Confidence flag §5.3 is resolved for the fix's logic (the
  PHPUnit files remain to be run in a suite-equipped environment).
- **Pro version bumped to 1.1.0** (`7cd66ad`) — header, `NLHP_VERSION`, translation-file
  metadata, recompiled `.mo`, AGENTS.md. Resolves the open product decision by versioning
  the post-1.0.0 feature growth; `NLHP_DB_VERSION` unchanged.
- **wp-cli could not be installed** to re-run the real `make-pot` (downloading the phar was
  blocked by session policy). Confidence flag §5.2 stands: re-run `wp i18n make-pot` when
  wp-cli is available; content parity was already verified by extraction diff.
- **REST API and JS-i18n stances confirmed as final**: admin-ajax stays (no current
  external consumer); the `wp_localize_script` + numbered-placeholder pattern stays
  (sanctioned, reorder-safe, and `wp.i18n` JSON generation needs wp-cli).
- Both repos pushed to GitHub by owner request (core → `Link_Health_Plugin`, Pro → new
  private repo) — see the repo hosting note in the project vault.
