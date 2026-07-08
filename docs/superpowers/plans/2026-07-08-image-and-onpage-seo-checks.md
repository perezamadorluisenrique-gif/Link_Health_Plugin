# Image Health + On-Page SEO Checks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 7 free checks to Native Link Health's SEO audit module — 3 image-health checks (missing alt text, dimension mismatch, legacy format) and 4 on-page SEO checks (title length, meta description length, heading hierarchy, keyword density) — with no Pro gating.

**Architecture:** All 7 checks are new public `audit_*` methods on the existing `NLH_SEO_Audit` class (`includes/class-nlh-seo-audit.php`), following the exact pattern of its 5 existing checks (loop `get_public_posts()`, parse with `WP_HTML_Tag_Processor`, return via the shared `result()` helper). Pure, WP-independent logic (length classification, focus-keyword extraction, heading-skip detection, legacy-format detection) is factored into private helper methods so it can be unit-tested with the project's existing dependency-free reflection harness (`tests/test-scanner-helpers.php` is the template; this plan adds `tests/test-seo-audit-helpers.php`). The results wire into the existing `ajax_run_seo_audit()` AJAX handler and the existing generic `renderSeoResults()` JS renderer — no new UI surface.

**Tech Stack:** PHP 8.0+, WordPress `WP_HTML_Tag_Processor`, vanilla JS (no build step), the project's standalone PHP test harness (no PHPUnit).

## Global Constraints

- Plugin root: `C:\xampp\htdocs\wpprueba\wp-content\plugins\native-link-health` (the free plugin — `native-link-health-pro` is NOT touched by this plan).
- Classes: `class-nlh-{name}.php`, class names `NLH_*`, methods `snake_case`. All new code lives in the existing `NLH_SEO_Audit` class — no new files for the audit logic itself.
- HTML parsing: exclusively `WP_HTML_Tag_Processor` (already a hard project convention).
- No Pro gating: none of the 7 new checks call `NLH_Pro::can()` or render an upsell — free from day one, exactly like the 5 existing SEO audit checks.
- No HTTP fetches for image checks: dimension/format checks apply only to images that resolve to a local media-library attachment (`attachment_url_to_postid()`); external/CDN images are skipped, never downloaded.
- `alt=""` is NOT flagged (only a fully absent `alt` attribute is) — avoids false positives on intentionally decorative images.
- No "no H1 in content" flag — themes typically render the post title as H1 outside `post_content`; flagging it would be a false positive on most WP sites.
- Keyword density check: no user-facing "focus keyword" field — auto-detect from `post_title`. Only flags 2 states: keyword absent from body, or density above threshold. No blanket per-post density listing.
- Meta description check: uses `get_the_excerpt()` (manual or WP-auto-generated) — WordPress core renders no `<meta name="description">` tag without an SEO plugin, so all user-facing copy for this check must describe it as measuring the **excerpt**, not claim a literal meta tag exists.
- All new thresholds are filterable, matching the project's existing convention (e.g. `nlh_dilution_threshold`, `nlh_redirect_chain_scan_limit`): `nlh_seo_title_min_length` (30), `nlh_seo_title_max_length` (60), `nlh_seo_meta_description_min_length` (50), `nlh_seo_meta_description_max_length` (160), `nlh_seo_keyword_density_max` (3.0).
- Text domain for all new strings: `native-link-health`. Every user-facing string uses `__()`/`sprintf()` with `/* translators: */` comments where there are placeholders (matches every existing string in the file).
- No WP integration test harness exists yet in this repo (see `tests/README.md`) — only pure-logic methods get automated tests. WP-dependent orchestration methods (the `audit_*` methods themselves) are verified manually via `php -l` + a browser check, exactly as the 5 pre-existing checks are today (they have no automated tests either).

---

## File Structure

- **Modify** `includes/class-nlh-seo-audit.php` — add 7 public `audit_*` methods + 8 private helpers (4 pure, 4 WP-dependent glue) + 1 private property (stopwords).
- **Create** `tests/test-seo-audit-helpers.php` — standalone reflection-based tests for the 4 pure private helpers, built up incrementally across Tasks 1–4.
- **Modify** `admin/class-nlh-admin.php` — `ajax_run_seo_audit()` gains 7 result keys; `wp_localize_script()` gains 7 i18n title strings.
- **Modify** `admin/js/nlh-admin.js` — `renderSeoResults()`'s `titles` map gains 7 keys.
- **Modify** `AGENTS.md` — update the "SEO audit: 5 checks" line.
- **Modify** `readme.txt`, `native-link-health.php` — version bump to 1.4.0 + changelog entry.
- **Modify** `languages/native-link-health.pot`, `languages/native-link-health-es_ES.po`, `languages/native-link-health-es_ES.mo` — new strings + Spanish translations.

No changes to `admin/partials/nlh-seo-dashboard.php` (already generic) or `native-link-health-pro` (not a Pro feature).

---

## Task 1: Pure helper — `classify_length()` + test harness scaffold

**Files:**
- Modify: `includes/class-nlh-seo-audit.php` (add private method, insert before `get_public_posts()` at current line 344)
- Create: `tests/test-seo-audit-helpers.php`

**Interfaces:**
- Produces: `private function classify_length( int $length, int $min, int $max ): string` — returns `'missing'` (length is 0), `'short'` (0 < length < min), `'long'` (length > max), or `'ok'`. Reused by Task 6 for both the title-length and meta-description-length checks.

- [ ] **Step 1: Write the failing test file**

Create `tests/test-seo-audit-helpers.php`:

