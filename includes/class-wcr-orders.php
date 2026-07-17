<?php
/**
 * Order handling: sequence cancellation and recovery attribution.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cancels the sequence and attributes recovered orders on checkout.
 */
class WCR_Orders {

	/**
	 * Registers the order hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 10, 1 );
	}

	/**
	 * Finds the cart behind a new order and stops its sequence.
	 *
	 * The cart status is moved off abandoned before sends are cancelled so an
	 * in-flight worker's recheck sees the completed state and does not mail.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_order_processed( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$cart = $this->find_cart( $order );

		if ( ! $cart ) {
			return;
		}

		$this->complete_cart( (int) $cart->id );
		wcr_cancel_pending_sends( (int) $cart->id );
		wcr_log_event( (int) $cart->id, null, 'order_completed', array( 'order_id' => absint( $order_id ) ) );
	}

	/**
	 * Locates the plugin cart row for an order by session key, user, then email.
	 *
	 * @param WC_Order $order Order object.
	 * @return object|null
	 */
	protected function find_cart( $order ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$key = (string) WC()->session->get_customer_id();

			if ( '' !== $key ) {
				$cart = wcr_get_cart_by_key( $key );

				if ( $cart && $this->is_open( $cart ) ) {
					return $cart;
				}
			}
		}

		$user_id = (int) $order->get_user_id();

		if ( $user_id > 0 ) {
			$cart = $this->find_latest_open( 'user_id', $user_id );

			if ( $cart ) {
				return $cart;
			}
		}

		$email = $order->get_billing_email();

		if ( $email ) {
			return $this->find_latest_open( 'email', $email );
		}

		return null;
	}

	/**
	 * Whether a cart is still in an open (cancellable) state.
	 *
	 * @param object $cart Cart row.
	 * @return bool
	 */
	protected function is_open( $cart ) {
		return in_array( $cart->status, array( 'active', 'abandoned' ), true );
	}

	/**
	 * Finds the most recent open cart matching a column value.
	 *
	 * @param string     $column One of user_id or email.
	 * @param int|string $value  Value to match.
	 * @return object|null
	 */
	protected function find_latest_open( $column, $value ) {
		global $wpdb;

		$table = wcr_table( 'carts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
		if ( 'user_id' === $column ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status IN ('active','abandoned') ORDER BY updated_at DESC LIMIT 1", (int) $value )
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND status IN ('active','abandoned') ORDER BY updated_at DESC LIMIT 1", (string) $value )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Marks an open cart completed.
	 *
	 * @param int $cart_id Cart row id.
	 * @return void
	 */
	protected function complete_cart( $cart_id ) {
		global $wpdb;

		$table = wcr_table( 'carts' );
		$now   = wcr_now();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET status = 'completed', updated_at = %s WHERE id = %d AND status IN ('active','abandoned')", $now, absint( $cart_id ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
