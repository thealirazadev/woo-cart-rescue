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

Outbound mail in the dev site goes to a local mail catcher (Mailpit) or a mail-logging dev
plugin — dev environment only, never a dependency of the shipped plugin.

```
composer install          # dev tooling (exact-pinned, lockfile committed)
composer run lint         # PHPCS against phpcs.xml.dist
composer run lint:fix     # PHPCBF autofixes
composer run test         # PHPUnit (unit mode, or integration mode when WP_TESTS_DIR is set)
```

`tests/bootstrap.php` picks the mode: with no `WP_TESTS_DIR` (or nothing installed there) it loads
stubs and only the pure-PHP tests run, because every integration class early-returns when
`WP_UnitTestCase` is absent. Point `WP_TESTS_DIR` at a WordPress core test library and the whole
suite runs. A `--filter` pass on the current test class is fine during development, but the full
suite runs before any commit.

## Provisioning the WordPress test environment

`@wordpress/env` provisions this, but it is not required and nothing in the suite depends on it.
The suite needs exactly three things — a WordPress core checkout, the WordPress core test library,
and a MySQL/MariaDB server — and they can be installed directly, which is what CI does:

```bash
WP_VERSION=6.8.2
WC_VERSION=10.6.2
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WCR_TESTS_WOOCOMMERCE=/tmp/woocommerce/woocommerce/woocommerce.php

# 1. A database. Any MySQL/MariaDB reachable over TCP will do, for example:
docker run -d --name wcr-test-db -e MARIADB_ROOT_PASSWORD=password -p 3306:3306 mariadb:11.4
docker exec wcr-test-db mariadb -uroot -ppassword -e 'CREATE DATABASE wordpress_test'

# 2. WordPress core (what ABSPATH points at).
curl -sSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz
mkdir -p /tmp/wordpress && tar --strip-components=1 -xzf /tmp/wordpress.tar.gz -C /tmp/wordpress

# 3. The core test library (includes/ + data/ + a config), from wordpress-develop.
curl -sSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" \
  -o /tmp/wordpress-develop.tar.gz
tar -xzf /tmp/wordpress-develop.tar.gz -C /tmp
mkdir -p "$WP_TESTS_DIR"
cp -r "/tmp/wordpress-develop-${WP_VERSION}/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
cp -r "/tmp/wordpress-develop-${WP_VERSION}/tests/phpunit/data" "$WP_TESTS_DIR/data"
cp "/tmp/wordpress-develop-${WP_VERSION}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s#dirname( __FILE__ ) . '/src/'#'/tmp/wordpress/'#; s#__DIR__ . '/src/'#'/tmp/wordpress/'#" \
  "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/wordpress_test/; s/yourusernamehere/root/; \
  s/yourpasswordhere/password/; s/localhost/127.0.0.1/" "$WP_TESTS_DIR/wp-tests-config.php"

# 4. WooCommerce (which bundles Action Scheduler).
curl -sSL "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" -o /tmp/woocommerce.zip
unzip -q /tmp/woocommerce.zip -d /tmp/woocommerce

# 5. Run everything.
composer run test
```

Notes:

- `WCR_TESTS_WOOCOMMERCE` is the path to `woocommerce.php` itself, not to its directory. Without it
  the bootstrap looks in `/tmp/wordpress/wp-content/plugins/woocommerce/woocommerce.php`.
- The pinned pair is WordPress 6.8.2 with WooCommerce 10.6.2, the newest WooCommerce that still
  declares support for WordPress 6.8. CI pins the same pair so a local failure is reproducible.
- Fixtures use the public WooCommerce CRUD API, never `WC_Helper_*`: those helpers live in
  WooCommerce's own test framework, which released WooCommerce packages do not ship.
- The database is dropped and recreated by the test suite on each run; never point it at real data.

Useful manual levers while testing timing: set the idle window and step delays to 1-2 minutes in
settings, and run due actions immediately from Tools > Scheduled Actions instead of waiting.

## The rule

After creating or editing files, run the build and tests (`composer run lint` and
`composer run test`) and fix all errors BEFORE reporting done. A phase is not complete while
either command fails or while the WooCommerce log (source `woo-cart-rescue`) shows unexpected
warnings from the manual checklist.
