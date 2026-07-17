# woo-cart-rescue

A WooCommerce plugin that recovers abandoned carts. It tracks carts for logged-in customers and for
guests who give explicit consent at checkout, detects abandonment after a configurable idle window,
sends a scheduled recovery email sequence with signed restore links that rebuild the cart, and
reports recovered orders and revenue in the WooCommerce admin. Consent, data retention, and token
security are first-class requirements, not afterthoughts.

Planned stack: WordPress plugin in PHP, WooCommerce hooks and email classes, Action Scheduler for
all timing, custom database tables with versioned migrations, vanilla JS for the checkout capture
script, PHPUnit and PHPCS (WordPress Coding Standards) for quality gates.

Status: planning — docs under review

## Install

TBD until implementation starts.

## Run

TBD until implementation starts.

## Test

TBD until implementation starts.
