# woo-cart-rescue

[![CI](https://github.com/thealirazadev/woo-cart-rescue/actions/workflows/ci.yml/badge.svg)](https://github.com/thealirazadev/woo-cart-rescue/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/license/mit/)

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

## Design decisions

The trade-offs below are the load-bearing ones. Each records the alternative that was rejected and
why, so the reasoning survives past the original author.

### Consent-gated guest capture

A guest cart is only stored after the shopper enters an email and ticks an unchecked-by-default
consent box at checkout; the browser posts both to an AJAX endpoint that re-validates the nonce,
the email, and `consent === "1"` server-side before writing anything. Logged-in customers are
captured on cart activity under the existing account relationship. Rejected: silently capturing any
email typed into the checkout field. That would store personal data without a lawful basis and is
exactly the pattern this plugin exists to avoid.

### No open or click tracking pixels

Recovery emails carry a signed restore link and an unsubscribe link and nothing else — no tracking
pixel, no click-through redirector. The report surfaces outcomes that matter (carts abandoned,
emails sent per step, recovered orders and revenue, recovery rate) and states plainly that opens are
not tracked. Rejected: an open/click pixel. It leaks the shopper's activity to the store for a
metric that does not change what the sequence does, and it undercuts the plugin's privacy posture.

### Custom tables over postmeta

Cart records live in four `$wpdb`-prefixed tables (`wcr_carts`, `wcr_sends`, `wcr_events`,
`wcr_optouts`) created through `dbDelta` with a versioned migration runner. Carts are not posts,
guests have no user row, the abandonment sweep needs an indexed `(status, last_activity_at)` scan,
duplicate-send protection needs a unique `(cart_id, step)` key, and the report needs aggregate
`SUM`/`COUNT`. Postmeta offers none of that and would force fake posts and unindexed `LIKE` queries.
Trade-off accepted: we own schema migrations, versioned through the `wcr_db_version` option and an
idempotent, resumable upgrade routine.

### Hashed-email opt-out table

An unsubscribe records only `sha256(lowercased, trimmed email)` in `wcr_optouts` — never a plaintext
address. The sweep, the send-time recheck, and guest capture all consult it, so a suppression
outlives the cart it came from: retention purges delete the cart row and anonymization nulls its
email, but the opt-out hash remains and keeps future carts for that address suppressed. Rejected:
storing the opt-out as a flag on the cart row. It would vanish the moment the cart was purged or
anonymized, silently resurrecting a suppressed address.

### Action Scheduler for all timing

The abandonment sweep, every send, the step chaining, and the daily retention cleanup all run as
Action Scheduler actions in a dedicated group (Action Scheduler ships bundled with WooCommerce, so
this adds no dependency). It survives missed cron ticks, supports group-wide unscheduling, and has
an admin UI for inspection. Rejected: raw WP-Cron, which silently drops overdue events on
low-traffic sites — fatal for a timing-sensitive email sequence. The send row's `status`, not the
scheduled action, is always authoritative and is reconciled at run time.

### Restore click resets the cart to active

Clicking a restore link rebuilds the session cart, marks the token used, and returns the cart row to
`active` with a fresh `last_activity_at`. A restored cart is a live shopping session again: if the
shopper completes checkout the order hook attributes and closes it; if they wander off, the sweep
re-abandons it after the idle window and the sequence resumes at the first unsent step rather than
repeating one already delivered. Rejected: leaving the cart `abandoned` after a restore, which would
let the in-flight sequence keep firing against someone actively shopping.

### Token security and race safety

Restore/unsubscribe tokens are `{send_id}.{expires}.{nonce}.{sig}` where `sig` is an HMAC-SHA256 over
the first three parts under a per-site 32-byte secret. Only `sha256(token)` is stored on the send
row, so a database leak exposes no usable link; verification is timing-safe (`hash_equals`), enforces
expiry, and the single-use claim is an atomic `UPDATE ... WHERE token_used_at IS NULL`. The secret is
dedicated rather than a `wp_salt()` derivative so unrelated salt rotation never invalidates every
outstanding link. Sends are race-safe by construction: the worker claims a row with
`UPDATE ... WHERE status = 'scheduled'`, then rechecks cart state as late as possible before
dispatch, and both the order-placed and unsubscribe paths flip the cart status *before* cancelling
sends so an in-flight worker stops itself. Rejected: distributed locks or a queue broker — the
conditional-update pattern is sufficient and adds no infrastructure.