```php
<?php
/**
 * Standalone unit tests for the pure helpers behind the SEO audit checks.
 * These run without a full WordPress bootstrap: only the few i18n stubs the
 * tested methods touch are defined here.
 *
 * Run from the plugin root with the bundled PHP:
 *   php tests/test-seo-audit-helpers.php
 *
 * Exit code is non-zero if any assertion fails, so this is CI-friendly.
 *
 * @package NativeLinkHealth
 */

// The class file guards on ABSPATH; satisfy it without loading WordPress.
define( 'ABSPATH', __DIR__ . '/' );

// Minimal i18n stub — the only WP function the tested methods touch.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-nlh-seo-audit.php';

$failures = 0;
$tests    = 0;

/**
 * Tiny assertion helper.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Label.
 * @return void
 */
function nlh_assert( $expected, $actual, string $message ): void {
	global $failures, $tests;
	++$tests;

	if ( $expected === $actual ) {
		echo "  PASS: {$message}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$message}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Invokes a private/protected method via reflection.
 *
 * @param object $object Instance.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function nlh_call_private( object $object, string $method, array $args ) {
	$ref = new ReflectionMethod( $object, $method );
	$ref->setAccessible( true );

	return $ref->invokeArgs( $object, $args );
}

$audit = new NLH_SEO_Audit();

echo "classify_length():\n";
nlh_assert( 'missing', nlh_call_private( $audit, 'classify_length', array( 0, 30, 60 ) ), 'zero length -> missing' );
nlh_assert( 'short', nlh_call_private( $audit, 'classify_length', array( 10, 30, 60 ) ), 'below min -> short' );
nlh_assert( 'ok', nlh_call_private( $audit, 'classify_length', array( 45, 30, 60 ) ), 'within range -> ok' );
nlh_assert( 'ok', nlh_call_private( $audit, 'classify_length', array( 30, 30, 60 ) ), 'exactly min -> ok' );
nlh_assert( 'ok', nlh_call_private( $audit, 'classify_length', array( 60, 30, 60 ) ), 'exactly max -> ok' );
nlh_assert( 'long', nlh_call_private( $audit, 'classify_length', array( 61, 30, 60 ) ), 'above max -> long' );

echo "\n{$tests} tests, {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-seo-audit-helpers.php`
Expected: PHP fatal error — `classify_length` does not exist (the require succeeds since the class itself has no syntax error, but `ReflectionMethod` throws because the method isn't defined yet).

- [ ] **Step 3: Implement `classify_length()`**

In `includes/class-nlh-seo-audit.php`, insert this new private method directly before the existing `get_public_posts()` method (currently starting at line 344, docblock `/**\n\t * Returns public posts/pages.`):

```php
	/**
	 * Classifies a measured length against a recommended min/max range.
	 *
	 * @param int $length Measured length.
	 * @param int $min    Recommended minimum.
	 * @param int $max    Recommended maximum.
	 * @return string 'missing', 'short', 'long', or 'ok'.
	 */
	private function classify_length( int $length, int $min, int $max ): string {
		if ( 0 === $length ) {
			return 'missing';
		}

		if ( $length < $min ) {
			return 'short';
		}

		if ( $length > $max ) {
			return 'long';
		}

		return 'ok';
	}

```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `6 tests, 0 failures.` and exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php tests/test-seo-audit-helpers.php
git commit -m "Add classify_length() helper and SEO-audit test harness"
```

---

## Task 2: Pure helper — `extract_focus_keyword()`

**Files:**
- Modify: `includes/class-nlh-seo-audit.php` (add private property + private method)
- Modify: `tests/test-seo-audit-helpers.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `private array $stopwords` (EN+ES stopword list) and `private function extract_focus_keyword( string $title ): string` — returns the longest word (≥4 chars) in the title that isn't a stopword, lowercased, or `''` if none qualify. Consumed by Task 8's `audit_keyword_density()`.

- [ ] **Step 1: Write the failing tests**

In `tests/test-seo-audit-helpers.php`, replace the final two lines:

```php
echo "\n{$tests} tests, {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );
```

with:

```php
echo "\nextract_focus_keyword():\n";
nlh_assert( 'gardening', nlh_call_private( $audit, 'extract_focus_keyword', array( 'The Best Gardening Guide For Beginners' ) ), 'picks longest non-stopword word, stopwords excluded' );
nlh_assert( 'jardineria', nlh_call_private( $audit, 'extract_focus_keyword', array( 'Como empezar con la jardineria' ) ), 'Spanish stopwords excluded' );
nlh_assert( '', nlh_call_private( $audit, 'extract_focus_keyword', array( 'The Guide' ) ), 'all words are stopwords -> empty string' );
nlh_assert( '', nlh_call_private( $audit, 'extract_focus_keyword', array( '' ) ), 'empty title -> empty string' );
nlh_assert( '', nlh_call_private( $audit, 'extract_focus_keyword', array( 'php' ) ), 'word under 4 chars is excluded -> empty string' );

echo "\n{$tests} tests, {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-seo-audit-helpers.php`
Expected: fatal error — `extract_focus_keyword` does not exist.

- [ ] **Step 3: Implement the stopword list and `extract_focus_keyword()`**

In `includes/class-nlh-seo-audit.php`, add this private property as the first member of the class, directly after the `class NLH_SEO_Audit {` opening line:

```php
	/**
	 * Common stop words (EN + ES) excluded from focus-keyword detection.
	 *
	 * Deliberately duplicated from the similar list in
	 * class-nlh-link-recommendations.php rather than shared, so the two
	 * classes (link relevance vs. keyword density) can evolve independently.
	 *
	 * @var string[]
	 */
	private array $stopwords = array(
		'the',
		'and',
		'for',
		'with',
		'that',
		'this',
		'from',
		'your',
		'you',
		'are',
		'was',
		'has',
		'have',
		'will',
		'can',
		'how',
		'what',
		'why',
		'who',
		'when',
		'where',
		'about',
		'into',
		'over',
		'best',
		'guide',
		'los',
		'las',
		'una',
		'unos',
		'unas',
		'del',
		'con',
		'por',
		'para',
		'que',
		'como',
		'mas',
		'pero',
		'sus',
		'este',
		'esta',
		'esto',
		'son',
		'fue',
		'han',
		'hay',
		'sobre',
		'entre',
		'cuando',
		'donde',
	);

```

Then add this private method directly after `classify_length()` (added in Task 1):

```php
	/**
	 * Auto-detects a "focus keyword" from a post title: the longest word of
	 * at least 4 characters that is not a common stop word. There is no
	 * user-facing focus-keyword field in WordPress core, so this is a
	 * heuristic, not a configured value.
	 *
	 * @param string $title Post title.
	 * @return string Lowercased keyword, or '' if no candidate qualifies.
	 */
	private function extract_focus_keyword( string $title ): string {
		$stop = array_fill_keys( $this->stopwords, true );

		preg_match_all( '/[\p{L}\p{N}]{4,}/u', mb_strtolower( $title ), $matches );
		$candidates = array_values( array_diff( $matches[0], array_keys( $stop ) ) );

		if ( empty( $candidates ) ) {
			return '';
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);

		return $candidates[0];
	}

```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `11 tests, 0 failures.` and exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php tests/test-seo-audit-helpers.php
git commit -m "Add extract_focus_keyword() helper for the keyword density check"
```

---

## Task 3: Pure helper — `find_heading_hierarchy_issues()`

**Files:**
- Modify: `includes/class-nlh-seo-audit.php`
- Modify: `tests/test-seo-audit-helpers.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `private function find_heading_hierarchy_issues( array $levels ): array` — takes an ordered list of heading levels (ints 1-6) found in content order, returns a list of issue arrays: `array( 'type' => 'multiple_h1', 'count' => int )` and/or `array( 'type' => 'skipped_level', 'from' => int, 'to' => int )`. Consumed by Task 7's `audit_heading_hierarchy()`.

- [ ] **Step 1: Write the failing tests**

In `tests/test-seo-audit-helpers.php`, replace the final two lines with:

```php
echo "\nfind_heading_hierarchy_issues():\n";
nlh_assert( array(), nlh_call_private( $audit, 'find_heading_hierarchy_issues', array( array( 2, 3, 2, 3 ) ) ), 'clean hierarchy -> no issues' );
nlh_assert(
	array( array( 'type' => 'multiple_h1', 'count' => 2 ) ),
	nlh_call_private( $audit, 'find_heading_hierarchy_issues', array( array( 1, 2, 1, 2 ) ) ),
	'two H1s -> multiple_h1 issue'
);
nlh_assert(
	array( array( 'type' => 'skipped_level', 'from' => 2, 'to' => 4 ) ),
	nlh_call_private( $audit, 'find_heading_hierarchy_issues', array( array( 2, 4 ) ) ),
	'H2 directly to H4 -> skipped_level issue'
);
nlh_assert( array(), nlh_call_private( $audit, 'find_heading_hierarchy_issues', array( array( 4, 2, 3 ) ) ), 'ascending back out (H4 to H2) is not a skip' );
nlh_assert( array(), nlh_call_private( $audit, 'find_heading_hierarchy_issues', array( array() ) ), 'no headings -> no issues' );
nlh_assert( array(), nlh_call_private( $audit, 'find_heading_hierarchy_issues', array( array( 1 ) ) ), 'single H1 -> no issues' );

echo "\n{$tests} tests, {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-seo-audit-helpers.php`
Expected: fatal error — `find_heading_hierarchy_issues` does not exist.

- [ ] **Step 3: Implement `find_heading_hierarchy_issues()`**

In `includes/class-nlh-seo-audit.php`, add this private method directly after `extract_focus_keyword()` (added in Task 2):

```php
	/**
	 * Flags multiple-H1 and skipped-level issues in an ordered list of
	 * heading levels. Ascending back out of a nested section (e.g. H4 then
	 * H2) is normal document structure and is never flagged — only a
	 * forward skip while descending (e.g. H2 directly to H4) is.
	 *
	 * @param int[] $levels Heading levels (1-6) in document order.
	 * @return array<int,array<string,int|string>>
	 */
	private function find_heading_hierarchy_issues( array $levels ): array {
		$issues = array();

		$h1_count = count(
			array_filter(
				$levels,
				static function ( $level ) {
					return 1 === $level;
				}
			)
		);

		if ( $h1_count > 1 ) {
			$issues[] = array(
				'type'  => 'multiple_h1',
				'count' => $h1_count,
			);
		}

		$previous = null;
		foreach ( $levels as $level ) {
			if ( null !== $previous && $level > $previous + 1 ) {
				$issues[] = array(
					'type' => 'skipped_level',
					'from' => $previous,
					'to'   => $level,
				);
			}
			$previous = $level;
		}

		return $issues;
	}

```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `17 tests, 0 failures.` and exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php tests/test-seo-audit-helpers.php
git commit -m "Add find_heading_hierarchy_issues() helper for the heading check"
```

---

## Task 4: Pure helper — `is_legacy_image_format()`

**Files:**
- Modify: `includes/class-nlh-seo-audit.php`
- Modify: `tests/test-seo-audit-helpers.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `private function is_legacy_image_format( string $src ): bool` — `true` for `.jpg`/`.jpeg`/`.png` extensions (case-insensitive, query string ignored), `false` otherwise (including `.gif`, which is often intentionally animated and is deliberately excluded). Consumed by Task 5's `audit_legacy_image_format()`.

- [ ] **Step 1: Write the failing tests**

In `tests/test-seo-audit-helpers.php`, replace the final two lines with:

```php
echo "\nis_legacy_image_format():\n";
nlh_assert( true, nlh_call_private( $audit, 'is_legacy_image_format', array( 'https://example.com/wp-content/uploads/2026/07/photo.jpg' ) ), '.jpg -> legacy' );
nlh_assert( true, nlh_call_private( $audit, 'is_legacy_image_format', array( 'https://example.com/uploads/logo.PNG' ) ), '.PNG (uppercase) -> legacy' );
nlh_assert( true, nlh_call_private( $audit, 'is_legacy_image_format', array( '/uploads/photo.jpeg?ver=2' ) ), '.jpeg with query string -> legacy' );
nlh_assert( false, nlh_call_private( $audit, 'is_legacy_image_format', array( '/uploads/animation.gif' ) ), '.gif -> not legacy (often intentional animation)' );
nlh_assert( false, nlh_call_private( $audit, 'is_legacy_image_format', array( '/uploads/photo.webp' ) ), '.webp -> not legacy' );
nlh_assert( false, nlh_call_private( $audit, 'is_legacy_image_format', array( '/uploads/photo' ) ), 'no extension -> not legacy' );

echo "\n{$tests} tests, {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-seo-audit-helpers.php`
Expected: fatal error — `is_legacy_image_format` does not exist.

- [ ] **Step 3: Implement `is_legacy_image_format()`**

In `includes/class-nlh-seo-audit.php`, add this private method directly after `find_heading_hierarchy_issues()` (added in Task 3). Note this uses plain PHP `parse_url()`, not `wp_parse_url()`, so it stays testable in the dependency-free harness:

```php
	/**
	 * Checks whether an image URL has a legacy raster extension (JPG/PNG).
	 * GIF is deliberately excluded — it is frequently used intentionally for
	 * animation, not as a static-photo format choice.
	 *
	 * Uses plain parse_url() rather than wp_parse_url() so this stays a pure
	 * function testable without WordPress loaded.
	 *
	 * @param string $src Image URL.
	 * @return bool
	 */
	private function is_legacy_image_format( string $src ): bool {
		$path = (string) parse_url( $src, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
	}

```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.` and exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php tests/test-seo-audit-helpers.php
git commit -m "Add is_legacy_image_format() helper for the image format check"
```

---

## Task 5: Image health checks

**Files:**
- Modify: `includes/class-nlh-seo-audit.php`

**Interfaces:**
- Consumes: `is_legacy_image_format()` (Task 4), `get_public_posts()` (existing), `format_post_item()` (existing), `result()` (existing).
- Produces: `public function audit_missing_alt_text(): array`, `public function audit_image_dimension_mismatch(): array`, `public function audit_legacy_image_format(): array` — each returns the same `{status, count, items, message}` shape as the 5 existing `audit_*` methods. Also produces two new private helpers: `get_image_tags( string $content ): array` and `resolve_local_attachment_file( string $src ): string`. Consumed by Task 9 (AJAX wiring).

No automated test for this task — these methods call `get_posts()`, `WP_HTML_Tag_Processor`, `attachment_url_to_postid()`, `get_attached_file()`, and `getimagesize()`, none of which run outside a full WordPress bootstrap. This repo has no WP integration test harness yet (see `tests/README.md`, "Future: full integration tests") — the 5 pre-existing `audit_*` methods have no automated tests either, for the same reason. Verification is a PHP lint pass plus a manual browser check (Step 4 below), matching how the existing checks are verified today.

- [ ] **Step 1: Add the two private helpers**

In `includes/class-nlh-seo-audit.php`, add these two private methods directly after `is_legacy_image_format()` (added in Task 4):

```php
	/**
	 * Extracts IMG tags from post content with their alt/src/width/height
	 * attributes. One WP_HTML_Tag_Processor pass, shared by all three image
	 * health checks.
	 *
	 * @param string $content HTML content.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_image_tags( string $content ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $content );
		$images    = array();

		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			$images[] = array(
				'src'    => $processor->get_attribute( 'src' ),
				'alt'    => $processor->get_attribute( 'alt' ),
				'width'  => $processor->get_attribute( 'width' ),
				'height' => $processor->get_attribute( 'height' ),
			);
		}

		return $images;
	}

	/**
	 * Resolves an image URL to a local media-library file path, if it is
	 * one. External/CDN images return ''  — no HTTP fetch is ever made to
	 * verify them, matching the "no cloud" design of the plugin.
	 *
	 * @param string $src Image URL.
	 * @return string Absolute file path, or '' if not a local attachment.
	 */
	private function resolve_local_attachment_file( string $src ): string {
		$attachment_id = attachment_url_to_postid( $src );

		if ( ! $attachment_id ) {
			return '';
		}

		$file = get_attached_file( $attachment_id );

		return ( is_string( $file ) && file_exists( $file ) ) ? $file : '';
	}

```

- [ ] **Step 2: Add the three public audit methods**

In `includes/class-nlh-seo-audit.php`, add these three public methods directly after the existing `audit_redundant_links()` method (currently ends at line 337, right before the `get_public_posts()` docblock):

```php
	/**
	 * Finds IMG tags missing an alt attribute entirely. alt="" is treated as
	 * an intentional decorative marker (valid per WCAG) and is NOT flagged —
	 * only a fully absent alt attribute is.
	 *
	 * @return array
	 */
	public function audit_missing_alt_text(): array {
		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			foreach ( $this->get_image_tags( $post->post_content ) as $image ) {
				if ( null === $image['alt'] ) {
					$items[] = $this->format_post_item( (int) $post->ID, (string) ( $image['src'] ?? '' ) );
				}
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'All images have an alt attribute.', 'native-link-health' )
				: __( 'Images without an alt attribute were found. Add descriptive alt text, or alt="" for purely decorative images.', 'native-link-health' )
		);
	}

	/**
	 * Finds images whose declared width/height HTML attributes do not match
	 * their real file dimensions. Only checked for images in this site's own
	 * media library — external images are skipped (no HTTP fetch).
	 *
	 * @return array
	 */
	public function audit_image_dimension_mismatch(): array {
		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			foreach ( $this->get_image_tags( $post->post_content ) as $image ) {
				if ( ! is_string( $image['src'] ) || '' === $image['src'] ) {
					continue;
				}

				if ( ! is_numeric( $image['width'] ) || ! is_numeric( $image['height'] ) ) {
					continue;
				}

				$file = $this->resolve_local_attachment_file( $image['src'] );
				if ( '' === $file ) {
					continue;
				}

				$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( ! is_array( $size ) ) {
					continue;
				}

				list( $real_width, $real_height ) = $size;

				if ( (int) $image['width'] !== $real_width || (int) $image['height'] !== $real_height ) {
					$items[] = $this->format_post_item(
						(int) $post->ID,
						sprintf(
							/* translators: 1: image URL, 2: declared width, 3: declared height, 4: real width, 5: real height. */
							__( '%1$s declares %2$dx%3$d but the file is %4$dx%5$d.', 'native-link-health' ),
							$image['src'],
							(int) $image['width'],
							(int) $image['height'],
							$real_width,
							$real_height
						)
					);
				}
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'No image dimension mismatches found.', 'native-link-health' )
				: __( 'Images with declared width/height that do not match the real file were found.', 'native-link-health' )
		);
	}

	/**
	 * Finds legacy-format (JPG/PNG) images in this site's own media library.
	 * GIF is excluded (often intentional animation). External images are
	 * skipped — no HTTP fetch is made to inspect them.
	 *
	 * @return array
	 */
	public function audit_legacy_image_format(): array {
		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			foreach ( $this->get_image_tags( $post->post_content ) as $image ) {
				if ( ! is_string( $image['src'] ) || '' === $image['src'] ) {
					continue;
				}

				if ( '' === $this->resolve_local_attachment_file( $image['src'] ) ) {
					continue;
				}

				if ( $this->is_legacy_image_format( $image['src'] ) ) {
					$items[] = $this->format_post_item( (int) $post->ID, $image['src'] );
				}
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'No legacy image formats found in your own media.', 'native-link-health' )
				: __( 'Images in a legacy format (JPG/PNG) were found. Consider converting to WebP or AVIF.', 'native-link-health' )
		);
	}

```

- [ ] **Step 3: Lint check**

Run: `php -l includes/class-nlh-seo-audit.php`
Expected: `No syntax errors detected in includes/class-nlh-seo-audit.php`

- [ ] **Step 4: Run the existing pure-logic test suite to confirm nothing broke**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.` (unchanged from Task 4 — these new methods aren't unit tested, but this confirms the class still loads and the tested helpers still work).

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php
git commit -m "Add image health checks: missing alt text, dimension mismatch, legacy format"
```

---

## Task 6: On-page checks — title length and meta description length

**Files:**
- Modify: `includes/class-nlh-seo-audit.php`

**Interfaces:**
- Consumes: `classify_length()` (Task 1), `get_public_posts()` (existing), `format_post_item()` (existing), `result()` (existing).
- Produces: `private function mb_len( string $text ): int`, `private function describe_length_issue( string $state, int $length, int $min, int $max, string $field ): string`, `public function audit_title_length(): array`, `public function audit_meta_description(): array`. Consumed by Task 9 (AJAX wiring).

- [ ] **Step 1: Add the two private helpers**

In `includes/class-nlh-seo-audit.php`, add these two private methods directly after `resolve_local_attachment_file()` (added in Task 5):

```php
	/**
	 * Multi-byte-safe string length, falling back to strlen() if the mbstring
	 * extension is unavailable.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private function mb_len( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Builds the detail message for a title/meta-description length issue.
	 *
	 * @param string $state  Result of classify_length(): 'missing', 'short', or 'long'.
	 * @param int    $length Measured length.
	 * @param int    $min    Recommended minimum.
	 * @param int    $max    Recommended maximum.
	 * @param string $field  'title' or 'excerpt' — selects the "missing" copy.
	 * @return string
	 */
	private function describe_length_issue( string $state, int $length, int $min, int $max, string $field ): string {
		if ( 'missing' === $state ) {
			return 'title' === $field
				? __( 'Title is empty.', 'native-link-health' )
				: __( 'Excerpt (used as the meta description by most themes) is empty.', 'native-link-health' );
		}

		if ( 'short' === $state ) {
			return sprintf(
				/* translators: 1: current length, 2: minimum recommended length. */
				__( '%1$d characters — shorter than the recommended minimum of %2$d.', 'native-link-health' ),
				$length,
				$min
			);
		}

		return sprintf(
			/* translators: 1: current length, 2: maximum recommended length. */
			__( '%1$d characters — longer than the recommended maximum of %2$d.', 'native-link-health' ),
			$length,
			$max
		);
	}

```

- [ ] **Step 2: Add the two public audit methods**

In `includes/class-nlh-seo-audit.php`, add these two public methods directly after `audit_legacy_image_format()` (added in Task 5):

```php
	/**
	 * Flags post titles outside the recommended search-snippet length range.
	 *
	 * @return array
	 */
	public function audit_title_length(): array {
		/**
		 * Filters the minimum recommended title length, in characters.
		 *
		 * @since 1.4.0
		 * @param int $min Minimum length.
		 */
		$min = max( 1, (int) apply_filters( 'nlh_seo_title_min_length', 30 ) );

		/**
		 * Filters the maximum recommended title length, in characters.
		 *
		 * @since 1.4.0
		 * @param int $max Maximum length.
		 */
		$max = max( $min, (int) apply_filters( 'nlh_seo_title_max_length', 60 ) );

		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			$length = $this->mb_len( $post->post_title );
			$state  = $this->classify_length( $length, $min, $max );

			if ( 'ok' === $state ) {
				continue;
			}

			$items[] = $this->format_post_item( (int) $post->ID, $this->describe_length_issue( $state, $length, $min, $max, 'title' ) );
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'All titles are within the recommended length.', 'native-link-health' )
				: __( 'Titles outside the recommended length range were found.', 'native-link-health' )
		);
	}

	/**
	 * Flags excerpts (used by most themes/plugins as the meta description)
	 * outside the recommended search-snippet length range. WordPress core
	 * renders no <meta name="description"> tag without an SEO plugin, so
	 * this measures the excerpt rather than claiming a literal meta tag.
	 *
	 * @return array
	 */
	public function audit_meta_description(): array {
		/**
		 * Filters the minimum recommended excerpt/meta-description length.
		 *
		 * @since 1.4.0
		 * @param int $min Minimum length, in characters.
		 */
		$min = max( 1, (int) apply_filters( 'nlh_seo_meta_description_min_length', 50 ) );

		/**
		 * Filters the maximum recommended excerpt/meta-description length.
		 *
		 * @since 1.4.0
		 * @param int $max Maximum length, in characters.
		 */
		$max = max( $min, (int) apply_filters( 'nlh_seo_meta_description_max_length', 160 ) );

		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
			$length  = $this->mb_len( $excerpt );
			$state   = $this->classify_length( $length, $min, $max );

			if ( 'ok' === $state ) {
				continue;
			}

			$items[] = $this->format_post_item( (int) $post->ID, $this->describe_length_issue( $state, $length, $min, $max, 'excerpt' ) );
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'All excerpts (used as meta descriptions by most themes) are within the recommended length.', 'native-link-health' )
				: __( 'Excerpts outside the recommended meta-description length range were found.', 'native-link-health' )
		);
	}

```

- [ ] **Step 3: Lint check**

Run: `php -l includes/class-nlh-seo-audit.php`
Expected: `No syntax errors detected in includes/class-nlh-seo-audit.php`

- [ ] **Step 4: Run the pure-logic test suite to confirm nothing broke**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.`

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php
git commit -m "Add title length and meta description length checks"
```

---

## Task 7: On-page check — heading hierarchy

**Files:**
- Modify: `includes/class-nlh-seo-audit.php`

**Interfaces:**
- Consumes: `find_heading_hierarchy_issues()` (Task 3), `get_public_posts()` (existing), `format_post_item()` (existing), `result()` (existing).
- Produces: `private function get_heading_levels( string $content ): array`, `private function describe_heading_issue( array $issue ): string`, `public function audit_heading_hierarchy(): array`. Consumed by Task 9 (AJAX wiring).

- [ ] **Step 1: Add the private helpers**

In `includes/class-nlh-seo-audit.php`, add these two private methods directly after `describe_length_issue()` (added in Task 6):

```php
	/**
	 * Extracts H1-H6 heading levels from post content in document order.
	 *
	 * @param string $content HTML content.
	 * @return int[]
	 */
	private function get_heading_levels( string $content ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $content );
		$levels    = array();

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( is_string( $tag ) && 1 === preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
				$levels[] = (int) $matches[1];
			}
		}

		return $levels;
	}

	/**
	 * Builds the detail message for one heading-hierarchy issue.
	 *
	 * @param array $issue Issue array from find_heading_hierarchy_issues().
	 * @return string
	 */
	private function describe_heading_issue( array $issue ): string {
		if ( 'multiple_h1' === $issue['type'] ) {
			return sprintf(
				/* translators: %d: number of H1 headings found. */
				__( '%d H1 headings found in the content — there should be only one.', 'native-link-health' ),
				(int) $issue['count']
			);
		}

		return sprintf(
			/* translators: 1: heading level before the skip, 2: heading level after the skip. */
			__( 'Heading level skipped: H%1$d is followed directly by H%2$d.', 'native-link-health' ),
			(int) $issue['from'],
			(int) $issue['to']
		);
	}

```

- [ ] **Step 2: Add the public audit method**

In `includes/class-nlh-seo-audit.php`, add this public method directly after `audit_meta_description()` (added in Task 6):

```php
	/**
	 * Flags multiple-H1 and skipped-heading-level issues in post content.
	 * Does NOT flag "no H1 in content" — themes typically render the post
	 * title as the page's H1 outside post_content, so a missing H1 inside
	 * the content body is normal on most WordPress sites.
	 *
	 * @return array
	 */
	public function audit_heading_hierarchy(): array {
		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			$issues = $this->find_heading_hierarchy_issues( $this->get_heading_levels( $post->post_content ) );

			foreach ( $issues as $issue ) {
				$items[] = $this->format_post_item( (int) $post->ID, $this->describe_heading_issue( $issue ) );
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'No heading hierarchy issues found.', 'native-link-health' )
				: __( 'Heading structure issues (multiple H1s or skipped levels) were found.', 'native-link-health' )
		);
	}

```

- [ ] **Step 3: Lint check**

Run: `php -l includes/class-nlh-seo-audit.php`
Expected: `No syntax errors detected in includes/class-nlh-seo-audit.php`

- [ ] **Step 4: Run the pure-logic test suite to confirm nothing broke**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.`

- [ ] **Step 5: Commit**

```bash
git add includes/class-nlh-seo-audit.php
git commit -m "Add heading hierarchy check"
```

---

## Task 8: On-page check — keyword density

**Files:**
- Modify: `includes/class-nlh-seo-audit.php`

**Interfaces:**
- Consumes: `extract_focus_keyword()` (Task 2), `get_public_posts()` (existing), `format_post_item()` (existing), `result()` (existing).
- Produces: `public function audit_keyword_density(): array`. Consumed by Task 9 (AJAX wiring).

- [ ] **Step 1: Add the public audit method**

In `includes/class-nlh-seo-audit.php`, add this public method directly after `audit_heading_hierarchy()` (added in Task 7):

```php
	/**
	 * Flags posts whose auto-detected title keyword is either absent from
	 * the body content, or overused within it. WordPress core has no
	 * user-facing "focus keyword" field, so the keyword is heuristically
	 * detected from the title (see extract_focus_keyword()). Only these two
	 * actionable states are flagged — informational density values for
	 * every post are not listed, to keep the check noise-free.
	 *
	 * @return array
	 */
	public function audit_keyword_density(): array {
		/**
		 * Filters the keyword density percentage above which a post is
		 * flagged as possible keyword stuffing.
		 *
		 * @since 1.4.0
		 * @param float $max_density Maximum density percentage.
		 */
		$max_density = (float) apply_filters( 'nlh_seo_keyword_density_max', 3.0 );

		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			$keyword = $this->extract_focus_keyword( $post->post_title );

			if ( '' === $keyword ) {
				continue;
			}

			$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

			$word_count = is_array( $words ) ? count( $words ) : 0;

			// Too short to measure meaningfully — skip rather than risk a
			// noisy density figure on a stub page.
			if ( $word_count < 20 ) {
				continue;
			}

			$occurrences = preg_match_all( '/\b' . preg_quote( $keyword, '/' ) . '\b/iu', $text );
			$density     = ( $occurrences / $word_count ) * 100;

			if ( 0 === $occurrences ) {
				$items[] = $this->format_post_item(
					(int) $post->ID,
					sprintf(
						/* translators: %s: focus keyword auto-detected from the title. */
						__( 'Title keyword "%s" does not appear anywhere in the content.', 'native-link-health' ),
						$keyword
					)
				);
			} elseif ( $density > $max_density ) {
				$items[] = $this->format_post_item(
					(int) $post->ID,
					sprintf(
						/* translators: 1: focus keyword, 2: occurrence count, 3: density percentage. */
						__( 'Keyword "%1$s" appears %2$d times (%3$s%% density) — consider reducing repetition.', 'native-link-health' ),
						$keyword,
						(int) $occurrences,
						number_format_i18n( $density, 1 )
					)
				);
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items )
				? __( 'No keyword density issues found.', 'native-link-health' )
				: __( 'Titles whose detected keyword is missing from the content, or overused within it, were found.', 'native-link-health' )
		);
	}

