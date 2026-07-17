# Phases: woo-cart-rescue

Phase N+1 does not start until the owner approves phase N. Phases are ordered so each ships
something useful and testable on its own. One commit per feature/task, in the listed order.

The senior differentiators are hard requirements placed early: consent gating lands in Phase 1;
token security, race-safe sending, and the data lifecycle land in Phase 2. None of them are
stretch goals.

---

## Phase 1: Foundation, schema, and consent-gated capture

Bootstrap the plugin, create the schema through the migration runner, and capture carts — with the
consent rule enforced from the first line: no guest row without consent.

### Goal

A store owner can activate the plugin, and the `wcr_carts` table fills with logged-in customer
carts automatically and guest carts only after email + consent at checkout. Nothing is emailed yet.

### Definition of done

- Plugin activates only when WooCommerce is active; otherwise a clear admin notice, no fatal.
- Activation runs `migrate_1()` (all four tables via `dbDelta`), sets `wcr_db_version`, generates
  `wcr_token_secret`, and registers the recurring sweep and daily cleanup actions (handlers may be
  no-ops until Phase 2).
- `wcr_log` exists, wraps `wc_get_logger()`, and is used at every failure branch.
- Logged-in cart activity upserts one `wcr_carts` row per session key with email, contents JSON,
  total, currency, `consent = 1`, and fresh `last_activity_at`.
- The classic checkout shows an unchecked consent checkbox near the email field;
  `checkout-capture.js` posts email + consent via AJAX only when both are present.
- The AJAX handler verifies the nonce, validates the email, and rejects `consent !== '1'`; a
  consenting guest gets one row with `consent = 1`; a non-consenting guest produces zero rows.
- Checkout page loads refresh `last_activity_at` for the current cart row.
- Settings page (WooCommerce submenu, `manage_woocommerce`) with: enable toggle, idle window
  minutes, retention days, consent checkbox label. Values validated and bounded server-side.

### Manual test checklist

- Activate with WooCommerce off: notice shown, no fatal, no tables created.
- Activate with WooCommerce on: four `wcr_*` tables exist; `wcr_db_version` and `wcr_token_secret`
  options set; sweep and cleanup actions visible under Tools > Scheduled Actions.
- Log in as a customer, add an item: one row in `wcr_carts` with your email; change quantity:
  same row updated, `last_activity_at` fresh.
- As a guest, go to checkout, type an email, leave consent unchecked: no row appears.
- Tick consent: a row appears with `consent = 1`; open the row and confirm contents JSON matches
  the cart.
- Submit the capture AJAX with a wrong nonce (browser devtools): JSON error, no row.
- Change the idle window to 0 and to 100000 in settings: value is clamped to the documented bounds.

### Commits

- `chore: scaffold plugin bootstrap, header, and constants`
- `feat: block activation without WooCommerce and add admin notice`
- `chore: add structured wcr_log helper backed by the WooCommerce logger`
- `feat: add versioned migration runner and initial schema migration`
- `feat: generate and store token signing secret on activation`
- `feat: register recurring sweep and daily cleanup actions on activation`
- `feat: capture logged-in customer carts on cart updates`
- `feat: add consent checkbox to classic checkout`
- `feat: capture consenting guest carts over nonce-checked AJAX`
- `feat: refresh cart activity on checkout page load`
- `feat: add settings page with enable, idle window, retention, and consent label`
- `test: cover consent gating, capture upserts, and AJAX validation`

---

## Phase 2: Abandonment, step-1 email, secure restore, and data lifecycle

The core loop: detect abandonment, send the first recovery email with a signed restore link and a
working unsubscribe, cancel on order placement even mid-flight, and run the retention lifecycle.

### Goal

An idle cart gets one recovery email whose restore link rebuilds the cart at checkout; placing an
order or unsubscribing stops everything; stale data is cleaned up; privacy tools work.

### Definition of done

- The sweep marks eligible carts `abandoned` (idle past window, email present, contents non-empty,
  no order since capture, email not opted out), creates the step-1 send row, and schedules
  `wcr_send_step`. New activity on an abandoned cart returns it to `active` and cancels pending
  sends; a later sweep resumes at the first unsent step.
- `WCR_Token` implements the documented format: `send_id.expires.nonce.sig` with HMAC-SHA256,
  `hash_equals` verification, TTL from settings, and only `sha256(token)` stored.
- The send handler is race-safe: it reloads the cart and cancels if status is not `abandoned`
  (send-time recheck), and claims the row via
  `UPDATE ... SET status='sending' WHERE id=%d AND status='scheduled'`, aborting on zero affected
  rows. The unique `(cart_id, step)` key backs this at the schema level.
