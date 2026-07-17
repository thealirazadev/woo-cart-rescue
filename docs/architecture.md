# Architecture: woo-cart-rescue

## App flow and architecture

A self-contained WordPress plugin with no external services. All behavior hangs off WooCommerce
hooks, Action Scheduler jobs, and two token-authenticated frontend endpoints. One main plugin file
bootstraps an orchestrator (`WCR_Plugin`) that wires the components below.

1. Bootstrap (`woo-cart-rescue.php`): defines constants, verifies WooCommerce (which bundles
   Action Scheduler) is active, loads the text domain, requires `includes/`, runs pending
   migrations, and instantiates `WCR_Plugin` on `plugins_loaded`.
2. Capture (`WCR_Capture`): upserts a row in `wcr_carts` on cart activity. Logged-in customers are
   captured on `woocommerce_cart_updated` (email from the account). Guests are captured only via
   the checkout consent flow: a consent checkbox is added to checkout fields, and
   `assets/js/checkout-capture.js` posts email + consent to an AJAX endpoint when both are present.
   Checkout page loads also refresh `last_activity_at`, so a shopper mid-checkout is never swept.
3. Abandonment (`WCR_Abandonment`): a recurring Action Scheduler action `wcr_abandonment_sweep`
   (every 15 minutes, group `woo-cart-rescue`) marks eligible idle carts `abandoned`, creates the
   step-1 row in `wcr_sends`, and schedules a single `wcr_send_step` action for it.
4. Sending (`WCR_Sender` + `WCR_Email_Recovery`): the `wcr_send_step` handler is race-safe (see
   below), renders the step through a `WC_Email` subclass with merge tags, and on success chains
   the next enabled step (new send row + scheduled action, delay measured from this send).
5. Endpoints (`WCR_Endpoints` + `WCR_Token`): `template_redirect` handles
   `?wcr_action=restore&wcr_token=...` and `?wcr_action=unsubscribe&wcr_token=...`. Restore
   validates the token, rebuilds the cart, sets attribution session keys, and redirects to
   checkout. Unsubscribe verifies the signature only, cancels the sequence, and records an opt-out.
6. Orders (`WCR_Orders`): on `woocommerce_checkout_order_processed`, finds the matching cart row
   (by session key, then user id, then billing email), cancels all pending sends and scheduled
   actions, and — if the attribution session keys are present and unexpired — marks the cart
   `recovered` with the order id and total.
7. Privacy (`WCR_Privacy`): a daily `wcr_retention_cleanup` action purges stale non-recovered data
   and anonymizes recovered rows; registers the personal-data exporter and eraser.
8. Admin (`WCR_Admin`): a WooCommerce submenu page with a Settings tab (Settings API) and a
   server-rendered Report tab. No admin AJAX.

Capture and sequence flow:

```
Cart activity (logged-in)            Checkout email + consent (guest)
  woocommerce_cart_updated             checkout-capture.js -> admin-ajax wcr_capture_guest
        \                                  (nonce + is_email + consent === '1')
         -> WCR_Capture::upsert()  -> wcr_carts row (status active, last_activity_at = now)

wcr_abandonment_sweep (every 15 min)
  -> carts WHERE status = 'active' AND last_activity_at < now - idle_window
     AND email IS NOT NULL AND cart_contents not empty AND no order since capture
     AND email not in wcr_optouts
  -> status = 'abandoned', abandoned_at = now
  -> INSERT wcr_sends (cart_id, step 1, status 'scheduled')   [unique (cart_id, step)]
  -> as_schedule_single_action(abandoned_at + step1_delay, 'wcr_send_step', [send_id])

wcr_send_step (send_id)
  -> reload cart row; if status != 'abandoned' -> mark send 'cancelled', stop   (send-time recheck)
  -> UPDATE wcr_sends SET status='sending' WHERE id=%d AND status='scheduled'
     if 0 rows affected -> another worker owns it, stop                          (atomic claim)
  -> generate token, store sha256(token) + expiry on the send row
  -> WCR_Email_Recovery::trigger(send) -> merge tags -> wp_mail via WC mailer
  -> status='sent', sent_at=now; log event email_sent
  -> chain next enabled step: new send row + as_schedule_single_action(now + delay)
```

