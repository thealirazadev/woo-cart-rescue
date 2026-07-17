# Testing: woo-cart-rescue

## Strategy

- Unit tests (pure PHP, no WordPress load): `WCR_Token` (sign, parse, verify, tamper, expiry
  boundary), merge-tag substitution, settings sanitization/clamping. Fast, run on every change.
- Integration tests (WP test suite + WooCommerce loaded): everything that touches the tables,
  hooks, or Action Scheduler —
  - capture: consent gating (guest without consent writes nothing), upsert-by-session-key,
    AJAX nonce/email/consent rejection;
  - abandonment: sweep eligibility rules, activity resets to active and cancels sends,
    resume-at-first-unsent-step;
  - sending: send-time state recheck (place order between schedule and run, assert no send and
    status `cancelled`), atomic claim (run handler twice, assert one send), chaining and skips;
  - endpoints: restore rebuilds the cart, single-use enforcement, generic failure behavior;
    unsubscribe cancels, records the opt-out hash, is idempotent;
  - orders: cancellation on placement, attribution inside/outside the window, order meta;
  - privacy: retention purge vs anonymization, exporter output, eraser effect;
  - uninstall: tables, options, and scheduled actions removed.
- Manual QA (cannot be automated meaningfully): real email rendering in the mail catcher (HTML and
  plain text), checkout consent UX on the classic checkout with a real theme, the report against
  hand-counted table rows, and the full happy path clicked end to end.

## Environment and commands

Local WordPress + WooCommerce runs under `@wordpress/env` (Docker). Outbound mail in the dev site
goes to a local mail catcher (Mailpit) or a mail-logging dev plugin — dev environment only, never
a dependency of the shipped plugin.

```
composer install          # dev tooling (exact-pinned, lockfile committed)
npx wp-env start          # local WP + WooCommerce
composer run lint         # PHPCS against phpcs.xml.dist
composer run lint:fix     # PHPCBF autofixes
composer run test         # PHPUnit (unit + WP integration suites)
```

`composer run test` must configure the WP test suite via `tests/bootstrap.php`; a `--filter` pass
on the current test class is fine during development, but the full suite runs before any commit.

Useful manual levers while testing timing: set the idle window and step delays to 1-2 minutes in
settings, and run due actions immediately from Tools > Scheduled Actions instead of waiting.

## The rule

After creating or editing files, run the build and tests (`composer run lint` and
`composer run test`) and fix all errors BEFORE reporting done. A phase is not complete while
either command fails or while the WooCommerce log (source `woo-cart-rescue`) shows unexpected
warnings from the manual checklist.
