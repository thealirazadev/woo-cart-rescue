# Launch Checklist: woo-cart-rescue

Fill near the end of implementation. Nothing ships while an item is unchecked without a written
reason next to it.

- [ ] `WP_DEBUG` / `WP_DEBUG_DISPLAY` off on the production site; no notices in the WooCommerce
      log (source `woo-cart-rescue`) after a full happy-path run
- [ ] Plugin header and `readme.txt` declare tested-up-to WordPress, WooCommerce, and PHP versions
- [ ] Exact dependency versions pinned; `composer.lock` committed; no dev deps in the built zip
      (`.distignore` verified by inspecting the zip)
- [ ] All four `wcr_*` tables created on a clean install; `wcr_db_version` correct; migration
      runner re-runs safely (activate twice, no errors)
- [ ] `wcr_token_secret` generated and non-autoloaded; no secret committed anywhere in the repo
- [ ] Email deliverability checked on the production mailer (SPF/DKIM configured at the store
      level; test recovery email lands in inbox, not spam)
- [ ] Restore link clicked from a real external email client rebuilds the cart on production
- [ ] Unsubscribe link works from a real email and suppresses future sequences
- [ ] Consent checkbox is unchecked by default and its copy has been reviewed; privacy policy
      suggested text appears under Settings > Privacy
- [ ] Retention cleanup ran at least once on staging with production-like data; counts sane
- [ ] Personal-data export and erase verified for a guest email on staging
- [ ] Action Scheduler queue healthy: no growing backlog of `woo-cart-rescue` group actions,
      no repeated failures under Tools > Scheduled Actions
- [ ] HPOS enabled on staging: capture, attribution, and report verified
- [ ] Report figures cross-checked against raw table counts for one real date range
- [ ] Uninstall on staging removes tables, options, and scheduled actions
- [ ] PHPCS clean; PHPUnit green; version bumped in the plugin header and `readme.txt` changelog
