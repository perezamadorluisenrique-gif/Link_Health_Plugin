# Unified Help System + Dashboard Clarity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a unified, always-on-Settings help system to Native Link Health, and fix the Dashboard's unexplained overlap between "Correction Suggestions" (core) and "Bulk Fix & Find-Replace" (Pro), closing Requirements 1 and 3 from the `nlh-frontend-review` skill.

**Architecture:** One new static PHP partial (`admin/partials/nlh-help.php`) rendered via native `<details>`/`<summary>` accordion (no JS), included at the top of `nlh-settings.php`. One new `<p class="description">` line on the Dashboard partial. Both are core-only — `native-link-health-pro` is not touched.

**Tech Stack:** Plain PHP templates (WordPress conventions), plain CSS (no build step), WordPress's own `pomo` library (`wp-includes/pomo/*.php`) for `.po`→`.mo` compilation since WP-CLI is not installed on this machine.

## Global Constraints

- Text domain: `native-link-health`. Every new user-facing string must be wrapped in `esc_html__()` or `esc_html_e()`.
- No new JS files, no new AJAX handlers, no new DB options (per design doc's Non-goals).
- Does not touch `native-link-health-pro` (separate plugin/repo).
- Every new string must ship with an es_ES translation in the same commit as the string itself — this plan exists partly because a prior page had strings ship untranslated (see `docs/frontend-review-2026-07-10.md`).
- Plugin has **no build step** — no `npm`/`composer` install expected to run.
- Spec: `docs/superpowers/specs/2026-07-10-unified-help-system-design.md` (read this first if anything below is ambiguous).

---

### Task 1: Build the help accordion and wire it into Settings

**Files:**
- Create: `admin/partials/nlh-help.php`
- Modify: `admin/css/nlh-admin.css` (append accordion rules at end of file)
- Modify: `admin/partials/nlh-settings.php:14-16`

**Interfaces:**
- Consumes: existing CSS class `.nlh-pro-badge` (already defined at `admin/css/nlh-admin.css:721`, dark pill badge, already used elsewhere for Pro-only UI) — reuse it verbatim, do not create a new Pro-badge class.
- Produces: `admin/partials/nlh-help.php` — a self-contained include with no return value, expects `ABSPATH` to be defined (guards itself). New CSS classes `.nlh-help`, `.nlh-help-intro`, `.nlh-help-panel`, `.nlh-help-panel-body` — Task 3 does not touch these, only later manual QA (Task 5) depends on the exact `<details>`/`<summary>` structure below.

- [ ] **Step 1: Create `admin/partials/nlh-help.php`**

```php
<?php
/**
 * Unified help section for the Settings page.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="nlh-help">
	<h2><?php esc_html_e( 'How to use Native Link Health', 'native-link-health' ); ?></h2>
	<p class="nlh-help-intro"><?php esc_html_e( 'A quick reference for what each screen does and how the free and Pro features fit together.', 'native-link-health' ); ?></p>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Overview', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Native Link Health scans your site for broken links and tracks how authority flows between your own pages, entirely on your own server. The Link Health Score blends two things: 60% how many broken links are currently unresolved, and 40% how well internal authority (Link Juice) flows through your content. A lower score means more broken links, more isolated pages, or both.', 'native-link-health' ); ?></p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Dashboard — broken links', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'The scanner runs automatically every 15 minutes, checking a few posts at a time so it never slows down your site. Use "Scan Now" to check everything immediately instead of waiting. Broken links appear in the list with their error type and impact score.', 'native-link-health' ); ?></p>
			<p><?php esc_html_e( 'Correction Suggestions groups broken links by domain and lets you fix every matching URL at once — it only shows patterns detected automatically from what is already broken.', 'native-link-health' ); ?></p>
			<p>
				<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				<?php esc_html_e( 'Bulk Fix & Find-Replace works differently: it lets you replace any URL, anywhere in your content, whether or not it is currently flagged as broken.', 'native-link-health' ); ?>
			</p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'SEO Audit', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Runs 12 checks against your published content: orphan pages, redirect chains and dead-end redirects, mixed content, invalid canonical tags, and redundant links; missing image alt text, image dimension mismatches, and legacy image formats; and title length, meta description length, heading hierarchy, and keyword density. All 12 checks are free. Results are cached for a day — run the audit again any time to refresh them.', 'native-link-health' ); ?></p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Link Juice', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Maps how authority flows between your own pages through internal links, entirely offline with no external requests. The site graph shows your most-linked pages as larger nodes; click a node to see its connections. Pages are flagged as Orphans (nothing links to them), Dead Ends (they receive links but link out to nothing), or Diluted (they link out to too many pages, spreading their authority thin). Recommendations suggest which pages to link together first.', 'native-link-health' ); ?></p>
			<p>
				<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				<?php esc_html_e( 'Insert Link lets you add a suggested link directly from a recommendation card without leaving this page.', 'native-link-health' ); ?>
			</p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Settings (this page)', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Scan Scope controls which content types get scanned beyond posts and pages — turn on Media, Comments, or Navigation Menus here. Batch Size controls how many items the background scanner checks every 15 minutes; lower it on a slow host, raise it on a fast one. Auto-fix Rules let you define JSON rules that automatically rewrite known-bad domains whenever the scanner runs.', 'native-link-health' ); ?></p>
			<p>
				<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				<?php esc_html_e( 'Bulk Fix & Find-Replace, Redirect Manager, and Email Notifications add manual bulk editing, 301/302 redirect management, and broken-link email alerts.', 'native-link-health' ); ?>
			</p>
		</div>
	</details>
</div>
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l "C:\xampp\htdocs\wpprueba\wp-content\plugins\native-link-health\admin\partials\nlh-help.php"`
Expected: `No syntax errors detected in ...nlh-help.php`

- [ ] **Step 3: Append accordion CSS to `admin/css/nlh-admin.css`**

Append at the end of the file:

```css

.nlh-help {
	margin: 16px 0 24px;
}

.nlh-help-intro {
	color: #50575e;
	margin: 4px 0 12px;
}

.nlh-help-panel {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 6px;
	margin-bottom: 8px;
}

.nlh-help-panel summary {
	cursor: pointer;
	font-weight: 600;
	list-style: none;
	padding: 12px 16px;
}

.nlh-help-panel summary::-webkit-details-marker {
	display: none;
}

.nlh-help-panel summary::before {
	content: "\25B8";
	display: inline-block;
	margin-right: 8px;
	transition: transform 0.15s ease-in-out;
}

.nlh-help-panel[open] summary::before {
	transform: rotate(90deg);
}

.nlh-help-panel-body {
	border-top: 1px solid #dcdcde;
	color: #3c434a;
	line-height: 1.6;
	padding: 12px 16px 16px;
}

.nlh-help-panel-body p {
	margin: 0 0 10px;
}

.nlh-help-panel-body p:last-child {
	margin-bottom: 0;
}
```

- [ ] **Step 4: Wire the include into `admin/partials/nlh-settings.php`**

Change (lines 13-16):

```php
<div class="wrap nlh-wrap">
	<h1><?php esc_html_e( 'Native Link Health Settings', 'native-link-health' ); ?></h1>

	<form action="options.php" method="post" class="nlh-settings-form">
```

to:

```php
<div class="wrap nlh-wrap">
	<h1><?php esc_html_e( 'Native Link Health Settings', 'native-link-health' ); ?></h1>

	<?php include NLH_PLUGIN_DIR . 'admin/partials/nlh-help.php'; ?>

	<form action="options.php" method="post" class="nlh-settings-form">
```

- [ ] **Step 5: Verify syntax on the modified file**

Run: `php -l "C:\xampp\htdocs\wpprueba\wp-content\plugins\native-link-health\admin\partials\nlh-settings.php"`
Expected: `No syntax errors detected in ...nlh-settings.php`

- [ ] **Step 6: Commit**

```bash
git add admin/partials/nlh-help.php admin/css/nlh-admin.css admin/partials/nlh-settings.php
git commit -m "Add unified help accordion to the Settings page"
```

---

### Task 2: Clarify Correction Suggestions on the Dashboard

**Files:**
- Modify: `admin/partials/nlh-dashboard.php:308-311`

**Interfaces:**
- Consumes: nothing new.
- Produces: one new translatable string, consumed only by Task 3's i18n pass.

- [ ] **Step 1: Add a description line under the "Correction Suggestions" heading**

Change (lines 308-311):

```php
	<?php if ( ! empty( $suggestions ) ) : ?>
		<div class="nlh-suggestions-section">
			<h2><?php esc_html_e( 'Correction Suggestions', 'native-link-health' ); ?></h2>
			<?php foreach ( $suggestions as $suggestion ) : ?>
```

to:

```php
	<?php if ( ! empty( $suggestions ) ) : ?>
		<div class="nlh-suggestions-section">
			<h2><?php esc_html_e( 'Correction Suggestions', 'native-link-health' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Detected automatically from your broken links — approve a suggestion to fix every matching URL at once.', 'native-link-health' ); ?></p>
			<?php foreach ( $suggestions as $suggestion ) : ?>
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l "C:\xampp\htdocs\wpprueba\wp-content\plugins\native-link-health\admin\partials\nlh-dashboard.php"`
Expected: `No syntax errors detected in ...nlh-dashboard.php`

- [ ] **Step 3: Commit**

```bash
git add admin/partials/nlh-dashboard.php
git commit -m "Clarify Correction Suggestions vs Bulk Fix on the Dashboard"
```

---

### Task 3: Translate every new string (POT + Spanish PO + compiled MO)

**Files:**
- Modify: `languages/native-link-health.pot` (append new msgid blocks before the final line)
- Modify: `languages/native-link-health-es_ES.po` (append new msgid/msgstr blocks)
- Regenerate: `languages/native-link-health-es_ES.mo`

**Interfaces:**
- Consumes: the exact English strings introduced in Task 1 and Task 2 (copy them verbatim — a single-character mismatch means `esc_html_e()` falls back to English at runtime even with a translation present).
- Produces: nothing consumed by later tasks — this is a leaf task, but it is **not optional**: Task 5's verification explicitly checks the Spanish locale.

`SEO Audit`, `Link Juice`, and `Pro` already exist as msgids in both files (confirmed via `grep`) — do **not** add duplicate entries for those three; `esc_html_e()` calls using them already resolve correctly.

- [ ] **Step 1: Append new msgid blocks to `languages/native-link-health.pot`**

Add at the end of the file (after the last existing entry):

```
#: admin/partials/nlh-help.php
msgid "How to use Native Link Health"
msgstr ""

#: admin/partials/nlh-help.php
msgid "A quick reference for what each screen does and how the free and Pro features fit together."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Overview"
msgstr ""

#: admin/partials/nlh-help.php
msgid "Native Link Health scans your site for broken links and tracks how authority flows between your own pages, entirely on your own server. The Link Health Score blends two things: 60% how many broken links are currently unresolved, and 40% how well internal authority (Link Juice) flows through your content. A lower score means more broken links, more isolated pages, or both."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Dashboard — broken links"
msgstr ""

#: admin/partials/nlh-help.php
msgid "The scanner runs automatically every 15 minutes, checking a few posts at a time so it never slows down your site. Use \"Scan Now\" to check everything immediately instead of waiting. Broken links appear in the list with their error type and impact score."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Correction Suggestions groups broken links by domain and lets you fix every matching URL at once — it only shows patterns detected automatically from what is already broken."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Bulk Fix & Find-Replace works differently: it lets you replace any URL, anywhere in your content, whether or not it is currently flagged as broken."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Runs 12 checks against your published content: orphan pages, redirect chains and dead-end redirects, mixed content, invalid canonical tags, and redundant links; missing image alt text, image dimension mismatches, and legacy image formats; and title length, meta description length, heading hierarchy, and keyword density. All 12 checks are free. Results are cached for a day — run the audit again any time to refresh them."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Maps how authority flows between your own pages through internal links, entirely offline with no external requests. The site graph shows your most-linked pages as larger nodes; click a node to see its connections. Pages are flagged as Orphans (nothing links to them), Dead Ends (they receive links but link out to nothing), or Diluted (they link out to too many pages, spreading their authority thin). Recommendations suggest which pages to link together first."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Insert Link lets you add a suggested link directly from a recommendation card without leaving this page."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Settings (this page)"
msgstr ""

#: admin/partials/nlh-help.php
msgid "Scan Scope controls which content types get scanned beyond posts and pages — turn on Media, Comments, or Navigation Menus here. Batch Size controls how many items the background scanner checks every 15 minutes; lower it on a slow host, raise it on a fast one. Auto-fix Rules let you define JSON rules that automatically rewrite known-bad domains whenever the scanner runs."
msgstr ""

#: admin/partials/nlh-help.php
msgid "Bulk Fix & Find-Replace, Redirect Manager, and Email Notifications add manual bulk editing, 301/302 redirect management, and broken-link email alerts."
msgstr ""

#: admin/partials/nlh-dashboard.php
msgid "Detected automatically from your broken links — approve a suggestion to fix every matching URL at once."
msgstr ""
```

- [ ] **Step 2: Append the same msgids with Spanish translations to `languages/native-link-health-es_ES.po`**

Add at the end of the file:

```
#: admin/partials/nlh-help.php
msgid "How to use Native Link Health"
msgstr "Cómo usar Native Link Health"

#: admin/partials/nlh-help.php
msgid "A quick reference for what each screen does and how the free and Pro features fit together."
msgstr "Una referencia rápida sobre qué hace cada pantalla y cómo encajan las funciones gratuitas y Pro."

#: admin/partials/nlh-help.php
msgid "Overview"
msgstr "Resumen"

#: admin/partials/nlh-help.php
msgid "Native Link Health scans your site for broken links and tracks how authority flows between your own pages, entirely on your own server. The Link Health Score blends two things: 60% how many broken links are currently unresolved, and 40% how well internal authority (Link Juice) flows through your content. A lower score means more broken links, more isolated pages, or both."
msgstr "Native Link Health escanea tu sitio en busca de enlaces rotos y analiza cómo fluye la autoridad entre tus propias páginas, todo en tu propio servidor. La Puntuación de salud de enlaces combina dos cosas: 60% cuántos enlaces rotos siguen sin resolver, y 40% qué tan bien fluye la autoridad interna (Link Juice) por tu contenido. Una puntuación más baja significa más enlaces rotos, más páginas aisladas, o ambas cosas."

#: admin/partials/nlh-help.php
msgid "Dashboard — broken links"
msgstr "Panel principal — enlaces rotos"

#: admin/partials/nlh-help.php
msgid "The scanner runs automatically every 15 minutes, checking a few posts at a time so it never slows down your site. Use \"Scan Now\" to check everything immediately instead of waiting. Broken links appear in the list with their error type and impact score."
msgstr "El escáner se ejecuta automáticamente cada 15 minutos, revisando unas pocas entradas a la vez para no ralentizar tu sitio. Usa «Escanear ahora» para revisarlo todo de inmediato en lugar de esperar. Los enlaces rotos aparecen en la lista con su tipo de error y su puntuación de impacto."

#: admin/partials/nlh-help.php
msgid "Correction Suggestions groups broken links by domain and lets you fix every matching URL at once — it only shows patterns detected automatically from what is already broken."
msgstr "«Sugerencias de corrección» agrupa los enlaces rotos por dominio y te permite corregir todas las URLs de un patrón a la vez — solo muestra patrones detectados automáticamente a partir de lo que ya está roto."

#: admin/partials/nlh-help.php
msgid "Bulk Fix & Find-Replace works differently: it lets you replace any URL, anywhere in your content, whether or not it is currently flagged as broken."
msgstr "«Bulk Fix & Find-Replace» funciona de forma distinta: te permite reemplazar cualquier URL, en cualquier parte de tu contenido, esté o no marcada como rota actualmente."

#: admin/partials/nlh-help.php
msgid "Runs 12 checks against your published content: orphan pages, redirect chains and dead-end redirects, mixed content, invalid canonical tags, and redundant links; missing image alt text, image dimension mismatches, and legacy image formats; and title length, meta description length, heading hierarchy, and keyword density. All 12 checks are free. Results are cached for a day — run the audit again any time to refresh them."
msgstr "Ejecuta 12 comprobaciones sobre tu contenido publicado: páginas huérfanas, cadenas de redirección y redirecciones sin destino final, contenido mixto, etiquetas canónicas inválidas y enlaces redundantes; texto alternativo de imagen ausente, discrepancias en las dimensiones de imagen y formatos de imagen antiguos; y longitud del título, longitud de la meta descripción, jerarquía de encabezados y densidad de palabra clave. Las 12 comprobaciones son gratuitas. Los resultados se guardan en caché durante un día — puedes volver a ejecutar la auditoría cuando quieras para actualizarlos."

#: admin/partials/nlh-help.php
msgid "Maps how authority flows between your own pages through internal links, entirely offline with no external requests. The site graph shows your most-linked pages as larger nodes; click a node to see its connections. Pages are flagged as Orphans (nothing links to them), Dead Ends (they receive links but link out to nothing), or Diluted (they link out to too many pages, spreading their authority thin). Recommendations suggest which pages to link together first."
msgstr "Mapea cómo fluye la autoridad entre tus propias páginas a través de los enlaces internos, completamente sin conexión y sin peticiones externas. El mapa del sitio muestra tus páginas más enlazadas como nodos más grandes; haz clic en un nodo para ver sus conexiones. Las páginas se marcan como Huérfanas (nada enlaza a ellas), Sin salida (reciben enlaces pero no enlazan a nada) o Diluidas (enlazan a demasiadas páginas, repartiendo su autoridad). «Próximos pasos recomendados» sugiere qué páginas enlazar primero entre sí."

#: admin/partials/nlh-help.php
msgid "Insert Link lets you add a suggested link directly from a recommendation card without leaving this page."
msgstr "«Insert Link» te permite añadir un enlace sugerido directamente desde una tarjeta de recomendación sin salir de esta página."

#: admin/partials/nlh-help.php
msgid "Settings (this page)"
msgstr "Ajustes (esta página)"

#: admin/partials/nlh-help.php
msgid "Scan Scope controls which content types get scanned beyond posts and pages — turn on Media, Comments, or Navigation Menus here. Batch Size controls how many items the background scanner checks every 15 minutes; lower it on a slow host, raise it on a fast one. Auto-fix Rules let you define JSON rules that automatically rewrite known-bad domains whenever the scanner runs."
msgstr "«Alcance del escaneo» controla qué tipos de contenido se escanean además de entradas y páginas — actívalo aquí para Medios, Comentarios o Menús de navegación. «Tamaño del lote» controla cuántos elementos revisa el escáner en segundo plano cada 15 minutos; redúcelo en un hosting lento, auméntalo en uno rápido. «Reglas de corrección automática» te permiten definir reglas en JSON que reescriben automáticamente dominios rotos conocidos cada vez que se ejecuta el escáner."

#: admin/partials/nlh-help.php
msgid "Bulk Fix & Find-Replace, Redirect Manager, and Email Notifications add manual bulk editing, 301/302 redirect management, and broken-link email alerts."
msgstr "«Bulk Fix & Find-Replace», «Redirect Manager» y «Email Notifications» añaden edición masiva manual, gestión de redirecciones 301/302 y alertas por correo de enlaces rotos."

#: admin/partials/nlh-dashboard.php
msgid "Detected automatically from your broken links — approve a suggestion to fix every matching URL at once."
msgstr "Detectado automáticamente a partir de tus enlaces rotos — aprueba una sugerencia para corregir todas las URLs de ese patrón a la vez."
```

- [ ] **Step 3: Create a one-off `.po`→`.mo` compiler script**

WP-CLI is not installed on this machine, so compile using WordPress's own `pomo` library directly. Create `C:\xampp\htdocs\wpprueba\compile-mo-nlh.php` (temporary — not part of the plugin, delete after Step 4):

```php
<?php
$wp_root = 'C:/xampp/htdocs/wpprueba';
require $wp_root . '/wp-includes/pomo/translations.php';
require $wp_root . '/wp-includes/pomo/entry.php';
require $wp_root . '/wp-includes/pomo/po.php';
require $wp_root . '/wp-includes/pomo/mo.php';

$po_file = $wp_root . '/wp-content/plugins/native-link-health/languages/native-link-health-es_ES.po';
$mo_file = $wp_root . '/wp-content/plugins/native-link-health/languages/native-link-health-es_ES.mo';

$po = new PO();
if ( ! $po->import_from_file( $po_file ) ) {
	fwrite( STDERR, "Failed to parse PO file\n" );
	exit( 1 );
}

$mo = new MO();
$mo->set_headers( $po->headers );
foreach ( $po->entries as $entry ) {
	$mo->add_entry( $entry );
}

if ( ! $mo->export_to_file( $mo_file ) ) {
	fwrite( STDERR, "Failed to write MO file\n" );
	exit( 1 );
}

echo 'OK: compiled ' . count( $po->entries ) . " entries to $mo_file\n";
```

- [ ] **Step 4: Run the compiler and verify output**

Run: `php C:\xampp\htdocs\wpprueba\compile-mo-nlh.php`
Expected: `OK: compiled <N> entries to C:/xampp/htdocs/wpprueba/wp-content/plugins/native-link-health/languages/native-link-health-es_ES.mo` where `<N>` is comfortably larger than before (hundreds of entries) — a failure prints to stderr and exits 1 instead.

- [ ] **Step 5: Spot-check one new translation resolves correctly**

Run:
```bash
php -r '
$wp_root = "C:/xampp/htdocs/wpprueba";
require $wp_root . "/wp-includes/pomo/translations.php";
require $wp_root . "/wp-includes/pomo/entry.php";
require $wp_root . "/wp-includes/pomo/mo.php";
$mo = new MO();
$mo->import_from_file( $wp_root . "/wp-content/plugins/native-link-health/languages/native-link-health-es_ES.mo" );
echo $mo->translate( "How to use Native Link Health" ) . "\n";
'
```
Expected: `Cómo usar Native Link Health`

- [ ] **Step 6: Delete the temporary compiler script**

Run: `rm "C:\xampp\htdocs\wpprueba\compile-mo-nlh.php"` (or `Remove-Item` in PowerShell) — it must not be committed.

- [ ] **Step 7: Commit**

```bash
git add languages/native-link-health.pot languages/native-link-health-es_ES.po languages/native-link-health-es_ES.mo
git commit -m "Translate the new help accordion and Dashboard clarification strings"
```

---

### Task 4: Version bump and changelog

**Files:**
- Modify: `native-link-health.php:6,23`
- Modify: `readme.txt:7` and the Changelog section
- Modify: `AGENTS.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks — purely bookkeeping, but required before Task 5 treats the feature as release-complete.

- [ ] **Step 1: Bump the version in `native-link-health.php`**

Change line 6 `* Version:           1.4.0` to `* Version:           1.5.0`
Change line 23 `define( 'NLH_VERSION', '1.4.0' );` to `define( 'NLH_VERSION', '1.5.0' );`

- [ ] **Step 2: Update `readme.txt`**

Change line 7 `Stable tag: 1.4.0` to `Stable tag: 1.5.0`

Add to the top of the Changelog section (after `== Changelog ==` on line 90, before the existing `= 1.4.0 =` entry):

```
= 1.5.0 =
* Added a unified "How to use Native Link Health" help section to the Settings page — a collapsible reference covering all 4 screens and what the free vs Pro features do.
* Clarified the Dashboard's "Correction Suggestions" with a one-line description distinguishing it from the Pro Bulk Fix & Find-Replace tool.

```

- [ ] **Step 3: Update `AGENTS.md`**

Add a new line under the "Dashboard health overview & speed (since 1.3.0)" section (after the existing bullet list in that section):

```markdown
- **Unified help (since 1.5.0)**: `admin/partials/nlh-help.php` renders a `<details>`/`<summary>` accordion (no JS) at the top of the Settings page — Overview, Dashboard, SEO Audit, Link Juice, and Settings panels, each covering free behavior and labeling Pro-only features with the existing `.nlh-pro-badge` class. The Dashboard's "Correction Suggestions" heading also gained a one-line description distinguishing it from Pro's Bulk Fix & Find-Replace tool.
```

- [ ] **Step 4: Commit**

```bash
git add native-link-health.php readme.txt AGENTS.md
git commit -m "Bump to 1.5.0 for the unified help system"
```

---

### Task 5: Live verification

**Files:** none modified — this task only reads/observes.

**Interfaces:**
- Consumes: the fully assembled Settings and Dashboard pages from Tasks 1-4.
- Produces: a pass/fail confirmation that Requirements 1 and 3 (from the original review) actually hold.

- [ ] **Step 1: Confirm XAMPP is running**

Run: `tasklist //FI "IMAGENAME eq httpd.exe"` and `tasklist //FI "IMAGENAME eq mysqld.exe"`
Expected: both show at least one running process. If not, start via `C:\xampp\apache_start.bat` and `C:\xampp\mysql_start.bat`.

- [ ] **Step 2: Load Settings in the browser (Playwright), confirm the accordion**

Navigate to `http://localhost/wpprueba/wp-admin/options-general.php?page=nlh-settings`, take a full-page screenshot. Confirm:
- The "How to use Native Link Health" heading and all 5 collapsed panel summaries are visible, above the existing settings form.
- The existing settings form, CSV export, and (if Pro active) Pro card still render unchanged below it.

- [ ] **Step 3: Expand each panel, confirm content and zero console errors**

Click each of the 5 `<summary>` elements in turn; confirm the body text appears (no JS needed — native `<details>` toggle). Check `browser_console_messages` at `error` level after all 5 are expanded.
Expected: 0 console errors attributable to `nlh-help.php` or `nlh-admin.css` (the pre-existing foreign-plugin notice error from `docs/frontend-review-2026-07-10.md` is a known, separate, out-of-scope issue — don't treat it as a regression here).

- [ ] **Step 4: Confirm Spanish translations render**

This site's admin locale is already es_ES (confirmed in the original review). Reload Settings, expand the "Resumen" panel (was "Overview"), confirm the body text reads in Spanish, not English. Spot-check the "Panel principal — enlaces rotos" panel too.

- [ ] **Step 5: Confirm the Dashboard clarification**

Navigate to `http://localhost/wpprueba/wp-admin/tools.php?page=nlh-dashboard`. If any "Correction Suggestions" cards are present (there were 4 domain patterns in the original review), confirm the new one-line description appears directly under the "Correction Suggestions" (Spanish: "Sugerencias de corrección") heading, above the cards.

- [ ] **Step 6: Re-run the `nlh-frontend-review` skill's Requirement 3 check**

Confirm `admin/partials/nlh-settings.php` no longer matches the "known gap" described in the skill (`C:\Users\perez\.claude\skills\nlh-frontend-review\SKILL.md`) — the unified help section now exists. No skill file edit is required by this step; it's a manual re-check against that rubric.

- [ ] **Step 7: Final commit if any fixes were needed during verification**

If Steps 2-6 surfaced any bugs, fix them, re-verify, and commit with a message describing the fix. If everything passed clean, this step is a no-op.
