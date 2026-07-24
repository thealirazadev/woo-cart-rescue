# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Contributor guide, code of conduct (Contributor Covenant 2.1), issue and pull request templates,
  and an `.editorconfig` matching the WordPress Coding Standards used here.

### Changed

- Accessibility of the settings and report admin screens: each scalar setting now has a control
  label associated with it and help text tied via `aria-describedby`, and the report summary has an
  accessible name and a screen-reader heading.
- Accessibility of the recovery email: the saved-items table gained a caption and the
  call-to-action layout table is marked `role="presentation"`.

## [1.0.0] - 2026-07-18

### Added

- Consent-gated cart capture: logged-in customers tracked automatically; guests only after entering
  an email and ticking an unchecked-by-default consent box at the classic checkout.
- Abandonment detection via a recurring Action Scheduler sweep with a configurable idle window.
- Up to three recovery email steps, each with its own enable toggle, delay, subject, and heading,
  sent through the store's own WooCommerce mailer.
- HMAC-SHA256 signed restore links: expiring, single-use, and hashed at rest, that rebuild the cart
  and land the shopper on checkout, plus an always-valid unsubscribe link.
- Automatic cancellation of the remaining sequence on order placement, race-safe against a send
  already dispatched to a worker.
- Recovery attribution within a configurable window and an admin report of carts abandoned, emails
  sent per step, recovered orders, recovered revenue, and recovery rate.
- GDPR data lifecycle: retention cleanup that purges or anonymizes stale carts, personal-data
  exporter and eraser, a suggested privacy-policy snippet, and a clean uninstall.
- HPOS (custom order tables) compatibility.

[Unreleased]: https://github.com/thealirazadev/woo-cart-rescue/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/thealirazadev/woo-cart-rescue/releases/tag/v1.0.0
