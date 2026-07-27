<?php
/**
 * Integration tests for auto-generated recovery coupons.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers coupon generation, the email merge tag, restore application, and cleanup.
 */
class WCR_Test_Coupons extends WP_UnitTestCase {

	/**
	 * Enables the feature with a percentage discount and the step-1 coupon toggle.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings                         = wcr_default_settings();
		$settings['coupon_enabled']       = true;
		$settings['coupon_type']          = 'percent';
		$settings['coupon_amount']        = 10.0;
		$settings['coupon_validity_days'] = 7;
		$settings['steps']                = wcr_step_defaults();
		$settings['steps'][1]['coupon']   = true;
		update_option( 'wcr_settings', $settings );

		update_option( 'wcr_token_secret', str_repeat( 'a', 64 ) );
		update_option( 'woocommerce_wcr_recovery_step_1_settings', array( 'enabled' => 'yes' ) );

		if ( function_exists( 'reset_phpmailer_instance' ) ) {
			reset_phpmailer_instance();
		}
	}

	/**
	 * Seeds an abandoned cart and a scheduled step-1 send; returns [cart_id, send_id].
	 *
	 * @return array
	 */
	protected function seed() {
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
	 * A coupon-enabled send generates a single-use coupon with the right expiry.
	 *
	 * @return void
	 */
	public function test_coupon_generated_when_enabled() {
		list( , $send_id ) = $this->seed();

		( new WCR_Sender() )->handle( $send_id );

		$code = wcr_get_send( $send_id )->coupon_code;
		$this->assertNotEmpty( $code );

		$coupon = new WC_Coupon( $code );
		$this->assertGreaterThan( 0, $coupon->get_id() );
		$this->assertSame( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( 1, $coupon->get_usage_limit() );
		$this->assertSame( 'yes', $coupon->get_meta( '_wcr_auto_generated' ) );

		$expires = $coupon->get_date_expires();
		$this->assertNotNull( $expires );
		$this->assertEqualsWithDelta( time() + ( 7 * DAY_IN_SECONDS ), $expires->getTimestamp(), 300 );
	}

	/**
	 * Each send generates a distinct coupon code.
	 *
	 * @return void
	 */
	public function test_coupon_is_unique_per_send() {
		list( , $send_one ) = $this->seed();
		list( , $send_two ) = $this->seed();

		( new WCR_Sender() )->handle( $send_one );
		( new WCR_Sender() )->handle( $send_two );

		$code_one = wcr_get_send( $send_one )->coupon_code;
		$code_two = wcr_get_send( $send_two )->coupon_code;

		$this->assertNotEmpty( $code_one );
		$this->assertNotEmpty( $code_two );
		$this->assertNotSame( $code_one, $code_two );
	}

	/**
	 * No coupon is generated while the feature is disabled.
	 *
	 * @return void
	 */
	public function test_no_coupon_when_feature_disabled() {
		$settings                   = get_option( 'wcr_settings' );
		$settings['coupon_enabled'] = false;
		update_option( 'wcr_settings', $settings );

		list( , $send_id ) = $this->seed();

		( new WCR_Sender() )->handle( $send_id );

		$this->assertNull( wcr_get_send( $send_id )->coupon_code );
	}

	/**
	 * The generated coupon code appears in the sent email body.
	 *
	 * @return void
	 */
	public function test_email_shows_coupon_code() {
		list( , $send_id ) = $this->seed();

		( new WCR_Sender() )->handle( $send_id );

		$code = wcr_get_send( $send_id )->coupon_code;
		$this->assertNotEmpty( $code );

		$this->assertNotEmpty( $GLOBALS['phpmailer']->mock_sent );
		$body = $GLOBALS['phpmailer']->mock_sent[0]['body'];
		$this->assertStringContainsString( $code, $body );
	}

	/**
	 * The subject merge tag {coupon_code} renders the code.
	 *
	 * @return void
	 */
	public function test_subject_merge_tag_renders_coupon_code() {
		list( , $send_id ) = $this->seed();

		update_option( 'woocommerce_wcr_recovery_step_1_settings', array( 'subject' => 'Your code: {coupon_code}' ) );

		( new WCR_Sender() )->handle( $send_id );

		$code = wcr_get_send( $send_id )->coupon_code;
		$this->assertNotEmpty( $GLOBALS['phpmailer']->mock_sent );
		$this->assertSame( 'Your code: ' . $code, $GLOBALS['phpmailer']->mock_sent[0]['subject'] );
	}

	/**
	 * No coupon is generated when the sending step's toggle is off.
	 *
	 * @return void
	 */
	public function test_no_coupon_when_step_toggle_off() {
		$settings                       = get_option( 'wcr_settings' );
		$settings['steps'][1]['coupon'] = false;
		update_option( 'wcr_settings', $settings );

		list( , $send_id ) = $this->seed();

		( new WCR_Sender() )->handle( $send_id );

		$this->assertNull( wcr_get_send( $send_id )->coupon_code );
	}

	/**
	 * Loads the session cart with one purchasable product.
	 *
	 * @return void
	 */
	protected function init_cart_with_product() {
		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();

		$product = new WC_Product_Simple();
		$product->set_name( 'Coupon Test Product' );
		$product->set_regular_price( '20' );
		$product->set_status( 'publish' );
		$product->save();

		WC()->cart->add_to_cart( $product->get_id(), 1 );
	}

	/**
	 * The restore endpoint applies the send's coupon to the rebuilt cart.
	 *
	 * @return void
	 */
	public function test_restore_applies_coupon() {
		global $wpdb;

		list( , $send_id ) = $this->seed();
		$code              = wcr_generate_recovery_coupon( $send_id, get_option( 'wcr_settings' ) );
		$this->assertNotEmpty( $code );

		$wpdb->update( wcr_table( 'sends' ), array( 'coupon_code' => $code ), array( 'id' => $send_id ) );

		$this->init_cart_with_product();

		( new WCR_Endpoints() )->apply_recovery_coupon( wcr_get_send( $send_id ) );

		$this->assertTrue( WC()->cart->has_discount( $code ) );
	}

	/**
	 * An expired coupon is not applied and raises no error.
	 *
	 * @return void
	 */
	public function test_restore_skips_expired_coupon() {
		global $wpdb;

		list( , $send_id ) = $this->seed();

		$coupon = new WC_Coupon();
		$coupon->set_code( 'wcr-expired-' . wp_generate_password( 6, false ) );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() - DAY_IN_SECONDS );
		$coupon->update_meta_data( '_wcr_auto_generated', 'yes' );
		$coupon->save();

		$wpdb->update( wcr_table( 'sends' ), array( 'coupon_code' => $coupon->get_code() ), array( 'id' => $send_id ) );

		$this->init_cart_with_product();

		( new WCR_Endpoints() )->apply_recovery_coupon( wcr_get_send( $send_id ) );

		$this->assertFalse( WC()->cart->has_discount( $coupon->get_code() ) );
	}

	/**
	 * The retention cleanup deletes expired auto-generated coupons and keeps live ones.
	 *
	 * @return void
	 */
	public function test_cleanup_deletes_expired_coupons_only() {
		$expired = new WC_Coupon();
		$expired->set_code( 'wcr-old-' . wp_generate_password( 6, false ) );
		$expired->set_amount( 10 );
		$expired->set_date_expires( time() - DAY_IN_SECONDS );
		$expired->update_meta_data( '_wcr_auto_generated', 'yes' );
		$expired->save();
		$expired_id = $expired->get_id();

		$live = new WC_Coupon();
		$live->set_code( 'wcr-new-' . wp_generate_password( 6, false ) );
		$live->set_amount( 10 );
		$live->set_date_expires( time() + ( 30 * DAY_IN_SECONDS ) );
		$live->update_meta_data( '_wcr_auto_generated', 'yes' );
		$live->save();
		$live_id = $live->get_id();

		( new WCR_Privacy() )->cleanup();

		$this->assertNull( get_post( $expired_id ) );
		$this->assertNotNull( get_post( $live_id ) );
	}
}
