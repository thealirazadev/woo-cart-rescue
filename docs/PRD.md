# Product Requirements: woo-cart-rescue

## What we're building

A WooCommerce plugin that recovers abandoned carts. It records the cart of every logged-in customer
automatically and the cart of a guest only after the guest has entered an email address at checkout
and ticked an explicit consent checkbox. A recurring Action Scheduler job marks a cart abandoned
once it has been idle past a configurable window, then a sequence of up to three recovery emails is
sent on configurable delays. Each email carries an HMAC-signed, expiring, single-use restore link
that rebuilds the customer's cart and lands them on checkout, plus an unsubscribe link that stops
the sequence immediately. The moment the customer places any order, the remaining sequence is
cancelled — including emails already in flight at send time. Orders placed through a restore link
within an attribution window are counted as recovered, and an admin report shows sent, recovered,
and revenue figures. Cart records are anonymized or purged on a retention schedule and integrate
with the WordPress personal-data export and erase tools.

## Target user

WooCommerce store owners and shop managers who lose revenue to abandoned checkouts and want an
automated, self-hosted recovery flow without paying for a SaaS or wiring up an email platform. They
care about GDPR exposure: many sell into the EU and need consent-gated tracking, a documented
retention policy, and working export/erase integration. Secondary beneficiary: the shopper, who
gets a one-click way back to a saved cart and a working unsubscribe on every email.

## Core features (prioritized)

1. Consent-gated cart capture. Logged-in customers are tracked automatically on cart changes.
   Guests are tracked only after they enter an email at checkout and tick an unchecked-by-default
   consent checkbox; no guest record is ever written without consent.
2. Abandonment detection. A recurring Action Scheduler sweep marks a cart abandoned after a
   configurable idle window (default 60 minutes), excluding empty carts, carts without an email,
   and carts whose owner has since placed an order. Any new cart activity returns the cart to
   active and cancels pending sends.
3. Tokenized restore links. Every recovery email contains a link signed with HMAC-SHA256, tied to
   one send record, expiring after a configurable TTL, single-use, and invalidated by order
   completion or unsubscribe. A valid link rebuilds the cart and redirects to checkout.
4. Recovery email sequence. Up to three steps, each with its own enable toggle and delay, rendered
   through WooCommerce email classes with merge tags for customer name, cart contents, cart total,
   restore link, and unsubscribe link. Race-safe: cart state is rechecked at send time, and a
   database-level claim prevents duplicate sends per step.
5. Automatic cancellation and unsubscribe. Placing any order cancels every remaining step for that
   customer's cart, even a step already dispatched to a worker. The unsubscribe link works on every
   step, ignores token expiry, cancels the sequence, and suppresses future sequences to that email.
6. Recovery attribution and admin report. An order placed through a restore link within the
   attribution window (default 7 days) is marked recovered, with the order id and total stored on
   the cart record. A report page shows abandoned carts, emails sent per step, recovered orders,
   recovered revenue, and recovery rate for a date range. Opens are shown as "not tracked".
7. GDPR data lifecycle. A daily job purges stale non-recovered cart data after the retention window
   and anonymizes recovered records (keeping only aggregate figures). Personal-data exporters and
   erasers are registered with the WordPress privacy tools. Uninstall removes all tables and options.

## Non-goals / out of scope

- No SMS, push, or any channel other than email.
- No ESP integrations (Mailchimp, Klaviyo, etc.); email goes through the store's own mailer.
- No A/B testing of subjects, delays, or templates.
- No automatic coupon generation in recovery emails (backlog candidate).
- No open or click tracking pixels. This is a deliberate privacy stance: we do not embed remote
  images or per-recipient beacons, so "opened" is reported as not tracked. Clicks are known only
  when a restore or unsubscribe link is actually used.
- No support for the WooCommerce Blocks (Gutenberg) checkout in v1; the guest consent field targets
  the classic shortcode checkout. Blocks checkout support is a backlog candidate.