- Step 1 sends through `WCR_Email_Recovery` with merge tags `{customer_first_name}` (neutral
  fallback for guests), `{cart_items}`, `{cart_total}`, `{restore_link}`, `{unsubscribe_url}`,
  `{site_title}`; an unsubscribe footer is appended when the template lacks the tag. Send outcome
  recorded as `sent` or `failed` with an event row.
- Restore endpoint: validates per docs/api-contracts.md, rebuilds the cart (skips unpurchasable
  items with a notice), marks the token used, sets the attribution session keys, logs
  `restore_used`, redirects to checkout. Every failure mode shows the same generic notice.
- Unsubscribe endpoint: verifies signature only (expiry and reuse ignored), sets the cart
  `unsubscribed`, cancels pending sends and actions, inserts the email hash into `wcr_optouts`,
  renders the confirmation template. Idempotent.
- Order placement (`woocommerce_checkout_order_processed`) finds the matching cart, cancels all
  pending sends and scheduled actions, and sets status `completed` (attribution comes in Phase 4).
- Daily cleanup: deletes non-recovered carts (plus their sends and events) past the retention
  window; anonymizes recovered carts (email, user_id, cart_contents nulled). Counts logged.
- Personal-data exporter and eraser registered and returning/erasing cart data by email.

### Manual test checklist

- Set idle window to 2 minutes, add items as a consenting guest, wait, run the sweep from
  Scheduled Actions: cart flips to `abandoned`, a step-1 send is scheduled.
- Run the send action: email arrives in the mail catcher with correct name, items, total, and two
  links; send row is `sent`.
- Click the restore link in a fresh browser session: cart is rebuilt, you land on checkout.
- Click the same restore link again: generic "no longer valid" notice, cart unchanged.
- Tamper one character of the token: same generic notice; log shows a signature failure.
- Race check: schedule a send, place the order before running the action, then run the action:
  no email, send row `cancelled`.
- Run the same send action twice in quick succession: exactly one email.
- Click unsubscribe on the email (even after expiry — set TTL to 1 minute and wait): confirmation
  page, cart `unsubscribed`, pending sends cancelled; abandon a new cart with the same email:
  no send is ever scheduled.
- Set retention to 0 days and run the cleanup: stale non-recovered rows deleted, recovered rows
  anonymized.
- Tools > Export Personal Data for the guest email: cart data included. Erase: rows anonymized.

### Commits

- `feat: mark idle carts abandoned in sweep with eligibility rules`
- `feat: reset abandoned carts to active on new activity and cancel pending sends`
- `feat: implement signed restore token format and validation`
- `feat: add recovery email class with merge tags and templates`
- `feat: send step one with send-time state recheck and atomic claim`
- `feat: handle restore endpoint to rebuild cart and redirect to checkout`
- `feat: handle unsubscribe endpoint and record hashed opt-out`
- `feat: cancel pending sequence when an order is placed`
- `feat: add daily retention cleanup with purge and anonymization`
- `feat: register personal data exporter and eraser`
- `test: cover token validation, race-safe sending, unsubscribe, and cleanup`

---

## Phase 3: Full three-step sequence

Extend from one email to the configurable sequence.

### Goal

Owners configure up to three steps with per-step enable and delay; steps chain from the previous
send; cancellation and duplicate protection hold across all steps.

### Definition of done

- Settings gain per-step enable toggles and delays (step 1 from abandonment; steps 2 and 3 from
  the previous send), validated and bounded.
- A successful send chains the next enabled step: new send row + scheduled action. Disabled steps
  are skipped; after the last enabled step the sequence ends.
- Per-step subject and heading are editable under WooCommerce > Settings > Emails (three
  registrations of the recovery email class, ids `wcr_recovery_step_1..3`).
- Order placement and unsubscribe cancel all remaining steps regardless of which step is pending.
- The `(cart_id, step)` unique key prevents re-sending an already-sent step when a cart re-abandons.

### Manual test checklist

- Enable all three steps with short delays; abandon a cart; receive three emails at the right
  intervals, each with a distinct working restore link.
- Disable step 2 only: step 3 arrives after its delay measured from step 1.
- Place the order between step 2 and step 3: step 3 never arrives; its send row is `cancelled`.
- Unsubscribe from step 2: step 3 never arrives.
- Re-abandon a cart that already got steps 1 and 2 (add activity, wait): sequence resumes at
  step 3, steps 1 and 2 are not repeated.
- Edit step 2's subject in WooCommerce email settings: next step-2 email uses it.

### Commits

- `feat: add per-step enable and delay settings`
- `feat: chain next enabled step after a successful send`
- `feat: register three recovery email steps with editable subject and heading`
- `feat: resume interrupted sequences at first unsent step`
- `test: cover multi-step chaining, skips, and cancellation across steps`