Restore and attribution flow:

```
GET /?wcr_action=restore&wcr_token={token}
  -> WCR_Token::validate() (steps in docs/api-contracts.md)
  -> rebuild WC()->cart from stored cart_contents (skip unpurchasable items, add notice)
  -> mark token used; log restore_used; cart status -> 'active', last_activity_at = now
  -> WC session: wcr_recovery_cart_id, wcr_recovery_send_id, wcr_recovery_expires
  -> 302 to wc_get_checkout_url()

woocommerce_checkout_order_processed
  -> cancel pending sends + as_unschedule for the matching cart
  -> if session wcr_recovery_* present and now < wcr_recovery_expires:
       cart status 'recovered', recovered_order_id, recovered_total, recovered_at
       order meta _wcr_recovered_cart_id; event order_recovered
     else: cart status 'completed'; event order_completed
```

Cart status lifecycle: `active -> abandoned` (sweep), `abandoned -> active` (any new activity or a
restore click; pending sends cancelled, sequence later resumes at the first unsent step),
`abandoned|active -> recovered|completed` (order), `abandoned -> unsubscribed`, and the retention
job maps non-recovered stale rows to deleted and recovered stale rows to `anonymized`.

## Proposed folder / file tree

```
woo-cart-rescue/
  woo-cart-rescue.php              Plugin header, constants (WCR_VERSION, WCR_PATH, WCR_URL),
                                   WooCommerce guard, text domain, requires, boot WCR_Plugin
  uninstall.php                    Drops wcr_* tables, deletes options, unschedules actions
  readme.txt                       WordPress.org-style plugin readme
  composer.json                    Dev deps + scripts (lint, lint:fix, test)
  phpcs.xml.dist                   PHPCS ruleset extending WordPress-Extra + WordPress-Docs
  phpunit.xml.dist                 PHPUnit config
  .distignore                      Files excluded from the built zip

  includes/
    class-wcr-plugin.php           Orchestrator; instantiates components; registers hooks
    class-wcr-install.php          Activation, versioned migrations (dbDelta), token secret,
                                   recurring action registration
    class-wcr-capture.php          Logged-in capture, consent checkout field, guest capture AJAX
    class-wcr-abandonment.php      Sweep job; eligibility query; step-1 scheduling; resume logic
    class-wcr-sender.php           wcr_send_step handler: recheck, atomic claim, chain next step
    class-wcr-email-recovery.php   WC_Email subclass (one class, registered once per step),
                                   merge-tag rendering, unsubscribe footer
    class-wcr-token.php            Token format, signing, parsing, validation
    class-wcr-endpoints.php        template_redirect handlers for restore and unsubscribe
    class-wcr-orders.php           Order-placed hook: sequence cancellation + attribution
    class-wcr-privacy.php          Retention cleanup job, personal-data exporter and eraser
    class-wcr-admin.php            Settings tab (Settings API) + server-rendered Report tab
    wcr-functions.php              Data access helpers (wcr_get_cart, wcr_get_settings, ...)
                                   and wcr_log()

  templates/
    emails/recovery.php            HTML email body (WooCommerce template loader, theme-overridable)
    emails/plain/recovery.php      Plain-text email body
    unsubscribe-confirmed.php      Standalone confirmation page (get_header/get_footer)

  assets/
    css/admin.css                  Report cards and settings layout tweaks
    js/checkout-capture.js         Guest email + consent capture on classic checkout

  languages/
    woo-cart-rescue.pot            Translation template

  tests/
    bootstrap.php                  Loads WP test suite + plugin
    test-token.php                 Sign/parse/validate, tamper, expiry, reuse
    test-capture.php               Consent gating, upserts, AJAX validation
    test-abandonment.php           Sweep eligibility, resume-at-unsent-step
    test-sender.php                Send-time recheck, atomic claim, chaining
    test-orders.php                Cancellation, attribution window
    test-privacy.php               Cleanup, exporter, eraser
    test-uninstall.php             Table and option removal

  docs/                            Planning + handoff documentation (this folder)
  README.md                        Root project doc
```

