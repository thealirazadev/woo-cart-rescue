<?php
/**
 * Integration tests for race-safe sending.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers the send-time recheck, atomic claim, and duplicate protection.
 */
class WCR_Test_Sender extends WP_UnitTestCase {

	/**
	 * Resets settings, the mailer, and enables the step-1 email.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wcr_settings', wcr_default_settings() );
		update_option( 'wcr_token_secret', str_repeat( 'a', 64 ) );
		update_option( 'woocommerce_wcr_recovery_step_1_settings', array( 'enabled' => 'yes' ) );

		if ( function_exists( 'reset_phpmailer_instance' ) ) {
			reset_phpmailer_instance();
		}
	}

	/**
	 * Seeds a cart and a scheduled send, returning [cart_id, send_id].
	 *
	 * @param string $cart_status Cart status.
	 * @return array
	 */
	protected function seed( $cart_status = 'abandoned' ) {
		global $wpdb;

		$now = wcr_now();

		$wpdb->insert(
			wcr_table( 'carts' ),
			array(
				'cart_key'         => 'key-' . wp_generate_password( 8, false ),
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
				'status'           => $cart_status,
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
	 * Returns the send row.
	 *
	 * @param int $send_id Send id.
	 * @return object|null
	 */
	protected function send_row( $send_id ) {
		return wcr_get_send( $send_id );
	}

	/**
	 * Counts emails captured by the test mailer.
	 *
	 * @return int
	 */
	protected function sent_count() {
		if ( isset( $GLOBALS['phpmailer'] ) && isset( $GLOBALS['phpmailer']->mock_sent ) ) {
			return count( $GLOBALS['phpmailer']->mock_sent );
		}
		return 0;
	}

	/**
	 * A cart no longer abandoned at send time cancels the send with no email.
	 *
	 * @return void
	 */
	public function test_send_time_recheck_cancels() {
		list( , $send_id ) = $this->seed( 'completed' );

		( new WCR_Sender() )->handle( $send_id );

		$this->assertSame( 'cancelled', $this->send_row( $send_id )->status );
		$this->assertSame( 0, $this->sent_count() );
	}

	/**
	 * A valid abandoned cart sends and records the token hash.
	 *
	 * @return void
	 */
	public function test_valid_send_marks_sent_and_stores_token() {
		list( , $send_id ) = $this->seed( 'abandoned' );

		( new WCR_Sender() )->handle( $send_id );

		$row = $this->send_row( $send_id );
		$this->assertSame( 'sent', $row->status );
		$this->assertNotEmpty( $row->token_hash );
		$this->assertNotEmpty( $row->sent_at );
		$this->assertSame( 1, $this->sent_count() );
	}

	/**
	 * The rendered HTML email exposes the item list as a captioned data table
	 * and marks the CTA layout table as presentational.
	 *
	 * @return void
	 */
	public function test_recovery_email_body_is_accessible() {
		list( , $send_id ) = $this->seed( 'abandoned' );

		( new WCR_Sender() )->handle( $send_id );

		$this->assertSame( 1, $this->sent_count() );
		$body = $GLOBALS['phpmailer']->mock_sent[0]['body'];

		$this->assertStringContainsString( 'Your saved cart', $body );
		$this->assertStringContainsString( 'role="presentation"', $body );
	}

	/**
	 * Running the same send twice produces exactly one email.
	 *
	 * @return void
	 */
	public function test_duplicate_run_sends_once() {
		list( , $send_id ) = $this->seed( 'abandoned' );

		$sender = new WCR_Sender();
		$sender->handle( $send_id );
		$sender->handle( $send_id );

		$this->assertSame( 'sent', $this->send_row( $send_id )->status );
		$this->assertSame( 1, $this->sent_count() );
	}

	/**
	 * Returns a send row for a cart step.
	 *
	 * @param int $cart_id Cart id.
	 * @param int $step    Step number.
	 * @return object|null
	 */
	protected function send_for_step( $cart_id, $step ) {
		global $wpdb;
		$table = wcr_table( 'sends' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d AND step = %d", $cart_id, $step ) );
	}

	/**
	 * A successful send chains the next enabled step.
	 *
	 * @return void
	 */
	public function test_successful_send_chains_next_step() {
		list( $cart_id, $send_id ) = $this->seed( 'abandoned' );

		( new WCR_Sender() )->handle( $send_id );

		$step_two = $this->send_for_step( $cart_id, 2 );
		$this->assertNotNull( $step_two );
		$this->assertSame( 'scheduled', $step_two->status );
	}

	/**
	 * Chaining skips a disabled step and schedules the next enabled one.
	 *
	 * @return void
	 */
	public function test_chain_skips_disabled_step() {
		$settings                        = wcr_default_settings();
		$settings['steps']               = wcr_step_defaults();
		$settings['steps'][2]['enabled'] = false;
		update_option( 'wcr_settings', $settings );

		list( $cart_id, $send_id ) = $this->seed( 'abandoned' );

		( new WCR_Sender() )->handle( $send_id );

		$this->assertNull( $this->send_for_step( $cart_id, 2 ) );
		$this->assertNotNull( $this->send_for_step( $cart_id, 3 ) );
	}

	/**
	 * An opted-out email cancels the send at recheck.
	 *
	 * @return void
	 */
	public function test_opted_out_email_cancels() {
		global $wpdb;
		list( , $send_id ) = $this->seed( 'abandoned' );

		$wpdb->insert(
			wcr_table( 'optouts' ),
			array(
				'email_hash' => wcr_email_hash( 'shopper@example.com' ),
				'created_at' => wcr_now(),
			)
		);

		( new WCR_Sender() )->handle( $send_id );

		$this->assertSame( 'cancelled', $this->send_row( $send_id )->status );
		$this->assertSame( 0, $this->sent_count() );
	}
}
