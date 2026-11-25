# Design decisions

## 1. Guest carts are captured only with explicit consent

Logged-in customers are tracked from `woocommerce_cart_updated` under their existing account
relationship. A guest cart is stored only after the shopper enters an email and ticks a consent box
on the classic checkout that is unchecked by default. The browser posts both to an AJAX endpoint
that re-checks the nonce, `is_email()`, and `consent === '1'` on the server before writing anything,
so a modified script cannot skip the gate. Silently capturing any address typed into the checkout
field was rejected: it would store personal data with no lawful basis.

Both capture paths check the opt-out list before writing. The logged-in path originally skipped
that check; no email ever went out, because the sweep and the send-time recheck both exclude
opted-out addresses, but it still wrote a row for an address that had asked to be left alone. It
now writes nothing for a suppressed address, mirroring the guest path.

## 2. Custom tables with versioned migrations, not postmeta

Carts, sends, events, and opt-outs live in four prefixed tables created with `dbDelta`. Carts are
not posts, guests have no user row, the abandonment sweep needs an indexed
`(status, last_activity_at)` scan, duplicate-send protection needs a unique `(cart_id, step)` key,
and the report needs `SUM` and `COUNT`. Meta tables offer none of that. The cost is owning schema
changes, so `WCR_Install::run_migrations()` applies numbered migrations in order and records
progress in the `wcr_db_version` option. An applied migration is never edited: adding the coupon
column was a new `migrate_2()`, not a change to `migrate_1()`.

## 3. Action Scheduler for every timed job

The 15-minute abandonment sweep, each send, step chaining, and the daily retention cleanup are
Action Scheduler actions in one group. Action Scheduler ships with WooCommerce, so this adds no
dependency; it survives missed cron ticks, supports unscheduling a whole group on uninstall, and has
an admin screen for inspecting runs. Raw WP-Cron was rejected because it silently drops overdue
events on low-traffic sites. The send row's `status` column, not the existence of a scheduled
action, is the source of truth and is re-read when the action runs.

## 4. Sends are race-safe through conditional updates

A worker claims a send with `UPDATE ... SET status = 'sending' WHERE id = %d AND status =
'scheduled'` and proceeds only if a row changed, so running the same action twice mails once. After
the claim it rechecks, as late as possible, that the plugin is enabled, the cart is still
`abandoned`, it still has an email, and that email is not opted out; otherwise the send is marked
`cancelled`. The order-placed and unsubscribe paths change the cart status before they cancel
pending sends, so a worker already past its claim sees the new status in its recheck and stops. A
failed send is marked `failed` and not retried in a loop. Distributed locks or a queue broker were
unnecessary given this pattern.

## 5. Signed, hashed, single-use restore links

A token is `{send_id}.{expires}.{nonce}.{sig}`, where `sig` is HMAC-SHA256 over the first three
parts under a per-site 32-byte secret generated with `random_bytes` and stored with autoload off.
The secret is dedicated rather than derived from `wp_salt()`, so rotating unrelated salts does not
invalidate every link in flight. Only `sha256(token)` is stored on the send row, so a database leak
yields no working link. Verification uses `hash_equals`, enforces expiry, and claims single use with
`UPDATE ... WHERE token_used_at IS NULL`, so two simultaneous clicks cannot both restore.

Every failed restore (malformed, bad signature, expired, unknown, used, wrong cart state, empty
cart) shows the same "This link is no longer valid" notice; the specific reason goes only to the
log.

Unsubscribe uses the same token but checks only the signature and the known send, so it keeps
working from an old email even after the restore link has expired or been used.

## 6. A restored cart becomes active again

Following a restore link rebuilds the session cart (skipping products that are no longer
purchasable, with a notice), applies the send's coupon if there is one, marks the token used, and
returns the cart row to `active` with a fresh `last_activity_at`. Attribution keys go on the
WooCommerce session with an expiry. If the shopper checks out, the order hook attributes the order;
if they leave again, the sweep re-abandons the cart and the sequence resumes at the first enabled
step that has not been sent, rather than repeating one already delivered. Leaving the cart
`abandoned` after a restore would have let the sequence keep mailing someone who was shopping.

An integration test for this resume path originally expected the cart to still have exactly one
send row. That expectation was wrong: resuming correctly adds a step 2 row. The test now asserts
what its name says, that step 1 keeps its single `sent` row and only step 2 is newly scheduled.

## 7. Recovery is attributed only inside a window

When an order is processed for a cart that is still `active` or `abandoned`, the plugin checks the
session for restore keys. If the order follows a restore click for the same cart and is inside the
configurable attribution window (0 to 365 days), the cart is marked `recovered` with the order id,
total, and time, and the order gets `_wcr_recovered_cart_id` meta. Otherwise the cart is simply
`completed`. Either way pending sends are cancelled. Both transitions are conditional updates on the
open statuses, so a cart is never attributed twice.

