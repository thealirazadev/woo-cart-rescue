<?php
/**
 * Standalone unsubscribe confirmation page.
 *
 * @package Woo_Cart_Rescue
 *
 * @var bool $wcr_valid Whether the unsubscribe token was valid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$wcr_site_title = get_bloginfo( 'name' );
?>
<div class="wcr-unsubscribe" style="max-width:600px;margin:40px auto;padding:0 16px;">
	<?php if ( ! empty( $wcr_valid ) ) : ?>
		<h1><?php esc_html_e( 'You have been unsubscribed', 'woo-cart-rescue' ); ?></h1>
		<p>
			<?php
			/* translators: %s: site title. */
			printf( esc_html__( 'You will not receive further cart reminder emails from %s.', 'woo-cart-rescue' ), esc_html( $wcr_site_title ) );
			?>
		</p>
	<?php else : ?>
		<h1><?php esc_html_e( 'Unsubscribe', 'woo-cart-rescue' ); ?></h1>
		<p><?php esc_html_e( 'This link is not valid.', 'woo-cart-rescue' ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to shop', 'woo-cart-rescue' ); ?></a></p>
</div>
<?php
get_footer();
