# API Contracts: woo-cart-rescue

Agreed before any code is written. The plugin exposes two token-authenticated frontend GET
endpoints (query-var based, handled at `template_redirect` — no rewrite rules to flush) and one
AJAX route. The settings tab uses the core Settings API (`options.php`) and the report is
server-rendered; neither adds a custom route.

## Error response formats

- AJAX (JSON): exactly one shape, produced by `wp_send_json_success` / `wp_send_json_error`.

  ```json
  { "success": true,  "data": { } }
  { "success": false, "data": { "code": "string_code", "message": "Friendly sentence." } }
  ```

  `code` is a stable machine string; `message` is translated, user-friendly, and never contains
  internals. HTTP status accompanies the error (400 validation, 403 nonce).

- Frontend GET endpoints: never JSON. Failures produce a 302 redirect with one generic
  `wc_add_notice` error, or the generic template for unsubscribe. All failure reasons are
  deliberately indistinguishable to the visitor; the specific reason code is logged via `wcr_log`.

## 1. Restore link

```
GET {home_url}/?wcr_action=restore&wcr_token={token}
```

- Auth: the token itself. No cookies or login required; works in a fresh browser session.
- Token format: `{send_id}.{expires}.{nonce}.{sig}` (see docs/architecture.md, Token format).

Validation, in order (any failure stops with the generic outcome; reason code in parentheses is
log-only):

1. Parse into four dot-separated parts; `send_id` numeric, `expires` numeric (`malformed`).
2. Recompute `hash_hmac('sha256', "{send_id}.{expires}.{nonce}", secret)`; compare with
   `hash_equals` (`bad_signature`).
3. `expires` must be in the future (`expired`).
4. Load the `wcr_sends` row by `send_id`; must exist and `sha256(token)` must equal its stored
   `token_hash` (`unknown_send`).
5. `token_used_at` must be null (`already_used`).
6. Load the cart row; status must be `abandoned` or `active` (`wrong_state` — covers recovered,
   completed, unsubscribed, anonymized).
7. Cart contents JSON must decode to a non-empty item list (`empty_cart`).

Success behavior:

- Current session cart is replaced with the stored items; items no longer purchasable (deleted,
  out of stock) are skipped and one notice lists them.
- Send row: `token_used_at = now`. Cart row: status `active`, `last_activity_at = now`. Event
  `restore_used` written.
- WC session keys set: `wcr_recovery_cart_id`, `wcr_recovery_send_id`,
  `wcr_recovery_expires = now + attribution_window`.
- Response: `302 Location: {checkout_url}` with a notice "We saved your cart — you can finish
  checking out below."

Failure behavior (all validation failures):

- Response: `302 Location: {cart_url}` with the single notice "This link is no longer valid."
- `wcr_log( 'info', 'restore rejected', { code, send_id } )`. No difference in response between
  failure modes.

## 2. Unsubscribe link

```
GET {home_url}/?wcr_action=unsubscribe&wcr_token={token}
```

- Auth: token signature only. By design, expiry (step 3 above) and single-use (step 5) are NOT
  enforced — an unsubscribe request must always work, even from an old email. Validation runs
  steps 1, 2, 4, then loads the cart.

Success behavior (idempotent — repeating it changes nothing and shows the same page):

- Cart status `unsubscribed`; pending `wcr_sends` rows `cancelled`; matching
  `wcr_send_step` actions unscheduled; `sha256(lowercased email)` inserted into `wcr_optouts`
  (ignore duplicate); event `unsubscribed` written.
- Response: `200`, themed confirmation template (`templates/unsubscribe-confirmed.php`):
  "You will not receive further cart reminder emails from {site_title}."

Failure behavior (malformed token, bad signature, unknown send):

- Response: `200`, same template shell with the generic line "This link is not valid." No redirect,
  no reason detail, logged with code.

## 3. Guest capture (AJAX)

```
POST {admin_url}/admin-ajax.php
Content-Type: application/x-www-form-urlencoded
```

- Registered for `wp_ajax_wcr_capture_guest` and `wp_ajax_nopriv_wcr_capture_guest`.
- Auth: nonce (action `wcr_capture`), printed onto the checkout page via `wp_localize_script`.

Request fields:

| Field | Required | Rule |
| --- | --- | --- |
| `action` | yes | literal `wcr_capture_guest` |
| `nonce` | yes | valid `wcr_capture` nonce |
| `email` | yes | passes `is_email` after unslash + sanitize |
| `consent` | yes | must be the string `1`; anything else is rejected server-side |

Success — `200`:

```json
{ "success": true, "data": { "captured": true } }
```

Note: an opted-out email also returns this exact success body while the server silently skips
writing the row — the endpoint must not act as an oracle for opt-out status.

Errors:

| HTTP | code | When |
| --- | --- | --- |
| 403 | `invalid_nonce` | missing/expired nonce |
| 400 | `invalid_email` | `is_email` fails |
| 400 | `consent_required` | `consent !== "1"` |
| 400 | `disabled` | plugin disabled in settings |

```json
{ "success": false, "data": { "code": "consent_required", "message": "Please tick the consent box to save your cart." } }
```

Side effects on success: upsert into `wcr_carts` keyed by the WC session customer id, with
`consent = 1`, email, current cart contents JSON, total, currency, `last_activity_at = now`;
event `captured` on first insert. No cookies are set by the plugin itself.