---

## Phase 4: Attribution and admin report

Count the money.

### Goal

Orders placed through a restore link within the attribution window are marked recovered, and the
report page shows the funnel and recovered revenue.

### Definition of done

- The restore endpoint's session keys plus the order hook mark the cart `recovered` when the order
  lands inside the attribution window (setting, default 7 days), storing `recovered_order_id`,
  `recovered_total`, `recovered_at`, and order meta `_wcr_recovered_cart_id`; outside the window
  the cart is `completed`.
- Report tab (server-rendered, `manage_woocommerce`): date-range filter (default last 30 days),
  showing carts abandoned, emails sent per step, recovered order count, recovered revenue
  (`wc_price`), recovery rate, and the literal note "Opens: not tracked". Queries hit the indexed
  events and carts tables.
- Empty state: a friendly message when the range has no data, not an empty table.

### Manual test checklist

- Abandon, receive email, click restore, place the order: cart `recovered`; the order in
  WooCommerce admin carries `_wcr_recovered_cart_id`; report revenue increases by the order total.
- Set attribution window to 0 days, click restore, order: cart is `completed`, not counted.
- Place an order with no restore click: not counted as recovered.
- Report with a range containing no data: empty state shown, no notices in the error log.
- Compare report counts against direct table counts for the same range: they match.

### Commits

- `feat: attribute orders placed within the attribution window as recovered`
- `feat: record recovered order id, total, and order meta`
- `feat: add report tab with funnel counts, revenue, and date range`
- `test: cover attribution window edges and report queries`

---

## Phase 5: i18n, uninstall, and distribution hardening

Polish for release.

### Goal

Translation-ready, clean uninstall, HPOS-declared, PHPCS/PHPUnit green, distributable.

### Definition of done

- HPOS compatibility declared via `FeaturesUtil`; plugin behaves with HPOS enabled.
- All user-facing strings (admin, checkout, emails, notices) wrapped with the `woo-cart-rescue`
  text domain; `languages/woo-cart-rescue.pot` generated.
- `uninstall.php` drops the four tables, deletes `wcr_settings`, `wcr_db_version`,
  `wcr_token_secret` and the step email options, and unschedules all `woo-cart-rescue` group
  actions.
- Consent and unsubscribe copy reviewed for plain language; suggested privacy-policy text provided
  via the core `wp_add_privacy_policy_content` hook.
- `readme.txt` complete; PHPCS clean; PHPUnit green; `.distignore` excludes dev files.

### Manual test checklist

- Enable HPOS on the dev site; run the Phase 4 attribution test again: identical behavior.
- Switch the site language with a test translation: checkout checkbox and email strings translate.
- Uninstall the plugin: tables gone, options gone, no `woo-cart-rescue` scheduled actions remain.
- Settings > Privacy > Policy Guide: plugin section present.
- Build the zip honoring `.distignore`, install it on a clean site, activate: no notices.

### Commits

- `feat: declare HPOS compatibility`
- `refactor: wrap all user-facing strings in text domain`
- `chore: generate translation template pot file`
- `feat: add privacy policy suggested text`
- `feat: drop tables, options, and scheduled actions on uninstall`
- `docs: add readme.txt for distribution`
- `test: cover uninstall cleanup`

---

## Phase verification (run after every phase)

- Run the app: activate on the dev WooCommerce site, exercise the touched flows (checkout,
  Scheduled Actions runs, restore/unsubscribe links, admin pages).
- Run tests: `composer run test` passes. Run lint: `composer run lint` is clean.
- Check the browser console, PHP error log, and WooCommerce > Status > Logs
  (source `woo-cart-rescue`) for warnings or notices on the touched screens.
- Unhappy paths:
  - Wrong input: malformed/tampered/expired/reused tokens; invalid email in capture; out-of-range
    settings values; cart JSON referencing a deleted product.
  - Empty forms: checkout with no email; settings saved blank; sweep run with zero eligible carts.
  - No network/mail failure: break the mailer; confirm the send row goes `failed`, is logged, and
    nothing crashes or retries in a loop.
  - Duplicate submissions: double-run a send action; double-click the restore link; unsubscribe
    twice. Each must be a safe no-op the second time.
  - Refresh mid-action: refresh checkout after consent capture; refresh the restore redirect.
- Empty states: report with no data; product page and checkout with the plugin disabled via the
  settings toggle (no output, no capture).
- Long inputs: 100-item cart renders capped in the email; very long product names wrap in the
  email table and the report.

## Backlog

(Empty. Add out-of-scope or deferred items here as they arise.)
