=== WooCommerce Cart Rescue ===
Contributors: thealirazadev
Tags: woocommerce, abandoned cart, cart recovery, email, gdpr
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 10.9
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/license/mit/

Consent-gated abandoned cart recovery for WooCommerce with signed restore links, a race-safe email sequence, and a GDPR data lifecycle.

== Description ==

WooCommerce Cart Rescue recovers abandoned checkouts without a SaaS or an external email platform. It records the cart of every logged-in customer automatically, and the cart of a guest only after they enter an email at checkout and tick an explicit, unchecked-by-default consent box. A recurring Action Scheduler sweep marks a cart abandoned after a configurable idle window, then sends up to three recovery emails on configurable delays through the store's own WooCommerce mailer.

Each email carries an HMAC-signed, expiring, single-use restore link that rebuilds the cart and lands the shopper on checkout, plus an unsubscribe link that stops the sequence immediately. The moment an order is placed the remaining sequence is cancelled, including a step already dispatched to a worker. Orders placed through a restore link within an attribution window are counted as recovered, and an admin report shows carts abandoned, emails sent per step, recovered orders, recovered revenue, and recovery rate.

Privacy is a first-class concern: no open or click tracking pixels, a documented retention schedule that purges or anonymizes stale cart data, and full integration with the WordPress personal-data export and erase tools.

= Features =

* Consent-gated capture: logged-in customers tracked automatically; guests only after email plus explicit consent.
* Abandonment detection via Action Scheduler with a configurable idle window.
* Up to three recovery email steps, each with its own enable toggle, delay, subject, and heading.
* HMAC-SHA256 signed restore links: expiring, single-use, and invalidated by order completion or unsubscribe.
* Automatic cancellation on order placement, race-safe against in-flight sends.
* Recovery attribution and an admin report with recovered revenue.
* GDPR data lifecycle: retention cleanup, anonymization, exporter and eraser, clean uninstall.
* HPOS (custom order tables) compatible.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/woo-cart-rescue` directory, or install it through the Plugins screen.
2. Activate the plugin. WooCommerce must be active; otherwise activation is blocked with a notice.
3. Go to WooCommerce > Cart Rescue to configure the idle window, retention, consent label, and the email steps.
4. Edit each step's subject and heading under WooCommerce > Settings > Emails.

== Frequently Asked Questions ==

= Does it track email opens? =

No. There are no tracking pixels or remote images. "Opens" is reported as not tracked. Clicks are known only when a restore or unsubscribe link is actually used.

= Are guest carts tracked without consent? =

Never. A guest cart is recorded only after the guest enters a valid email at checkout and ticks the consent box, which is unchecked by default.

= Does it work with the block checkout? =

Version 1 targets the classic shortcode checkout for the guest consent field. Block checkout support is planned.

== Changelog ==

= 1.0.0 =
* Initial release: consent-gated capture, abandonment detection, three-step recovery sequence, signed restore and unsubscribe links, recovery attribution and reporting, and the GDPR data lifecycle.