## Tech stack with rationale

Major versions below; exact versions are pinned at install time and the lockfile
(`composer.lock`) is committed.

- WordPress plugin in PHP 8.1+, WordPress 6.4+, WooCommerce 8.x+: the product is a WooCommerce
  extension; a plugin integrating through documented hooks is the only correct delivery form. HPOS
  (custom order tables) compatibility is declared via `FeaturesUtil` since orders are only touched
  through the CRUD API.
- Action Scheduler (bundled with WooCommerce) for all timing: the sweep, every send, and the
  retention cleanup. It survives missed WP-Cron ticks, supports groups for bulk unscheduling, has
  an admin UI for inspection, and adds no dependency. Raw WP-Cron is rejected: it silently drops
  overdue events on low-traffic sites, which is fatal for a timing-sensitive email sequence.
- Custom tables via `dbDelta` with versioned migrations, not postmeta/usermeta: cart records are
  not posts, guests have no user row, the sweep needs an indexed `(status, last_activity_at)`
  query, duplicate-send protection needs a unique `(cart_id, step)` key, and the report needs
  aggregate SUM/COUNT queries. Meta tables offer none of that and would force fake posts and
  unindexed LIKE queries. Trade-off: we own schema migrations; they are versioned through
  `wcr_db_version` and an idempotent upgrade routine (see docs/rules.md).
- WooCommerce email classes (`WC_Email` subclass) rather than bare `wp_mail` with hand-rolled
  templates: recovery emails inherit the store's email styling, from-name/address, and mailer
  (including any SMTP plugin), the subject and heading of each step become owner-editable under
  WooCommerce > Settings > Emails, and the body templates are theme-overridable through the
  standard template loader. Bare `wp_mail` would mean rebuilding all of that. Constraint honored:
  emails are triggered from Action Scheduler context with no session, so templates render solely
  from the stored cart JSON, never from `WC()->cart`.
- Vanilla JS for `checkout-capture.js`: one small script listening to the billing email field and
  consent checkbox on the classic checkout. No framework, no build step.
- Composer + PHPCS/WPCS + PHPUnit with the WP test suite: the conventional quality gates for a
  distributable plugin. Dev-only; nothing ships to production.

## Data model

Four custom tables, all `$wpdb->prefix`-prefixed. Charset/collation from `$wpdb->get_charset_collate()`.

### wcr_carts

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AI PK | |
| cart_key | VARCHAR(64) NOT NULL | WC session customer id; UNIQUE |
| user_id | BIGINT UNSIGNED NULL | null for guests |
| email | VARCHAR(191) NULL | indexed; nulled on anonymization |
| consent | TINYINT(1) NOT NULL DEFAULT 0 | 1 for logged-in and consenting guests |
| cart_contents | LONGTEXT NULL | JSON: product_id, variation_id, variation attrs, qty, line_total |
| cart_total | DECIMAL(19,4) NOT NULL DEFAULT 0 | |
| currency | CHAR(3) NOT NULL | store currency at capture |
| status | VARCHAR(20) NOT NULL DEFAULT 'active' | active, abandoned, recovered, completed, unsubscribed, anonymized |
| recovered_order_id | BIGINT UNSIGNED NULL | |
| recovered_total | DECIMAL(19,4) NULL | order total at attribution time |
| recovered_at | DATETIME NULL | |
| last_activity_at | DATETIME NOT NULL | indexed with status |
| abandoned_at | DATETIME NULL | |
| created_at, updated_at | DATETIME NOT NULL | |

Indexes: UNIQUE `cart_key`; KEY `(status, last_activity_at)`; KEY `email`.

### wcr_sends

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AI PK | |
| cart_id | BIGINT UNSIGNED NOT NULL | |
| step | TINYINT UNSIGNED NOT NULL | 1..3 |
| status | VARCHAR(20) NOT NULL DEFAULT 'scheduled' | scheduled, sending, sent, cancelled, failed |
| token_hash | CHAR(64) NULL | sha256 hex of the full token; the token itself is never stored |
| token_expires_at | DATETIME NULL | |
| token_used_at | DATETIME NULL | single-use marker |
| scheduled_for | DATETIME NOT NULL | |
| sent_at | DATETIME NULL | |
| created_at, updated_at | DATETIME NOT NULL | |