- No multi-currency normalization in the report; revenue is summed in the amounts recorded at
  order time, assuming a single store currency.
- No per-product or per-category exclusion rules.
- No frontend cart-saving UI ("email me my cart" widgets); capture is passive.

## Success criteria per core feature

### 1. Consent-gated cart capture
- A logged-in customer who adds an item to the cart gets exactly one row in `wcr_carts` (keyed by
  session), updated in place on every later cart change, with `consent = 1` and their account email.
- A guest who fills the checkout email field but does not tick the consent checkbox produces zero
  rows in any plugin table; nothing about them is stored.
- A guest who ticks consent and enters a valid email gets one row with `consent = 1`; unticking has
  no retroactive effect but the AJAX capture stops firing.
- The capture AJAX rejects a missing/invalid nonce, an invalid email, and `consent != 1`.

### 2. Abandonment detection
- A cart idle past the configured window is set to `abandoned` by the sweep and a step-1 send row
  is scheduled; a cart updated 5 minutes ago is not.
- An empty cart, a cart without an email, and a cart whose customer completed an order after
  capture are never marked abandoned.
- Adding an item to an abandoned cart returns it to `active` and its pending scheduled sends are
  cancelled; going idle again resumes the sequence at the first unsent step, never re-sending step 1.

### 3. Tokenized restore links
- A valid, unexpired, unused token rebuilds the exact saved cart (skipping items no longer
  purchasable, with a notice) and 302-redirects to checkout.
- A token with a tampered payload or signature, an expired token, a reused token, and a token whose
  cart is recovered, completed, unsubscribed, or anonymized are all rejected with one generic
  user-facing message and a detailed log entry; no two failure modes are distinguishable to the user.
- Using a token marks it used; the same URL a second time is rejected.

### 4. Recovery email sequence
- With defaults, an abandoned cart receives step 1 after its delay; steps 2 and 3 follow their
  delays measured from the previous send; disabled steps are skipped.
- Every merge tag renders correct values; a guest with no name renders the neutral fallback.
- Send-time recheck: if the order is placed after the step was scheduled but before the worker
  runs, the worker sends nothing and marks the send `cancelled`.
- Duplicate protection: running the same send job twice (or two workers racing) produces exactly
  one email; the unique `(cart_id, step)` key plus the atomic status claim make the second attempt
  a no-op.

### 5. Automatic cancellation and unsubscribe
- Placing an order (any status that means "order exists") cancels all scheduled sends for the
  matching cart within the same request; no later email arrives.
- The unsubscribe link on any step, even after token expiry, sets the cart to `unsubscribed`,
  cancels pending sends, records the email hash in the opt-out table, and shows a confirmation page.
- After unsubscribing, a new abandoned cart with the same email never gets an email scheduled.

### 6. Recovery attribution and admin report
- An order placed through a restore link within the attribution window marks the cart `recovered`
  and stores `recovered_order_id` and `recovered_total`; the order carries `_wcr_recovered_cart_id`
  meta.
- An order placed after the attribution window, or without a restore click, is not counted.
- The report page shows, for a selectable date range: carts abandoned, emails sent per step,
  recovered order count, recovered revenue (sum of `recovered_total`), recovery rate, and an
  explicit "Opens: not tracked" note. Figures match the table contents exactly.

### 7. GDPR data lifecycle
- The daily cleanup deletes non-recovered carts (and their sends and events) older than the
  retention window, and anonymizes recovered carts (email, user id, and cart contents nulled;
  totals and status kept).
- The WordPress privacy exporter returns the cart records held for a given email; the eraser
  anonymizes them; both are reachable from Tools > Export/Erase Personal Data.
- Uninstalling the plugin drops all four `wcr_*` tables, deletes `wcr_settings`, `wcr_db_version`,
  and `wcr_token_secret`, and unschedules all plugin Action Scheduler hooks.
