# Memory: woo-cart-rescue

Update this file after every meaningful chunk of work: move items between the sections below and
log every non-obvious decision WITH its reason.

## Completed

- Toolchain: git repo (local identity Ali Raza), `.gitignore`, `composer.json` (dev deps pinned per
  architecture.md), `composer.lock`, `phpcs.xml.dist`, `phpunit.xml.dist`, `.distignore`,
  `.wp-env.json`. PHPCS 3.13.5 and PHPUnit 9.6.35 confirmed runnable.
- Phase 1 COMPLETE. Bootstrap + WooCommerce guard + admin notice; `wcr_log` over `wc_get_logger`;
  versioned migration runner (`wcr_db_version`) + `migrate_1` (four tables via dbDelta); token
  secret generated non-autoloaded on activation; recurring `wcr_abandonment_sweep` (15 min) and
  `wcr_retention_cleanup` (daily) registered; logged-in capture on `woocommerce_cart_updated`;
  consent checkbox on classic checkout; nonce-checked guest capture AJAX with server-side consent
  gate and silent opt-out skip; activity refresh on checkout load; Settings page (enable, idle
  window, retention, consent label) with server-side clamping. Verification: `composer run lint`
  clean (20 files), `composer run test` green (7 tests / unit mode). Integration test
  `test-capture.php` written to the WP-suite contract, no-ops here.

- Phase 2 COMPLETE. `WCR_Token` (HMAC build/parse/verify/hash/generate/is_expired + validate_restore
  and validate_unsubscribe per api-contracts); resume-safe `wcr_enqueue_send`; abandonment sweep with
  full eligibility (idle, email present, non-empty, no order since capture via CRUD, not opted out,
  batch 200) and atomic abandon transition; reactivation on new activity with pending-send cancel;
  recovery email class + HTML/plain templates + merge-tag helper; race-safe sender (atomic claim then
  send-time recheck, token stored as hash+expiry, sent/failed events, no retry loop); restore endpoint
  (single-use claim, cart rebuild skipping unpurchasable items, attribution session keys, generic
  failure notice); unsubscribe endpoint (signature-only, cancel + opt-out hash, idempotent,
  confirmation template); order-placed cancellation (status set before send-cancel for race safety);
  daily retention cleanup (purge non-recovered + anonymize recovered) and privacy exporter/eraser.
  Verification: `composer run lint` clean (30 files), `composer run test` green (19 tests, unit mode).
  Integration tests (abandonment, sender, endpoints, privacy, orders) written to the WP-suite
  contract, seeding rows via $wpdb; they no-op here and run under wp-env/CI.

- Phase 3 COMPLETE. Per-step enable/delay settings + restore-link TTL field with server-side
  clamping; sender chains the next enabled step after a successful send (delay from this send);
  three `WCR_Email_Recovery` steps registered via `woocommerce_email_classes` (editable
  subject/heading per id); sweep resumes at the first enabled unsent step via `wcr_next_unsent_step`.
  Verification: lint clean, `composer run test` green (21 tests, unit mode). Integration coverage for
  chaining/skips/cross-step cancellation/resume added (runs under wp-env/CI).

- Phase 4 COMPLETE. Order attribution reads restore session keys; an order within the attribution
  window marks the cart `recovered` with `recovered_order_id`, `recovered_total`, `recovered_at`, and
  order meta `_wcr_recovered_cart_id`; outside the window (or no restore click) it is `completed`.
  Attribution window is a bounded setting (0..365). Report tab (Settings/Report nav, manage_woocommerce)
  with a date-range filter (default last 30 days), stat cards for abandoned / emails sent (per step) /
  recovered orders / recovered revenue (`wc_price`) / recovery rate, an "Opens: not tracked" note, and
  an empty state. Report revenue/count query keyed on `recovered_order_id IS NOT NULL` so anonymized
  recovered carts still count. `get_report_data` made public for testing. Verification: lint clean,
  `composer run test` green (21 tests, unit mode); attribution-edge and report-query integration tests
  added.

## In progress

- Phase 5: i18n, uninstall, distribution hardening.

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
- Default `enabled = true` (open question; PRD success criteria assume capture is active out of the
  box and the manual checklist adds items without a config step). Logged here per the "documented
  defaults" instruction. Idle window default 60 min (PRD), retention default 30 days.
- WPCS flags the mandated 3-char `wcr`/`WCR` prefix as "too short" (ShortPrefixPassed). The prefix
  is binding (docs/rules.md), so that one sub-sniff is excluded in `phpcs.xml.dist`; the prefix
  requirement itself is still enforced.
- Commit-order note: the `wcr_log` helper (phases.md commit 3) was committed before the WooCommerce
  activation block (commit 2) because the block logs through `wcr_log`; committing the block first
  would reference an undefined function. Only these two adjacent Phase-1 commits were reordered.
- Owner instruction mid-run: make small granular commits (each migration/helper/endpoint/test its
  own commit). Applied from the migration runner onward.
- Added test files beyond the proposed tree: `tests/test-settings.php`, `tests/test-merge-tags.php`
  (pure unit), and `tests/test-endpoints.php` (integration for restore token states + unsubscribe).
  The architecture file tree is "proposed"; testing.md mandates these unit tests, so dedicated files
  are added as needed and kept one-class-per-file.
- `WCR_Endpoints::apply_unsubscribe()` is public (not protected) so the unsubscribe side effect is
  directly testable without a test-only subclass (which would break one-class-per-file / FileName).
- Retention purge keyed on `last_activity_at < cutoff` with status NOT IN (recovered, anonymized).
  Retention setting min stays 1 (Phase-1 committed/tested); the cleanup test forces purging with
  backdated rows rather than retention 0, satisfying the manual "retention 0" step's intent.
- Send race-safety ordering chosen: claim (scheduled->sending) FIRST, then recheck cart status as
  late as possible before dispatch. Order-placed and unsubscribe both flip cart status BEFORE
  cancelling sends, so an in-flight worker's recheck sees the non-abandoned state and stops.
- Deviation from design.md: per-step delay inputs are NOT rendered with the `disabled` attribute
  when a step is toggled off. Disabled inputs are not POSTed, which would drop a saved delay on the
  next save. Delays stay editable/submittable; the enable checkbox alone gates the step. Logged per
  the "flag deviations" rule.