```

- [ ] **Step 2: Lint check**

Run: `php -l includes/class-nlh-seo-audit.php`
Expected: `No syntax errors detected in includes/class-nlh-seo-audit.php`

- [ ] **Step 3: Run the pure-logic test suite to confirm nothing broke**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.`

- [ ] **Step 4: Commit**

```bash
git add includes/class-nlh-seo-audit.php
git commit -m "Add keyword density check"
```

---

## Task 9: Wire the 7 checks into the AJAX handler and JS renderer

**Files:**
- Modify: `admin/class-nlh-admin.php:1140-1160` (the `ajax_run_seo_audit()` method) and `admin/class-nlh-admin.php:305-309` (the `i18n` array inside `wp_localize_script()`)
- Modify: `admin/js/nlh-admin.js:520-527` (the `titles` map inside `renderSeoResults()`)

**Interfaces:**
- Consumes: the 7 `audit_*` method names from Tasks 5–8 (`audit_missing_alt_text`, `audit_image_dimension_mismatch`, `audit_legacy_image_format`, `audit_title_length`, `audit_meta_description`, `audit_heading_hierarchy`, `audit_keyword_density`).
- Produces: 7 new keys in the `nlh_seo_audit_results` transient / AJAX response: `missing_alt_text`, `image_dimension_mismatch`, `legacy_image_format`, `title_length`, `meta_description`, `heading_hierarchy`, `keyword_density`.

