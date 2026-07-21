<?php
/**
 * Integration tests for the abandonment sweep.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers sweep eligibility and step scheduling.
 */
class WCR_Test_Abandonment extends WP_UnitTestCase {

	/**
	 * Resets settings before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wcr_settings', array_merge( wcr_default_settings(), array( 'idle_window' => 60 ) ) );
	}

	/**
	 * Inserts a cart row and returns its id.
	 *
	 * @param array $overrides Column overrides.
	 * @return int
	 */
	protected function seed_cart( $overrides = array() ) {
		global $wpdb;

		$old      = gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) );
		$defaults = array(
			'cart_key'         => 'key-' . wp_generate_password( 8, false ),
			'user_id'          => null,
			'email'            => 'shopper@example.com',
			'consent'          => 1,
			'cart_contents'    => wp_json_encode(
				array(
					array(
						'product_id' => 10,
						'quantity'   => 1,
						'line_total' => 5.0,
					),
				)
			),
			'cart_total'       => 5.0,
			'currency'         => 'USD',
			'status'           => 'active',
			'last_activity_at' => $old,
			'created_at'       => $old,
			'updated_at'       => $old,
		);

		$row = array_merge( $defaults, $overrides );
		$wpdb->insert( wcr_table( 'carts' ), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Counts scheduled/sent send rows for a cart.
	 *
	 * @param int $cart_id Cart id.
	 * @return int
	 */
	protected function count_sends( $cart_id ) {
		global $wpdb;
		$table = wcr_table( 'sends' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE cart_id = %d", $cart_id ) );
	}

	/**
	 * Returns a cart's status.
	 *
	 * @param int $cart_id Cart id.
	 * @return string
	 */
	protected function status_of( $cart_id ) {
		$cart = wcr_get_cart( $cart_id );
		return $cart ? $cart->status : '';
	}

	/**
	 * An idle, eligible cart is abandoned and gets a step-1 send.
	 *
	 * @return void
	 */
	public function test_idle_cart_is_abandoned_and_scheduled() {
		$cart_id = $this->seed_cart();

		( new WCR_Abandonment() )->sweep();

		$this->assertSame( 'abandoned', $this->status_of( $cart_id ) );
		$this->assertSame( 1, $this->count_sends( $cart_id ) );
	}

	/**
	 * A recently active cart is left alone.
	 *
	 * @return void
	 */
	public function test_recent_cart_is_not_abandoned() {
		$now     = gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) );
		$cart_id = $this->seed_cart(
			array(
				'last_activity_at' => $now,
				'created_at'       => $now,
			)
		);

		( new WCR_Abandonment() )->sweep();

		$this->assertSame( 'active', $this->status_of( $cart_id ) );
		$this->assertSame( 0, $this->count_sends( $cart_id ) );
	}

	/**
	 * Empty carts and carts without an email are excluded.
	 *
	 * @return void
	 */
	public function test_empty_or_emailless_carts_excluded() {
		$no_email = $this->seed_cart( array( 'email' => null ) );
		$empty    = $this->seed_cart( array( 'cart_contents' => wp_json_encode( array() ) ) );

		( new WCR_Abandonment() )->sweep();

		$this->assertSame( 'active', $this->status_of( $no_email ) );
		$this->assertSame( 'active', $this->status_of( $empty ) );
	}

	/**
	 * Opted-out emails are never abandoned.
	 *
	 * @return void
	 */
	public function test_opted_out_email_excluded() {
		global $wpdb;
		$wpdb->insert(
			wcr_table( 'optouts' ),
			array(
				'email_hash' => wcr_email_hash( 'shopper@example.com' ),
				'created_at' => wcr_now(),
			)
		);

		$cart_id = $this->seed_cart();

		( new WCR_Abandonment() )->sweep();

		$this->assertSame( 'active', $this->status_of( $cart_id ) );
		$this->assertSame( 0, $this->count_sends( $cart_id ) );
	}

	/**
	 * Resume never re-sends an already sent step 1.
	 *
	 * @return void
	 */
	public function test_resume_does_not_resend_sent_step_one() {
		global $wpdb;
		$cart_id = $this->seed_cart();

		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 1,
				'status'        => 'sent',
				'scheduled_for' => wcr_now(),
				'sent_at'       => wcr_now(),
				'created_at'    => wcr_now(),
				'updated_at'    => wcr_now(),
			)
		);

		( new WCR_Abandonment() )->sweep();

		$table = wcr_table( 'sends' );

		// Step 1 keeps its single sent row: the resume must not queue it again. The sweep does add
		// a row, but for step 2, which is the documented resume behaviour (docs/PRD.md).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table.
		$step_one = $wpdb->get_results( $wpdb->prepare( "SELECT status FROM {$table} WHERE cart_id = %d AND step = 1", $cart_id ) );

		$this->assertCount( 1, $step_one );
		$this->assertSame( 'sent', $step_one[0]->status );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table.
		$scheduled = $wpdb->get_col( $wpdb->prepare( "SELECT step FROM {$table} WHERE cart_id = %d AND status = 'scheduled'", $cart_id ) );

		$this->assertSame( array( '2' ), array_map( 'strval', $scheduled ) );
	}

	/**
	 * Resume schedules the first unsent step, not step 1, when step 1 already sent.
	 *
	 * @return void
	 */
	public function test_resume_schedules_next_unsent_step() {
		global $wpdb;
		$cart_id = $this->seed_cart();

		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 1,
				'status'        => 'sent',
				'scheduled_for' => wcr_now(),
				'sent_at'       => wcr_now(),
				'created_at'    => wcr_now(),
				'updated_at'    => wcr_now(),
			)
		);
		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 2,
				'status'        => 'cancelled',
				'scheduled_for' => wcr_now(),
				'created_at'    => wcr_now(),
				'updated_at'    => wcr_now(),
			)
		);

		( new WCR_Abandonment() )->sweep();

		$table = wcr_table( 'sends' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table.
		$step_two_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE cart_id = %d AND step = %d", $cart_id, 2 ) );
		$this->assertSame( 'scheduled', $step_two_status );
	}
}
