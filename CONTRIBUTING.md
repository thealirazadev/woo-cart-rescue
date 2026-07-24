# Contributing to WooCommerce Cart Rescue

Thanks for helping improve WooCommerce Cart Rescue. This is a WordPress/WooCommerce plugin, so the
workflow is PHP-first: WordPress Coding Standards for style and PHPUnit for behaviour. Please read
this before opening a pull request.

## Ground rules

- By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).
- Report suspected security issues privately; see [SECURITY.md](SECURITY.md). Do not open a public
  issue for a vulnerability.
- Keep commits small and focused: one discrete change per commit (a migration, a helper, an
  endpoint, a template, a test), with a short imperative, conventional message such as
  `fix: handle empty cart`. No emoji in code, comments, or commit messages.
- Custom-table changes ship as a new versioned `dbDelta` migration. Applied migrations are never
  edited afterwards.
- Do not add runtime dependencies. WooCommerce bundles Action Scheduler; the plugin has no other
  runtime requirement.

## Development setup

You need PHP 8.1+ and Composer. Install the dev tooling (exact-pinned, lockfile committed):

```bash
composer install
```

This gives you PHPCS with the WordPress Coding Standards and PHPUnit.

## Running the checks

Every change must pass both gates before it is pushed:

```bash
composer run lint         # PHPCS against phpcs.xml.dist (WordPress-Extra + Docs + PHPCompatibility)
composer run lint:fix     # PHPCBF autofix for the mechanical violations
composer run test         # PHPUnit
```

`composer run test` runs in one of two modes depending on the environment:

- **Unit mode** (no WordPress): the token signer, settings sanitisation, and merge-tag tests run
  against stubs. Every integration test file early-returns when `WP_UnitTestCase` is absent, so the
  suite is green without a database.
- **Integration mode**: point `WP_TESTS_DIR` at a WordPress core test library (and, optionally,
  `WCR_TESTS_WOOCOMMERCE` at a `woocommerce.php`) and the whole suite runs against real WordPress
  and WooCommerce.

## Provisioning the WordPress + WooCommerce test environment

The integration suite needs three things: a WordPress core checkout, the WordPress core test
library, and a MySQL/MariaDB server. The full, copy-pasteable provisioning commands live in
[`docs/testing.md`](docs/testing.md); CI runs exactly the same steps. In short:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WCR_TESTS_WOOCOMMERCE=/tmp/woocommerce/woocommerce/woocommerce.php
composer run test
```

The pinned pair is WordPress 6.8.2 with WooCommerce 10.6.2 (the newest WooCommerce that still
supports WordPress 6.8). CI pins the same pair, so a local failure is reproducible.

Fixtures use the public WooCommerce CRUD API, never `WC_Helper_*` classes: those live in
WooCommerce's own test framework, which released WooCommerce packages do not ship.

## Coding standards

- WordPress Coding Standards are enforced by `phpcs.xml.dist`. Run `composer run lint` and fix every
  reported violation; `composer run lint:fix` handles the mechanical ones.
- All globals (functions, classes, hooks, options) use the `wcr_` / `WCR_` prefix.
- Every external call (database, API, file I/O) handles failure. Log detail through `wcr_log()`;
  never expose a stack trace to a user.
- Validate and sanitise all input server-side, escape all output, and use `$wpdb->prepare()` for
  every query with a variable.
- Wrap every user-facing string for translation with the `woo-cart-rescue` text domain, and
  regenerate `languages/woo-cart-rescue.pot` when you add or change one:

  ```bash
  wp i18n make-pot . languages/woo-cart-rescue.pot --exclude=tests,vendor,bin,node_modules,build,docs
  ```

## Pull request expectations

Before you open a PR:

1. `composer run lint` is clean.
2. `composer run test` is green (integration mode when you touch tables, hooks, or the scheduler).
3. New or changed behaviour has a test. Accessibility, security, and i18n changes count as
   behaviour.
4. Commits are granular and conventionally named; the branch stays in a working state.
5. User-facing strings are translated and the `.pot` is refreshed if you added any.

The pull request template ([`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md))
mirrors this list. CI must be green before review.