- [ ] **Step 1: Add the 7 result keys to `ajax_run_seo_audit()`**

In `admin/class-nlh-admin.php`, find this block (currently lines 1145-1152):

```php
				$audit   = new NLH_SEO_Audit();
				$results = array(
					'orphan_pages'       => $audit->audit_orphan_pages(),
					'redirect_chains'    => $audit->audit_redirect_chains(),
					'mixed_content'      => $audit->audit_mixed_content(),
					'invalid_canonicals' => $audit->audit_invalid_canonicals(),
					'redundant_links'    => $audit->audit_redundant_links(),
				);
```

Replace it with:

```php
				$audit   = new NLH_SEO_Audit();
				$results = array(
					'orphan_pages'             => $audit->audit_orphan_pages(),
					'redirect_chains'          => $audit->audit_redirect_chains(),
					'mixed_content'            => $audit->audit_mixed_content(),
					'invalid_canonicals'       => $audit->audit_invalid_canonicals(),
					'redundant_links'          => $audit->audit_redundant_links(),
					'missing_alt_text'         => $audit->audit_missing_alt_text(),
					'image_dimension_mismatch' => $audit->audit_image_dimension_mismatch(),
					'legacy_image_format'      => $audit->audit_legacy_image_format(),
					'title_length'             => $audit->audit_title_length(),
					'meta_description'         => $audit->audit_meta_description(),
					'heading_hierarchy'        => $audit->audit_heading_hierarchy(),
					'keyword_density'          => $audit->audit_keyword_density(),
				);
```

