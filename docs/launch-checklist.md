# Launch Checklist: woo-cart-rescue

Status key: `[x]` verified in this environment; `[ ]` requires a live/staging store or real mailer
and is left for the human operator. wp-env/live verification was blocked here by network throttling
to wordpress.org, so hook/Action-Scheduler/report flows are covered by the WP-suite integration
tests (run under wp-env/CI) plus a stubbed boot smoke-test, not by clicking through a live site.

- [ ] `WP_DEBUG` / `WP_DEBUG_DISPLAY` off on the production site; no notices in the WooCommerce
      log (source `woo-cart-rescue`) after a full happy-path run. (Live run required.)
- [x] Plugin header and `readme.txt` declare tested-up-to WordPress, WooCommerce, and PHP versions
      (Requires at least 6.4, Requires PHP 8.1, WC requires at least 8.0).
- [x] Exact dependency versions pinned in `composer.json`; `composer.lock` committed. `.distignore`
      excludes `vendor`, `tests`, `bin`, and dev config. (Confirm by inspecting the built zip.)
- [~] All four `wcr_*` tables created on a clean install; `wcr_db_version` correct; migration runner
      is idempotent (guarded by `wcr_db_version`, advances per migration). Activate-twice on a live
      site still to be confirmed.
- [x] `wcr_token_secret` generated with `random_bytes(32)` and stored non-autoloaded; no secret is
      committed anywhere in the repo.
- [ ] Email deliverability checked on the production mailer (SPF/DKIM; test email lands in inbox).
- [ ] Restore link clicked from a real external email client rebuilds the cart on production.
- [ ] Unsubscribe link works from a real email and suppresses future sequences.
- [x] Consent checkbox is unchecked by default and its copy names what is stored and why; privacy
      policy suggested text is registered via `wp_add_privacy_policy_content`.
- [ ] Retention cleanup ran at least once on staging with production-like data; counts sane.
      (Logic + integration test in place; staging run required.)
- [~] Personal-data export and erase implemented and unit-tested; verify for a guest email on
      staging.
- [ ] Action Scheduler queue healthy: no growing backlog of `woo-cart-rescue` group actions.
- [~] HPOS compatibility declared via `FeaturesUtil`; orders touched only through the CRUD API.
      Verify capture/attribution/report with HPOS enabled on staging.
- [~] Report figures cross-checked against raw table counts (covered by the report integration
      test; cross-check one real range on staging).
- [~] Uninstall removes tables, options, and scheduled actions (implemented + integration test);
      confirm on staging.
- [x] PHPCS clean; PHPUnit green; version 1.0.0 set in the plugin header and `readme.txt` changelog.
