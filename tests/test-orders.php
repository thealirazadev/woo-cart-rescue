<?php
/**
 * Integration tests for order-driven sequence cancellation.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers cancellation on order placement.
 */
class WCR_Test_Orders extends WP_UnitTestCase {

	/**
	 * Resets settings before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wcr_settings', wcr_default_settings() );
	}

	/**
	 * Seeds an abandoned cart with a scheduled send for an email.
	 *
	 * @param string $email Cart email.
	 * @return array [cart_id, send_id]
	 */
	protected function seed( $email ) {
		global $wpdb;

		$now = wcr_now();
		$wpdb->insert(
			wcr_table( 'carts' ),
			array(
				'cart_key'         => 'key-' . wp_generate_password( 8, false ),
				'email'            => $email,
				'consent'          => 1,
				'cart_contents'    => wp_json_encode(
					array(
						array(
							'product_id' => 1,
							'quantity'   => 1,
						),
					)
				),
				'cart_total'       => 12.0,
				'currency'         => 'USD',
				'status'           => 'abandoned',
				'last_activity_at' => $now,
				'abandoned_at'     => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$cart_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 1,
				'status'        => 'scheduled',
				'scheduled_for' => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return array( $cart_id, (int) $wpdb->insert_id );
	}

	/**
	 * Placing an order for the cart email completes it and cancels the send.
	 *
	 * @return void
	 */
	public function test_order_completes_cart_and_cancels_send() {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce order helpers unavailable.' );
		}

		list( $cart_id, $send_id ) = $this->seed( 'buyer@example.com' );

		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.com' );
		$order->save();

		( new WCR_Orders() )->on_order_processed( $order->get_id() );

		$this->assertSame( 'completed', wcr_get_cart( $cart_id )->status );
		$this->assertSame( 'cancelled', wcr_get_send( $send_id )->status );
	}

	/**
	 * An unrelated order does not touch the cart.
	 *
	 * @return void
	 */
	public function test_unrelated_order_leaves_cart() {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce order helpers unavailable.' );
		}

		list( $cart_id, $send_id ) = $this->seed( 'buyer@example.com' );

		$order = wc_create_order();
		$order->set_billing_email( 'someone-else@example.com' );
		$order->save();

		( new WCR_Orders() )->on_order_processed( $order->get_id() );

		$this->assertSame( 'abandoned', wcr_get_cart( $cart_id )->status );
		$this->assertSame( 'scheduled', wcr_get_send( $send_id )->status );
	}

	/**
	 * A later pending step is cancelled when an order is placed.
	 *
	 * @return void
	 */
	public function test_order_cancels_later_pending_step() {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce order helpers unavailable.' );
		}

		global $wpdb;
		list( $cart_id ) = $this->seed( 'multi@example.com' );

		// Step 1 already sent; step 3 pending.
		$wpdb->update( wcr_table( 'sends' ), array( 'status' => 'sent' ), array( 'cart_id' => $cart_id ) );
		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 3,
				'status'        => 'scheduled',
				'scheduled_for' => wcr_now(),
				'created_at'    => wcr_now(),
				'updated_at'    => wcr_now(),
			)
		);

		$order = wc_create_order();
		$order->set_billing_email( 'multi@example.com' );
		$order->save();

		( new WCR_Orders() )->on_order_processed( $order->get_id() );

		$table = wcr_table( 'sends' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table.
		$step_three = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE cart_id = %d AND step = %d", $cart_id, 3 ) );
		$this->assertSame( 'cancelled', $step_three );
	}
}