- [ ] **Step 2: Add the 7 i18n title strings**

In `admin/class-nlh-admin.php`, find this line (currently line 309):

```php
					'seoRedundantLinks'    => __( 'Redundant links', 'native-link-health' ),
```

Replace it with:

```php
					'seoRedundantLinks'    => __( 'Redundant links', 'native-link-health' ),
					'seoMissingAltText'    => __( 'Images missing alt text', 'native-link-health' ),
					'seoImageDimensionMismatch' => __( 'Image dimension mismatches', 'native-link-health' ),
					'seoLegacyImageFormat' => __( 'Legacy image formats', 'native-link-health' ),
					'seoTitleLength'       => __( 'Title length', 'native-link-health' ),
					'seoMetaDescription'   => __( 'Meta description length', 'native-link-health' ),
					'seoHeadingHierarchy'  => __( 'Heading hierarchy', 'native-link-health' ),
					'seoKeywordDensity'    => __( 'Keyword density', 'native-link-health' ),
```

- [ ] **Step 3: Add the 7 keys to the JS `titles` map**

In `admin/js/nlh-admin.js`, find this block (currently lines 521-527):

```javascript
		var titles = {
			orphan_pages: nlh_ajax.i18n.seoOrphanPages,
			redirect_chains: nlh_ajax.i18n.seoRedirectChains,
			mixed_content: nlh_ajax.i18n.seoMixedContent,
			invalid_canonicals: nlh_ajax.i18n.seoInvalidCanonicals,
			redundant_links: nlh_ajax.i18n.seoRedundantLinks
		};
```

