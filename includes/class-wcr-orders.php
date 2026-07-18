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

		$recovery = $this->get_recovery_session();

		// Prefer the restored cart for attribution; otherwise match the order.
		$cart = $recovery ? wcr_get_cart( $recovery['cart_id'] ) : null;

		if ( ! $cart ) {
			$cart = $this->find_cart( $order );
		}

		if ( ! $cart ) {
			$this->clear_recovery_session();
			return;
		}

		$in_window = $recovery && (int) $cart->id === (int) $recovery['cart_id'] && time() < (int) $recovery['expires'];

		if ( $in_window ) {
			$this->mark_recovered( $cart, $order );
			wcr_log_event( (int) $cart->id, (int) $recovery['send_id'], 'order_recovered', array( 'order_id' => absint( $order_id ) ) );
		} else {
			$this->complete_cart( (int) $cart->id );
			wcr_log_event( (int) $cart->id, null, 'order_completed', array( 'order_id' => absint( $order_id ) ) );
		}

		wcr_cancel_pending_sends( (int) $cart->id );
		$this->clear_recovery_session();
	}

	/**
	 * Reads the restore attribution keys from the WooCommerce session.
	 *
	 * @return array|null
	 */
	protected function get_recovery_session() {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return null;
		}

		$cart_id = (int) WC()->session->get( 'wcr_recovery_cart_id' );

		if ( $cart_id <= 0 ) {
			return null;
		}

		return array(
			'cart_id' => $cart_id,
			'send_id' => (int) WC()->session->get( 'wcr_recovery_send_id' ),
			'expires' => (int) WC()->session->get( 'wcr_recovery_expires' ),
		);
	}

	/**
	 * Clears the restore attribution keys from the session.
	 *
	 * @return void
	 */
	protected function clear_recovery_session() {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}

		WC()->session->set( 'wcr_recovery_cart_id', null );
		WC()->session->set( 'wcr_recovery_send_id', null );
		WC()->session->set( 'wcr_recovery_expires', null );
	}

	/**
	 * Marks a cart recovered.
	 *
	 * @param object   $cart  Cart row.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	protected function mark_recovered( $cart, $order ) {
		global $wpdb;

		$table = wcr_table( 'carts' );
		$now   = wcr_now();
		$total = (float) $order->get_total();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'recovered', recovered_order_id = %d, recovered_total = %f, recovered_at = %s, updated_at = %s WHERE id = %d AND status IN ('active','abandoned')",
				(int) $order->get_id(),
				$total,
				$now,
				$now,
				absint( $cart->id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $updated ) {
			$order->update_meta_data( '_wcr_recovered_cart_id', absint( $cart->id ) );
			$order->save();
		}
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
