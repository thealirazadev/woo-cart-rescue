# Memory: woo-cart-rescue

Update this file after every meaningful chunk of work: move items between the sections below and
log every non-obvious decision WITH its reason.

## Completed

- Toolchain: git repo (local identity Ali Raza), `.gitignore`, `composer.json` (dev deps pinned per
  architecture.md), `composer.lock`, `phpcs.xml.dist`, `phpunit.xml.dist`, `.distignore`,
  `.wp-env.json`. PHPCS 3.13.5 and PHPUnit 9.6.35 confirmed runnable.

## In progress

- Phase 1: foundation, schema, consent-gated capture.

## Decisions log

- wp-env unavailable in this environment: Docker daemon is up, but both genuine `npx wp-env start`
  attempts failed with network `ETIMEDOUT` fetching WordPress core from wordpress.org (throughput
  ~15KB/s; core zip is 31MB). No containers started. Per the build authorization, fell back to
  static verification: PHPCS (WPCS) is the always-available gate; a standalone PHPUnit "unit mode"
  suite (WP/Woo stubs, no WordPress load) covers the pure security-critical logic — token
  sign/parse/verify/tamper/expiry, settings sanitization/clamping, merge-tag rendering. The
  integration test files (capture, abandonment, sender, orders, privacy, uninstall) are written to
  the documented WP-test-suite contract and guarded to no-op when `WP_UnitTestCase` is absent, so
  the same `tests/` runs green here and fully under wp-env/CI. Live-in-browser verification of the
  hooks/Action Scheduler flows could not be performed here and is noted for staging.
- Pinned dev dependency versions mirror the sibling `woo-product-faq` set, which is known-good in
  this PHP 8.2 environment: phpunit 9.6.35, php_codesniffer 3.13.5, wpcs 3.3.0,
  phpcompatibility-wp 2.1.8, phpunit-polyfills 4.0.0. No runtime dependencies (WooCommerce bundles
  Action Scheduler).
- Pure, hermetically testable helpers `wcr_render_merge_tags()` and `wcr_sanitize_settings()` live
  in `wcr-functions.php` (not inside the `WC_Email` subclass or the Settings API callback) so the
  unit suite can exercise them without loading WooCommerce. Rule-of-three not triggered; this is to
  make the documented "pure PHP, no WordPress load" unit tests real.