Replace it with:

```javascript
		var titles = {
			orphan_pages: nlh_ajax.i18n.seoOrphanPages,
			redirect_chains: nlh_ajax.i18n.seoRedirectChains,
			mixed_content: nlh_ajax.i18n.seoMixedContent,
			invalid_canonicals: nlh_ajax.i18n.seoInvalidCanonicals,
			redundant_links: nlh_ajax.i18n.seoRedundantLinks,
			missing_alt_text: nlh_ajax.i18n.seoMissingAltText,
			image_dimension_mismatch: nlh_ajax.i18n.seoImageDimensionMismatch,
			legacy_image_format: nlh_ajax.i18n.seoLegacyImageFormat,
			title_length: nlh_ajax.i18n.seoTitleLength,
			meta_description: nlh_ajax.i18n.seoMetaDescription,
			heading_hierarchy: nlh_ajax.i18n.seoHeadingHierarchy,
			keyword_density: nlh_ajax.i18n.seoKeywordDensity
		};
```

- [ ] **Step 4: Lint check both PHP files**

Run: `php -l admin/class-nlh-admin.php && php -l includes/class-nlh-seo-audit.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Run the pure-logic test suite to confirm nothing broke**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.`

- [ ] **Step 6: Commit**

```bash
git add admin/class-nlh-admin.php admin/js/nlh-admin.js
git commit -m "Wire the 7 new SEO audit checks into the AJAX handler and JS renderer"
```