Indexes: UNIQUE `(cart_id, step)` — schema-level duplicate-send protection; KEY `(status, scheduled_for)`.

### wcr_events

Append-only audit/report log.

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AI PK | |
| cart_id | BIGINT UNSIGNED NOT NULL | indexed |
| send_id | BIGINT UNSIGNED NULL | |
| type | VARCHAR(32) NOT NULL | captured, abandoned, email_sent, email_failed, restore_used, unsubscribed, order_completed, order_recovered, anonymized |
| meta | LONGTEXT NULL | JSON detail (never full personal data) |
| created_at | DATETIME NOT NULL | |

Indexes: KEY `(type, created_at)` for report counts; KEY `cart_id`.

### wcr_optouts

Suppression list that survives cart anonymization; stores no plaintext addresses.

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AI PK | |
| email_hash | CHAR(64) NOT NULL | sha256 of lowercased trimmed email; UNIQUE |
| created_at | DATETIME NOT NULL | |

Relationships: one cart has zero-or-more sends (max 3, one per step) and zero-or-more events;
sends and events reference `cart_id` and are deleted with their cart by the cleanup job (enforced
in code, not by FK — MySQL FKs are unreliable across WP hosts). Opt-outs are keyed by email hash,
independent of any cart.

### Token format

`token = "{send_id}.{expires}.{nonce}.{sig}"` — all URL-safe, no encoding step needed.

- `send_id`: integer id of the `wcr_sends` row.
- `expires`: unix timestamp, `sent_at + token TTL` (default 7 days).
- `nonce`: `bin2hex(random_bytes(16))`.
- `sig`: `hash_hmac('sha256', "{send_id}.{expires}.{nonce}", $secret)` in hex.

The secret is 32 bytes from `random_bytes()`, generated on activation and stored in the
`wcr_token_secret` option (autoload off). A dedicated secret, not `wp_salt()`: rotating WordPress
salts for unrelated reasons must not silently kill every outstanding recovery link, and the plugin
can offer its own rotation later. Only `sha256(token)` is stored on the send row, so a database
leak exposes no usable links. Validation steps are specified in docs/api-contracts.md.

## Where state lives

- Persistent state: the four `wcr_*` tables (source of truth), the options `wcr_settings`
  (all configuration: enabled flag, idle window, per-step enable/delay, token TTL, attribution
  window, retention days, consent label), `wcr_db_version`, `wcr_token_secret`, and per-step
  subject/heading in WooCommerce's standard email settings options.
- Scheduled state: pending actions in Action Scheduler's own tables, group `woo-cart-rescue`
  (`wcr_abandonment_sweep` recurring, `wcr_send_step` singles, `wcr_retention_cleanup` daily).
  Always reconciled against `wcr_sends.status` at run time — the send row, not the scheduled
  action, is authoritative.
- Session state: WC session keys `wcr_recovery_cart_id`, `wcr_recovery_send_id`,
  `wcr_recovery_expires` set by the restore endpoint, read once at order placement.
- Order state: `_wcr_recovered_cart_id` meta on recovered orders (audit trail).
- Client state: none persisted. `checkout-capture.js` holds only transient DOM state.

## External dependencies and required env vars

- Runtime: WooCommerce active (checked at bootstrap; admin notice and no-op otherwise). Action
  Scheduler arrives bundled with WooCommerce. Nothing else — no external APIs, no remote calls.
- Dev (Composer, exact-pinned): `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`,
  `phpcompatibility/phpcompatibility-wp`, `phpunit/phpunit`, `yoast/phpunit-polyfills`.
- Environment variables: none, so no `.env` / `.env.example` ships with this project. The only
  secret is the token signing key, which is generated on the target site at activation and stored
  in an option — it must be per-site, so a dotenv file would be wrong. Local email testing uses a
  mail catcher in the dev environment (see docs/testing.md), not credentials in the repo.
