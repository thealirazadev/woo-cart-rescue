<?php
/**
 * Recovery email HTML body. Theme-overridable via the WooCommerce template loader.
 *
 * @package Woo_Cart_Rescue
 *
 * @var WC_Email $email          Email object.
 * @var string   $email_heading  Heading text.
 * @var string   $first_name     Customer first name or neutral fallback.
 * @var array    $items          List of array{name:string,quantity:int,total:string}.
 * @var int      $extra_items    Count of items beyond the rendered cap.
 * @var string   $cart_total     Formatted cart total.
 * @var string   $restore_url    Signed restore link.
 * @var string   $unsubscribe_url Signed unsubscribe link.
 * @var string   $button_color   CTA button background color.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	/* translators: %s: customer first name or a neutral fallback. */
	printf( esc_html__( 'Hi %s,', 'woo-cart-rescue' ), esc_html( $first_name ) );
	?>
</p>

<p><?php esc_html_e( 'You left the following in your cart. We saved it so you can pick up where you left off.', 'woo-cart-rescue' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5;" border="1">
	<thead>
		<tr>
			<th scope="col" style="text-align:left;"><?php esc_html_e( 'Product', 'woo-cart-rescue' ); ?></th>
			<th scope="col" style="text-align:left;"><?php esc_html_e( 'Quantity', 'woo-cart-rescue' ); ?></th>
			<th scope="col" style="text-align:left;"><?php esc_html_e( 'Total', 'woo-cart-rescue' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td style="text-align:left; word-break:break-word;"><?php echo esc_html( $item['name'] ); ?></td>
				<td style="text-align:left;"><?php echo esc_html( (string) $item['quantity'] ); ?></td>
				<td style="text-align:left;"><?php echo wp_kses_post( $item['total'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( $extra_items > 0 ) : ?>
			<tr>
				<td colspan="3" style="text-align:left;">
					<?php
					/* translators: %d: number of additional cart items not shown. */
					printf( esc_html( _n( 'and %d more item', 'and %d more items', $extra_items, 'woo-cart-rescue' ) ), (int) $extra_items );
					?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<p><strong><?php esc_html_e( 'Cart total:', 'woo-cart-rescue' ); ?></strong> <?php echo wp_kses_post( $cart_total ); ?></p>

<table cellspacing="0" cellpadding="0" style="margin:24px 0;">
	<tr>
		<td style="border-radius:4px; background:<?php echo esc_attr( $button_color ); ?>;">
			<a href="<?php echo esc_url( $restore_url ); ?>" style="display:inline-block; padding:12px 24px; color:#ffffff; font-size:16px; font-weight:600; text-decoration:none; border-radius:4px;">
				<?php esc_html_e( 'Return to your cart', 'woo-cart-rescue' ); ?>
			</a>
		</td>
	</tr>
</table>

<p style="font-size:13px; color:#646970;">
	<?php esc_html_e( 'If the button does not work, copy and paste this link into your browser:', 'woo-cart-rescue' ); ?><br />
	<?php echo esc_url( $restore_url ); ?>
</p>

<p style="font-size:12px; color:#646970;">
	<a href="<?php echo esc_url( $unsubscribe_url ); ?>"><?php esc_html_e( 'Unsubscribe from cart reminders', 'woo-cart-rescue' ); ?></a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