---

## Task 10: Update AGENTS.md documentation

**Files:**
- Modify: `AGENTS.md` (the "SEO audit" bullet in "Key conventions", currently line 44)

- [ ] **Step 1: Update the SEO audit bullet**

In `AGENTS.md`, find this line:

```
- **SEO audit**: 5 checks — orphan pages, redirect chains, mixed content, invalid canonicals, redundant links. Results cached 1 day. **`redirect_chains` is now real (since 1.3.0)**: `trace_redirects()` follows each same-host internal link by hand (HEAD→GET fallback, `redirection=0`, loop-guarded, ≤6 hops) and flags only multi-hop chains (≥2 hops) or redirects that dead-end (≥400) — single clean `301→200` is intentionally NOT flagged (no-false-positives). Bounded to `nlh_redirect_chain_scan_limit` (default 50) URLs/run; only the site's own host is probed (no external hammering, no SSRF). *Detection is free*; the 301 auto-fix/management is the gated Pro feature (`nlh_pro_redirect_management_enabled`, extension point `nlh_seo_audit_after`).
```

Replace it with:

```
- **SEO audit**: 12 checks — orphan pages, redirect chains, mixed content, invalid canonicals, redundant links, missing alt text, image dimension mismatch, legacy image format, title length, meta description length, heading hierarchy, keyword density. Results cached 1 day. All 12 are free — none are Pro-gated. **`redirect_chains` is now real (since 1.3.0)**: `trace_redirects()` follows each same-host internal link by hand (HEAD→GET fallback, `redirection=0`, loop-guarded, ≤6 hops) and flags only multi-hop chains (≥2 hops) or redirects that dead-end (≥400) — single clean `301→200` is intentionally NOT flagged (no-false-positives). Bounded to `nlh_redirect_chain_scan_limit` (default 50) URLs/run; only the site's own host is probed (no external hammering, no SSRF). *Detection is free*; the 301 auto-fix/management is the gated Pro feature (`nlh_pro_redirect_management_enabled`, extension point `nlh_seo_audit_after`). **Image + on-page checks (since 1.4.0)**: `audit_missing_alt_text()` flags an absent `alt` attribute only (`alt=""` is treated as an intentional decorative marker, not flagged). `audit_image_dimension_mismatch()` and `audit_legacy_image_format()` apply only to images resolving to a local media-library attachment (`attachment_url_to_postid()`) — external/CDN images are skipped, no HTTP fetch. `audit_title_length()`/`audit_meta_description()` share a `classify_length()` helper (filterable via `nlh_seo_title_min_length`/`_max_length` and `nlh_seo_meta_description_min_length`/`_max_length`); the meta-description check measures `get_the_excerpt()` since WP core renders no `<meta name="description">` tag without an SEO plugin. `audit_heading_hierarchy()` flags multiple H1s and forward level-skips (e.g. H2→H4) but never "no H1 in content" — themes typically render the post title as H1 outside `post_content`. `audit_keyword_density()` auto-detects a focus keyword from the title (`extract_focus_keyword()`, EN/ES stopwords, ≥4 chars) since WP core has no focus-keyword field, and only flags keyword-absent-from-body or density above `nlh_seo_keyword_density_max` (default 3.0%).
```

