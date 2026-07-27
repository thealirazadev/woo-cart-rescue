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
 * @var string   $coupon_code    Recovery coupon code, empty when none.
 * @var string   $coupon_offer   Human-readable discount, for example "10% off".
 * @var int      $coupon_days    Days the coupon stays valid.
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
	<caption style="text-align:left; padding:6px 0; font-weight:600;"><?php esc_html_e( 'Your saved cart', 'woo-cart-rescue' ); ?></caption>
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

<?php if ( '' !== $coupon_code ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; margin:24px 0;">
		<tr>
			<td style="padding:16px; border:2px dashed <?php echo esc_attr( $button_color ); ?>; text-align:center;">
				<p style="margin:0 0 4px;">
					<?php
					/* translators: %s: human-readable discount, for example "10% off". */
					printf( esc_html__( 'Here is %s to help you finish up:', 'woo-cart-rescue' ), esc_html( $coupon_offer ) );
					?>
				</p>
				<p style="margin:0; font-size:20px; font-weight:700; letter-spacing:1px;"><?php echo esc_html( $coupon_code ); ?></p>
				<?php if ( $coupon_days > 0 ) : ?>
					<p style="margin:6px 0 0; font-size:12px; color:#646970;">
						<?php
						/* translators: %d: number of days the coupon stays valid. */
						printf( esc_html( _n( 'Valid for %d day. Applied automatically when you return.', 'Valid for %d days. Applied automatically when you return.', $coupon_days, 'woo-cart-rescue' ) ), (int) $coupon_days );
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>

<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0;">
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
