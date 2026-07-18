<?php
/**
 * Recovery email plain-text body.
 *
 * @package Woo_Cart_Rescue
 *
 * @var string $email_heading   Heading text.
 * @var string $first_name      Customer first name or neutral fallback.
 * @var array  $items           List of array{name:string,quantity:int,total:string}.
 * @var int    $extra_items     Count of items beyond the rendered cap.
 * @var string $cart_total      Formatted cart total.
 * @var string $restore_url     Signed restore link.
 * @var string $unsubscribe_url Signed unsubscribe link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

/* translators: %s: customer first name or a neutral fallback. */
printf( esc_html__( 'Hi %s,', 'woo-cart-rescue' ), esc_html( $first_name ) );
echo "\n\n";

esc_html_e( 'You left the following in your cart:', 'woo-cart-rescue' );
echo "\n\n";

foreach ( $items as $item ) {
	echo esc_html( sprintf( '- %1$s x%2$d  %3$s', $item['name'], (int) $item['quantity'], wp_strip_all_tags( $item['total'] ) ) ) . "\n";
}

if ( $extra_items > 0 ) {
	/* translators: %d: number of additional cart items not shown. */
	echo esc_html( sprintf( _n( 'and %d more item', 'and %d more items', $extra_items, 'woo-cart-rescue' ), (int) $extra_items ) ) . "\n";
}

echo "\n";
/* translators: %s: formatted cart total. */
printf( esc_html__( 'Cart total: %s', 'woo-cart-rescue' ), esc_html( wp_strip_all_tags( $cart_total ) ) );
echo "\n\n";

esc_html_e( 'Return to your cart:', 'woo-cart-rescue' );
echo "\n" . esc_url_raw( $restore_url ) . "\n\n";

esc_html_e( 'Unsubscribe from cart reminders:', 'woo-cart-rescue' );
echo "\n" . esc_url_raw( $unsubscribe_url ) . "\n";
