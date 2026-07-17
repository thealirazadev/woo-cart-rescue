# Engineering Rules: woo-cart-rescue

These rules are binding for every change to this plugin. When something here conflicts with a quick
shortcut, follow these rules.

## Conventions

- Coding standard: WordPress Coding Standards (WPCS). Run PHPCS before every commit; code must be
  clean against `phpcs.xml.dist`.
- Preferred libraries/patterns:
  - WordPress/WooCommerce core APIs only: `$wpdb` with `prepare()` for the custom tables,
    `dbDelta` for schema, `WC_Email` for mail, `wc_get_logger()` for logging, Action Scheduler
    (`as_schedule_single_action`, `as_schedule_recurring_action`, `as_unschedule_all_actions`)
    for every timed behavior, the Settings API for the settings tab, and the `sanitize_*` /
    `esc_*` / `wp_kses` families.
  - Timing goes through Action Scheduler exclusively. Never `wp_schedule_event`, never `sleep`,
    never ad-hoc cron.
  - Crypto: `random_bytes` for secrets/nonces, `hash_hmac('sha256', ...)` for signatures,
    `hash_equals` for every comparison. Never `==`/`===` on signatures, never `md5`/`sha1`,
    never `rand`/`mt_rand` for anything secret.
  - Orders only via the CRUD API (`wc_get_order`, `$order->get_meta()` etc.) so HPOS keeps working.
- What to avoid:
  - No JS framework or build step for shipped code. Plain JS and CSS only.
  - No raw SQL string interpolation; every query with a variable goes through `$wpdb->prepare`.
  - No direct `$_GET`/`$_POST` reads without unslash + sanitize.
  - No plaintext tokens at rest (store `sha256(token)` only); no personal data in `wcr_events.meta`
    or log lines (ids and hashes only, never email addresses or names).
  - No remote assets in emails, no tracking pixels (documented privacy stance).
  - No global functions, hooks, or options without the `wcr_` prefix.
- Naming:
  - Text domain: `woo-cart-rescue` (matches the plugin slug and folder).
  - Function/hook prefix: `wcr_`; Action Scheduler hooks `wcr_abandonment_sweep`,
    `wcr_send_step`, `wcr_retention_cleanup`; group `woo-cart-rescue`.
  - Constant prefix: `WCR_` (`WCR_VERSION`, `WCR_PATH`, `WCR_URL`).
  - Class prefix `WCR_` in PascalCase, file named `class-wcr-*.php` (e.g. `WCR_Sender` in
    `class-wcr-sender.php`).
  - Tables: `{$wpdb->prefix}wcr_carts`, `wcr_sends`, `wcr_events`, `wcr_optouts`.
  - Options: `wcr_settings`, `wcr_db_version`, `wcr_token_secret`.
  - Query vars: `wcr_action`, `wcr_token`. Session keys: `wcr_recovery_cart_id`,
    `wcr_recovery_send_id`, `wcr_recovery_expires`. Order meta: `_wcr_recovered_cart_id`.
  - AJAX action: `wcr_capture_guest`; nonce action `wcr_capture`.
  - Email ids: `wcr_recovery_step_1`, `wcr_recovery_step_2`, `wcr_recovery_step_3`.
  - Files lowercase-hyphenated; variables/functions `snake_case` per WPCS.