## 8. Opt-outs are stored as email hashes and outlive the cart

An unsubscribe sets the cart to `unsubscribed`, cancels its pending sends, and inserts
`sha256(lowercased, trimmed email)` into `wcr_optouts` with `INSERT IGNORE` on a unique key, which
makes repeat clicks harmless. No plaintext address is kept. Capture, the sweep, and the send-time
recheck all consult this table, so the suppression survives retention purges and anonymization of
the original cart. A flag on the cart row was rejected because it would disappear with the row and
quietly re-enable mail to that address.

## 9. Retention purges, anonymizes, and keeps the report honest

The daily cleanup deletes carts older than the retention window (with their sends and events) unless
they were recovered. Recovered carts past the window are anonymized instead: email, user id, and
contents are nulled and the status becomes `anonymized`, while the order id and revenue stay. The
report counts recovered orders and revenue by `recovered_order_id IS NOT NULL`, not by status, so
anonymized carts still count. The WordPress personal data exporter returns each cart's status,
total, timestamps, and contents, and the eraser anonymizes every cart for an address.

## 10. No open or click tracking

Recovery emails contain the restore link, the unsubscribe link, and nothing else: no tracking pixel
and no redirecting click tracker. The report shows carts abandoned, emails sent per step, recovered
orders, revenue, and recovery rate, and says plainly that opens are not tracked. A pixel would leak
the shopper's activity for a metric that changes nothing about what the sequence does.

## 11. WooCommerce email classes instead of bare `wp_mail`

Each step is a `WC_Email` subclass registered through `woocommerce_email_classes`, so emails inherit
the store's styling, sender, and mailer (including SMTP plugins), each step's subject and heading
are editable under WooCommerce > Settings > Emails, and templates can be overridden by the theme.
Sends run from Action Scheduler with no session, so templates render only from the stored cart and
send rows. Merge-tag rendering and settings sanitization live as pure functions in
`wcr-functions.php`, which lets the unit suite test them without loading WooCommerce.

## 12. Recovery coupons are opt-in, per send, and single-use

Coupons are off by default. With the feature on and a step's toggle on, the send path creates a new
`WC_Coupon` for that one send: code `wcr-` plus 16 hex characters from `random_bytes`,
`usage_limit = 1`, and an expiry from the validity setting (1 to 365 days). Percentage discounts are
capped at 100. The code is stored on the send row in the same update as the token hash, exposed to
the subject, heading, and body through `{coupon_code}`, and applied automatically on restore; a
missing or expired coupon is skipped without showing a discount error. The retention job deletes
expired generated coupons in batches of 100. Only the final step has its coupon toggle on by
default, because discounting every reminder teaches shoppers to abandon on purpose. A shared static
coupon was rejected: it cannot be single-use or expire per send, and it spreads as soon as one
recipient posts it. Uninstall does not delete generated coupons: unexpired ones are harmless unused
coupons, and deleting posts with raw SQL while WooCommerce may be unloaded is riskier than leaving
them.

## 13. Disabled steps keep their delay

On the settings screen a step's delay input stays enabled when the step is switched off, and the
enable checkbox alone decides whether the step runs. A `disabled` input is not submitted with the
form, so disabling the field would have reset the saved delay on the next save.

## 14. Tests run in two modes against a pinned WordPress and WooCommerce

`tests/bootstrap.php` loads stubs when `WP_TESTS_DIR` is not set; the token, settings, and merge-tag
tests then run, and every integration class returns early because `WP_UnitTestCase` is absent. With
a WordPress core test library available, the whole suite runs against real WordPress, WooCommerce,
and Action Scheduler. Integration runs are pinned to WordPress 6.8.2 and WooCommerce 10.6.2, the
newest WooCommerce that still supports WordPress 6.8, so a new release cannot turn CI red on its
own. Two fixture problems surfaced when the integration suite first ran for real:

- Product fixtures used `WC_Helper_Product`, which ships only with WooCommerce's own test framework
  and never in a released package, so those tests could only skip. Fixtures now use the public CRUD
  API.
- The uninstall test checked that tables were dropped, but the plugin creates its tables while the
  bootstrap loads it, before `WP_UnitTestCase` installs the query filters that turn `CREATE` and
  `DROP TABLE` into temporary-table statements. `uninstall.php` was dropping a temporary table that
  never existed. The test removes those two filters and recreates the schema in `tear_down`.

PHPCS enforces the `wcr` / `WCR` prefix on all globals; only the sub-sniff that flags a three
character prefix as too short is excluded.
