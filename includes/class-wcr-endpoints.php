<?php
/**
 * Frontend token endpoints for restore and unsubscribe.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the restore and unsubscribe query-var endpoints at template_redirect.
 */
class WCR_Endpoints {

	/**
	 * Registers the endpoint handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
	}

	/**
	 * Routes the plugin's token endpoints.
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated public GET endpoint; the token is the credential.
		if ( ! isset( $_GET['wcr_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated public GET endpoint; the token is the credential.
		$action = sanitize_key( wp_unslash( $_GET['wcr_action'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated public GET endpoint; the token is the credential.
		$token = isset( $_GET['wcr_token'] ) ? sanitize_text_field( wp_unslash( $_GET['wcr_token'] ) ) : '';

		if ( 'restore' === $action ) {
			$this->handle_restore( $token );
		}
	}

	/**
	 * Validates a restore token, rebuilds the cart, and redirects to checkout.
	 *
	 * Every failure mode produces the same generic notice and a log line.
	 *
	 * @param string $token Token string.
	 * @return void
	 */
	protected function handle_restore( $token ) {
		$result = WCR_Token::validate_restore( $token );

		if ( ! $result['ok'] ) {
			$this->reject_restore( $result['code'] );
			return;
		}

		$send = $result['send'];
		$cart = $result['cart'];

		// Single-use: claim the token atomically so a second click fails.
		global $wpdb;

		$table = wcr_table( 'sends' );
		$now   = wcr_now();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$claimed = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET token_used_at = %s, updated_at = %s WHERE id = %d AND token_used_at IS NULL", $now, $now, (int) $send->id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $claimed ) {
			$this->reject_restore( 'already_used' );
			return;
		}

		$this->rebuild_cart( $cart );
		$this->mark_cart_active( (int) $cart->id );
		$this->set_attribution_session( (int) $cart->id, (int) $send->id );

		wcr_log_event( (int) $cart->id, (int) $send->id, 'restore_used' );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'We saved your cart. You can finish checking out below.', 'woo-cart-rescue' ), 'notice' );
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Rejects a restore with the single generic notice and a log line.
	 *
	 * @param string $code Reason code, log-only.
	 * @return void
	 */
	protected function reject_restore( $code ) {
		wcr_log( 'info', 'Restore rejected.', array( 'code' => $code ) );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'This link is no longer valid.', 'woo-cart-rescue' ), 'error' );
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Rebuilds the session cart from stored contents, skipping unpurchasable items.
	 *
	 * @param object $cart Cart row.
	 * @return void
	 */
	protected function rebuild_cart( $cart ) {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}

		$contents = json_decode( (string) $cart->cart_contents, true );

		if ( ! is_array( $contents ) ) {
			return;
		}

		WC()->cart->empty_cart();

		$skipped = 0;

		foreach ( $contents as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
			$variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
			$quantity     = isset( $line['quantity'] ) ? max( 1, (int) $line['quantity'] ) : 1;
			$variation    = ( isset( $line['variation'] ) && is_array( $line['variation'] ) ) ? $line['variation'] : array();

			$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );

			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				++$skipped;
				continue;
			}

			$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );

			if ( ! $added ) {
				++$skipped;
			}
		}

		if ( $skipped > 0 && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: number of saved items no longer available. */
					_n( '%d item from your saved cart is no longer available.', '%d items from your saved cart are no longer available.', $skipped, 'woo-cart-rescue' ),
					$skipped
				),
				'notice'
			);
		}
	}

	/**
	 * Returns a cart row to active with fresh activity.
	 *
	 * @param int $cart_id Cart row id.
	 * @return void
	 */
	protected function mark_cart_active( $cart_id ) {
		global $wpdb;

		$table = wcr_table( 'carts' );
		$now   = wcr_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Status update on a trusted table.
		$updated = $wpdb->update(
			$table,
			array(
				'status'           => 'active',
				'last_activity_at' => $now,
				'updated_at'       => $now,
			),
			array( 'id' => absint( $cart_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wcr_log( 'error', 'Failed to reactivate a restored cart.', array( 'cart_id' => absint( $cart_id ) ) );
		}
	}

	/**
	 * Stores the attribution keys on the WooCommerce session.
	 *
	 * @param int $cart_id Cart row id.
	 * @param int $send_id Send row id.
	 * @return void
	 */
	protected function set_attribution_session( $cart_id, $send_id ) {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}

		$settings = wcr_get_settings();
		$window   = wcr_clamp( $settings['attribution_days'], 0, 365 ) * DAY_IN_SECONDS;

		WC()->session->set( 'wcr_recovery_cart_id', $cart_id );
		WC()->session->set( 'wcr_recovery_send_id', $send_id );
		WC()->session->set( 'wcr_recovery_expires', time() + $window );
	}
}