- [ ] **Step 2: Commit**

```bash
git add AGENTS.md
git commit -m "Document the 7 new SEO audit checks in AGENTS.md"
```

---

## Task 11: Version bump and changelog

**Files:**
- Modify: `native-link-health.php:6` and `native-link-health.php:23`
- Modify: `readme.txt` (Stable tag header + Changelog section)

- [ ] **Step 1: Bump the version constant and header**

In `native-link-health.php`, change:

```php
 * Version:           1.3.3
```

to:

```php
 * Version:           1.4.0
```

And change:

```php
define( 'NLH_VERSION', '1.3.3' );
```

to:

```php
define( 'NLH_VERSION', '1.4.0' );
```

- [ ] **Step 2: Update readme.txt**

In `readme.txt`, change:

```
Stable tag: 1.3.3
```

to:

```
Stable tag: 1.4.0
```

Then find the changelog section start:

```
== Changelog ==

= 1.3.3 =
```

Replace it with:

```
== Changelog ==

= 1.4.0 =
* Added 3 image health checks to the SEO audit: missing alt text, declared-vs-real dimension mismatches, and legacy (JPG/PNG) formats — all free, checked against your own media library only (no external fetches).
* Added 4 on-page SEO checks to the SEO audit: title length, meta description (excerpt) length, heading hierarchy (multiple H1s / skipped levels), and keyword density (auto-detected from the title) — all free.
* The SEO audit now runs 12 checks total, all free, none Pro-gated.

= 1.3.3 =
```

- [ ] **Step 3: Commit**

```bash
git add native-link-health.php readme.txt
git commit -m "Bump version to 1.4.0 for the new SEO audit checks"
```

---

## Task 12: Regenerate translations

**Files:**
- Modify: `languages/native-link-health.pot`
- Modify: `languages/native-link-health-es_ES.po`
- Modify: `languages/native-link-health-es_ES.mo`

- [ ] **Step 1: Regenerate the POT file**

If `wp` (WP-CLI) is available in the implementer's environment:

Run: `wp i18n make-pot . languages/native-link-health.pot`
Expected: command completes, `languages/native-link-health.pot` is updated with the new msgids from Tasks 5–8 (e.g. "All images have an alt attribute.", "Title length", etc.).

If WP-CLI is not available (it is not installed in this project's dev environment as of this plan), skip automated regeneration and manually add the new `msgid`/`msgstr ""` entries for every new `__()`/`sprintf()` string introduced in Tasks 5-8 to `languages/native-link-health.pot`, following the existing entry format in that file (each entry has a `#: ` source comment; use the file:line of the new strings in `includes/class-nlh-seo-audit.php`).

- [ ] **Step 2: Add Spanish translations**

For every new msgid added in Step 1, add a matching `msgid`/`msgstr` pair to `languages/native-link-health-es_ES.po` with a Spanish translation, following the existing style/tone of the other entries in that file (informal "tú" register, consistent with the rest of the plugin's Spanish strings).

- [ ] **Step 3: Recompile the .mo file**

If WP-CLI is available:

Run: `wp i18n make-mo languages/native-link-health-es_ES.po`
Expected: `languages/native-link-health-es_ES.mo` is regenerated and its modification time updates.

If WP-CLI is not available, use `msgfmt` (part of GNU gettext) as a fallback:

Run: `msgfmt languages/native-link-health-es_ES.po -o languages/native-link-health-es_ES.mo`
Expected: no output on success, `languages/native-link-health-es_ES.mo` updates.

- [ ] **Step 4: Commit**

```bash
git add languages/native-link-health.pot languages/native-link-health-es_ES.po languages/native-link-health-es_ES.mo
git commit -m "Add translations for the new SEO audit checks"
```

---

## Task 13: End-to-end manual verification

**Files:** none (verification only)

- [ ] **Step 1: Full automated test suite pass**

Run: `php tests/test-seo-audit-helpers.php`
Expected: `23 tests, 0 failures.`, exit code 0.

Run: `php tests/test-scanner-helpers.php`
Expected: all tests pass (confirms Task 5-8's edits to the same directory didn't disturb the unrelated scanner file — they shouldn't have touched it at all, this is a regression guard).

- [ ] **Step 2: Lint the full plugin**

Run: `php -l includes/class-nlh-seo-audit.php && php -l admin/class-nlh-admin.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 3: Manual browser verification**

With the plugin active on the local WordPress install (`http://localhost/wpprueba/` or equivalent) and at least one published post/page containing an `<img>` tag without `alt`, and a title under 30 or over 60 characters:

1. Navigate to **Tools → Native Link Health SEO Audit** (`wp-admin/tools.php?page=nlh-seo-audit`).
2. Click **Run SEO Audit**.
3. Confirm all **12** result sections render (the original 5 plus the 7 new ones), each with a title, a pass/warning/fail-colored header, and a message.
4. Confirm the post with a missing `alt` appears under "Images missing alt text".
5. Confirm the post with an out-of-range title appears under "Title length".
6. Open the browser DevTools console — confirm no JS errors fired during the audit run.
7. Confirm **no** "Upgrade to Pro" / upsell UI appears anywhere near the 7 new sections (they must render identically to the 5 free ones, no `NLH_Pro` gating).

- [ ] **Step 4: Confirm native-link-health-pro is untouched**

Run: `git -C ../native-link-health-pro status`
Expected: `nothing to commit, working tree clean` (this plan makes no changes there).

---

## Self-Review Notes

- **Spec coverage:** Section A (3 image checks) → Tasks 4-5. Section B (4 on-page checks) → Tasks 1-3, 6-8. Wiring/UI → Task 9. Docs/versioning/translations → Tasks 10-12. Manual verification → Task 13. No spec requirement is without a task.
- **Placeholder scan:** none — every step has literal code, exact file paths, and exact expected command output.
- **Type consistency:** `classify_length()` return type (`string`: 'missing'/'short'/'long'/'ok') is used identically in Task 6's `describe_length_issue()`. `find_heading_hierarchy_issues()`'s array shape (`type`/`count` or `type`/`from`/`to`) matches exactly what `describe_heading_issue()` in Task 7 reads. All 7 result-array keys used in Task 9's AJAX wiring match the audit method names defined in Tasks 5-8 exactly.
