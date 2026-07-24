## Summary

What does this change and why? Link any related issue (`Fixes #123`).

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactor / internal change
- [ ] Documentation
- [ ] Tests only

## Checklist

- [ ] `composer run lint` is clean (PHPCS / WordPress Coding Standards).
- [ ] `composer run test` is green. If this touches tables, hooks, or the scheduler, it was run in
      integration mode against WordPress + WooCommerce (see [docs/testing.md](../docs/testing.md)).
- [ ] New or changed behaviour is covered by a test (accessibility, security, and i18n changes
      count as behaviour).
- [ ] Any schema change is a new versioned `dbDelta` migration; no applied migration was edited.
- [ ] All user-facing strings are translated with the `woo-cart-rescue` text domain, and
      `languages/woo-cart-rescue.pot` was regenerated if strings changed.
- [ ] Input is validated/sanitised server-side, output is escaped, and queries use
      `$wpdb->prepare()`.
- [ ] No new runtime dependency was added.
- [ ] Commits are granular and conventionally named, with no emoji.

## Notes for reviewers

Anything that needs manual verification (email rendering, checkout consent UX, the report against
hand-counted rows) or context that helps the review.
