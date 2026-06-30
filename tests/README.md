# Tests

Lightweight, dependency-free checks for the logic that must never regress.

## Pure-logic tests (no WordPress required)

`test-scanner-helpers.php` exercises the heart of the no-false-positive engine
and the URL parser via reflection, stubbing only the i18n functions it touches:

- `NLH_Scanner::classify_error_type()` — error bucketing (5xx / 4xx / fragment /
  dns / ssl / timeout).
- `trim_url_punctuation()` — bare-URL punctuation trimming (the classic source of
  false positives on URLs ending a sentence).
- `parse_srcset()` — responsive-image candidate extraction.

Run with the bundled PHP:

```sh
php tests/test-scanner-helpers.php
```

Exit code is non-zero on any failure, so it drops straight into CI.

## Coding standards

`phpcs.xml.dist` (in the plugin root) configures WordPress Coding Standards.
With Composer dev tooling installed (`squizlabs/php_codesniffer` +
`wp-coding-standards/wpcs`):

```sh
phpcs --standard=phpcs.xml.dist
```

## Future: full integration tests

Network-dependent paths (HTTP probing, the confirmation gate, fragment
self-healing, the link graph) need the WordPress test suite. When that harness is
added, place `WP_UnitTestCase` tests here and a `phpunit.xml.dist` in the root.