- Commits: Conventional Commits (`feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `perf`) with a
  short imperative subject (e.g. `feat: mark idle carts abandoned in sweep job`).
- ONE COMMIT PER FEATURE / TASK. Never batch multiple features into one commit. Each commit listed
  in `docs/phases.md` maps to exactly one commit.
- Pin exact dependency versions in `composer.json` (no `^`/`~` ranges), commit `composer.lock`, and
  declare `Requires at least`, `Requires PHP`, and `WC requires at least` in the plugin header and
  `readme.txt`. No blanket upgrades without approval.
- Database migrations: never modify the schema directly. Every schema change is a new numbered
  migration method in `WCR_Install` (`migrate_1()`, `migrate_2()`, ...), applied in order by an
  idempotent runner guarded by the `wcr_db_version` option. Applied migrations are never edited
  afterward — a fix is a new migration. `dbDelta` only; no ad-hoc `ALTER TABLE` outside migrations.

## Error handling & logging

- Every boundary call handles failure: `$wpdb` insert/update/query results are checked (`false`
  means failure — log and bail, never assume the row exists), `wp_mail`/`WC_Email::trigger`
  outcomes set the send row to `sent` or `failed`, `as_schedule_*` return values are checked,
  `json_decode` of stored cart contents is null-checked, and WooCommerce functions are
  existence-checked at bootstrap.
- Friendly user errors vs detailed logs: shoppers hitting a bad restore link get exactly one
  generic notice ("This link is no longer valid.") regardless of the real reason; the real reason
  (bad signature, expired, reused, wrong state) goes to the log with ids. Never surface stack
  traces, SQL, token contents, or table names to any user.
- One error format everywhere:
  - Internal: `wcr_log( $level, $message, $context = array() )` wrapping
    `wc_get_logger()->log( $level, $message, array( 'source' => 'woo-cart-rescue' ) + $context )`.
    Context is an array of scalars (cart_id, send_id, step, code) — never personal data.
  - AJAX JSON: `wp_send_json_error( array( 'code' => ..., 'message' => ... ), $status )` and
    `wp_send_json_success( array( ... ) )`, shapes fixed in docs/api-contracts.md.
  - Frontend GET endpoints: redirect + `wc_add_notice( ..., 'error' )` or the generic template;
    never JSON, never a white-screen `wp_die`.
- Structured logging from day one: `wcr_log` lands in Phase 1 and is used at every failure branch
  (guard failures, failed upserts, sweep query errors, send failures, token rejections with reason
  codes, cleanup counts).

## Security

- No hardcoded secrets. The only secret is `wcr_token_secret`, generated with
  `random_bytes(32)` at activation and stored as a non-autoloaded option. No `.env` exists; the
  repo contains no credentials.
- Tokens: HMAC-SHA256 signed, `hash_equals` verified, expiring, single-use, tied to one send row
  via stored `sha256(token)`, and invalidated by cart state (order placed, unsubscribed,
  anonymized). Full format and validation order in docs/api-contracts.md. All failure paths return
  the same user-facing result (no oracle distinguishing "expired" from "invalid").
- Nonce + capability checks: the guest capture AJAX verifies the `wcr_capture` nonce; the settings
  tab goes through the Settings API with `manage_woocommerce`; the report page requires
  `manage_woocommerce`. Privacy exporter/eraser run under core's own authorization.
- Consent is enforced server-side: the capture endpoint rejects any request where
  `consent !== '1'`; no guest row is written on the client's word alone, and the checkbox default
  is unchecked. Opted-out emails (checked via hash against `wcr_optouts`) are silently skipped —
  the endpoint response does not reveal opt-out status.
- Validate all input server-side: `is_email` on captured addresses, integer casts on ids, enum
  whitelists on statuses and settings values (idle window, delays, TTL, retention all cast to
  bounded integers), cart contents rebuilt only from product/variation ids that still exist and
  are purchasable.
- Escape on output: `esc_html` for text, `esc_attr` for attributes, `esc_url` for links
  (restore/unsubscribe URLs included), `wp_kses_post` for owner-authored email body content.
  Report figures are numbers formatted with `wc_price`/`number_format_i18n`.
- Parameterized queries only: every `$wpdb` call with input uses `prepare()`. Table names come from
  a single helper, never string-built from input.
- Protected surface summary: settings + report = `manage_woocommerce`; capture AJAX = public with
  nonce + server-side consent validation; restore/unsubscribe = token-authenticated public GET;
  everything else runs in cron/Action Scheduler context with no user input.

## Simplicity (YAGNI / KISS)

- Write the minimum code that satisfies the current phase. Prefer core helpers over new code.
- Rule of three: no abstraction until the same logic exists three times. One `WC_Email` class
  registered three times beats three near-identical classes.
- The class list is fixed: `WCR_Plugin`, `WCR_Install`, `WCR_Capture`, `WCR_Abandonment`,
  `WCR_Sender`, `WCR_Email_Recovery`, `WCR_Token`, `WCR_Endpoints`, `WCR_Orders`, `WCR_Privacy`,
  `WCR_Admin`. No new wrapper/factory/manager/utils class without owner approval recorded in
  `docs/memory.md`.
- No settings, flags, filters, or config that no shipped feature exercises. Defaults live in one
  place (`wcr_get_settings`).
- Sane caps, documented in code: cart JSON capped at 100 line items; merge-tag item table renders
  at most 20 items plus "and N more"; sweep processes batches of 200 carts per run.
- Before submitting, self-review: can this be done in fewer lines without hurting readability?
  If a function approaches ~150 lines, stop and split or justify in the PR description.
- Use what exists: Action Scheduler for timing, WooCommerce email plumbing for mail, core privacy
  hooks for export/erase. Never reimplement them.

## Code style

- Sparse, human comments that explain "why", not "what". No commented-out code.
- Concise docstrings: short WPCS-style DocBlocks with `@param`/`@return` where they add value.
- No emoji anywhere in code, comments, docs, or commits.
- No AI/authorship mentions anywhere — no "generated by", no co-author trailers, nothing of the
  kind in code, commits, or docs.
- Conventional Commits for every commit (see Conventions).

## Boundaries

- No wholesale file delete or rewrite. Targeted, reviewable edits only; flag destructive changes
  first.
- Never change `docs/PRD.md` or `docs/architecture.md` without flagging it first and recording the
  reason in `docs/memory.md` — those are the source of truth.
- No new runtime or dev dependency without explicit approval recorded in `docs/memory.md`.
- Ask when ambiguous rather than guessing at product behavior — especially anything touching
  consent, retention, or token semantics.
- Stop after 2 failed fix attempts on the same problem and report what was tried instead of
  continuing to churn.
- Mid-phase requests not in docs/PRD.md: ask whether to (a) add to the current phase, (b) create a
  new phase, or (c) log to the Backlog section in docs/phases.md. Never silently absorb scope.
