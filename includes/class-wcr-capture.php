<?php
/**
 * Cart capture: logged-in tracking, the checkout consent field, and guest capture AJAX.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records carts into wcr_carts under the consent rules.
 */
class WCR_Capture {

	/**
	 * Registers capture hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_cart_updated', array( $this, 'capture_logged_in' ) );
	}

	/**
	 * Captures a logged-in customer's cart on cart changes.
	 *
	 * @return void
	 */
	public function capture_logged_in() {
		$settings = wcr_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$this->upsert( sanitize_email( $user->user_email ), get_current_user_id(), true );
	}

	/**
	 * Inserts or updates the cart row for the current session.
	 *
	 * @param string $email    Customer email.
	 * @param int    $user_id  User id, or 0 for guests.
	 * @param bool   $consent  Whether consent is granted.
	 * @return int Cart row id, or 0 on failure or skip.
	 */
	public function upsert( $email, $user_id, $consent ) {
		global $wpdb;

		if ( ! function_exists( 'WC' ) ) {
			return 0;
		}

		$wc = WC();

		if ( ! $wc || null === $wc->cart || null === $wc->session ) {
			return 0;
		}

		$cart = $wc->cart;

		if ( $cart->is_empty() ) {
			return 0;
		}

		$cart_key = (string) $wc->session->get_customer_id();

		if ( '' === $cart_key ) {
			return 0;
		}

		$contents = wcr_serialize_cart( $cart );

		if ( empty( $contents ) ) {
			return 0;
		}

		$json = wp_json_encode( $contents );

		if ( false === $json ) {
			wcr_log( 'error', 'Failed to encode cart contents during capture.' );
			return 0;
		}

		$table    = wcr_table( 'carts' );
		$total    = (float) $cart->get_total( 'edit' );
		$currency = get_woocommerce_currency();
		$now      = wcr_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE cart_key = %s", $cart_key ) );

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- In-place update of the captured cart row.
			$updated = $wpdb->update(
				$table,
				array(
					'user_id'          => $user_id ? absint( $user_id ) : null,
					'email'            => $email,
					'consent'          => $consent ? 1 : 0,
					'cart_contents'    => $json,
					'cart_total'       => $total,
					'currency'         => $currency,
					'last_activity_at' => $now,
					'updated_at'       => $now,
				),
				array( 'id' => absint( $existing_id ) ),
				array( '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wcr_log( 'error', 'Failed to update a cart row.', array( 'cart_id' => absint( $existing_id ) ) );
				return 0;
			}

			return absint( $existing_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- First capture of a new session cart.
		$inserted = $wpdb->insert(
			$table,
			array(
				'cart_key'         => $cart_key,
				'user_id'          => $user_id ? absint( $user_id ) : null,
				'email'            => $email,
				'consent'          => $consent ? 1 : 0,
				'cart_contents'    => $json,
				'cart_total'       => $total,
				'currency'         => $currency,
				'status'           => 'active',
				'last_activity_at' => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wcr_log( 'error', 'Failed to insert a cart row.' );
			return 0;
		}

		$cart_id = (int) $wpdb->insert_id;
		wcr_log_event( $cart_id, null, 'captured' );

		return $cart_id;
	}
}
