# woo-cart-rescue

A WooCommerce plugin that recovers abandoned carts. It tracks carts for logged-in customers and for
guests who give explicit consent at checkout, detects abandonment after a configurable idle window,
sends a scheduled recovery email sequence with signed restore links that rebuild the cart, and
reports recovered orders and revenue in the WooCommerce admin. Consent, data retention, and token
security are first-class requirements, not afterthoughts.

Stack: WordPress plugin in PHP 8.1+, WooCommerce hooks and email classes, Action Scheduler for all
timing, custom database tables with versioned migrations, vanilla JS for the checkout capture
script, PHPUnit and PHPCS (WordPress Coding Standards) for quality gates.

Status: v1.0.0 implemented (phases 1-5 complete).

## Install

1. Copy this folder to `wp-content/plugins/woo-cart-rescue` on a site with WooCommerce active.
2. Run `composer install` for the development tooling (not required at runtime).
3. Activate the plugin. Activation is blocked with an admin notice if WooCommerce is not active.
4. Configure it under WooCommerce > Cart Rescue, and edit the per-step email subject and heading
   under WooCommerce > Settings > Emails.

## Run

Local development uses `@wordpress/env` (Docker):

```
npx wp-env start          # local WordPress + WooCommerce
```

Set the idle window and step delays to 1-2 minutes and run due actions from
Tools > Scheduled Actions to exercise the timing flows quickly. Outbound mail in the dev site goes
to a local mail catcher (for example Mailpit).

## Test

```
composer install         # dev tooling (exact-pinned, lockfile committed)
composer run lint        # PHPCS against phpcs.xml.dist (WordPress Coding Standards)
composer run test        # PHPUnit
```

The test suite runs in two modes. With the WordPress test suite installed (via
`bin/install-wp-tests.sh` or `wp-env`), the full integration tests run against WordPress and
WooCommerce. Without it, a unit subset (token signing, settings sanitization, merge-tag rendering)
runs with lightweight stubs; the integration test files no-op so the command still passes.
