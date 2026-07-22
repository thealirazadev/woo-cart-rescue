<?php
/**
 * Integration tests for consent-gated capture and the guest capture AJAX route.
 *
 * Runs only when the WordPress test suite is loaded; otherwise it is a no-op so
 * the same file passes in the unit-only environment.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_Ajax_UnitTestCase' ) ) {
	return;
}

/**
 * Covers the AJAX validation branches and the upsert behavior.
 */
class WCR_Test_Capture extends WP_Ajax_UnitTestCase {

	/**
	 * A simple product used to populate the cart.
	 *
	 * @var WC_Product|null
	 */
	protected $product = null;

	/**
	 * Sets up a fresh WooCommerce session and cart per test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'wcr_settings', wcr_default_settings() );

		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		WC()->cart->empty_cart();

		// Built from the public CRUD API rather than WC_Helper_Product: that helper ships with
		// WooCommerce's own test framework, which a released WooCommerce package does not include.
		$product = new WC_Product_Simple();
		$product->set_name( 'Cart Rescue Test Product' );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );
		$product->save();

		$this->product = $product;
	}

	/**
	 * Adds the test product to the cart.
	 *
	 * @return bool True when the cart holds at least one line.
	 */
	protected function seed_cart() {
		WC()->cart->add_to_cart( $this->product->get_id(), 2 );
		return ! WC()->cart->is_empty();
	}

	/**
	 * Counts rows in the carts table.
	 *
	 * @return int
	 */
	protected function count_carts() {
		global $wpdb;
		$table = wcr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * A missing nonce is rejected with a 403 and invalid_nonce code.
	 *
	 * @return void
	 */
	public function test_ajax_rejects_invalid_nonce() {
		$_POST['nonce']   = 'bogus';
		$_POST['email']   = 'guest@example.com';
		$_POST['consent'] = '1';

		$response = $this->dispatch_capture();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_nonce', $response['data']['code'] );
	}

	/**
	 * An invalid email is rejected with invalid_email.
	 *
	 * @return void
	 */
	public function test_ajax_rejects_invalid_email() {
		$_POST['nonce']   = wp_create_nonce( 'wcr_capture' );
		$_POST['email']   = 'not-an-email';
		$_POST['consent'] = '1';

		$response = $this->dispatch_capture();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_email', $response['data']['code'] );
	}

	/**
	 * Consent other than "1" is rejected with consent_required.
	 *
	 * @return void
	 */
	public function test_ajax_requires_consent() {
		$_POST['nonce']   = wp_create_nonce( 'wcr_capture' );
		$_POST['email']   = 'guest@example.com';
		$_POST['consent'] = '0';

		$response = $this->dispatch_capture();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'consent_required', $response['data']['code'] );
		$this->assertSame( 0, $this->count_carts() );
	}

	/**
	 * A consenting guest with a populated cart gets exactly one row.
	 *
	 * @return void
	 */
	public function test_consenting_guest_is_captured_once() {
		$this->assertTrue( $this->seed_cart(), 'The test product could not be added to the cart.' );

		$capture = new WCR_Capture();
		$cart_id = $capture->upsert( 'guest@example.com', 0, true );

		$this->assertGreaterThan( 0, $cart_id );
		$this->assertSame( 1, $this->count_carts() );

		global $wpdb;
		$table = wcr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against a trusted table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $cart_id ), ARRAY_A );

		$this->assertSame( 'guest@example.com', $row['email'] );
		$this->assertSame( '1', (string) $row['consent'] );
		$this->assertNotEmpty( json_decode( $row['cart_contents'], true ) );

		// A second capture for the same session updates in place.
		$again = $capture->upsert( 'guest@example.com', 0, true );
		$this->assertSame( $cart_id, $again );
		$this->assertSame( 1, $this->count_carts() );
	}

	/**
	 * An opted-out guest gets the success body but no row is written.
	 *
	 * @return void
	 */
	public function test_opted_out_guest_is_silently_skipped() {
		$this->assertTrue( $this->seed_cart(), 'The test product could not be added to the cart.' );

		global $wpdb;
		$wpdb->insert(
			wcr_table( 'optouts' ),
			array(
				'email_hash' => wcr_email_hash( 'guest@example.com' ),
				'created_at' => wcr_now(),
			),
			array( '%s', '%s' )
		);

		$_POST['nonce']   = wp_create_nonce( 'wcr_capture' );
		$_POST['email']   = 'guest@example.com';
		$_POST['consent'] = '1';

		$response = $this->dispatch_capture();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $this->count_carts() );
	}

	/**
	 * A logged-in customer on the opt-out list gets no cart row written.
	 *
	 * @return void
	 */
	public function test_logged_in_optout_writes_no_row() {
		$this->assertTrue( $this->seed_cart(), 'The test product could not be added to the cart.' );

		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'member@example.com',
				'role'       => 'customer',
			)
		);
		wp_set_current_user( $user_id );

		global $wpdb;
		$wpdb->insert(
			wcr_table( 'optouts' ),
			array(
				'email_hash' => wcr_email_hash( 'member@example.com' ),
				'created_at' => wcr_now(),
			),
			array( '%s', '%s' )
		);

		( new WCR_Capture() )->capture_logged_in();

		$this->assertSame( 0, $this->count_carts() );
	}

	/**
	 * A logged-in customer who has not opted out is captured once.
	 *
	 * @return void
	 */
	public function test_logged_in_without_optout_is_captured() {
		$this->assertTrue( $this->seed_cart(), 'The test product could not be added to the cart.' );

		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'active-member@example.com',
				'role'       => 'customer',
			)
		);
		wp_set_current_user( $user_id );

		( new WCR_Capture() )->capture_logged_in();

		$this->assertSame( 1, $this->count_carts() );
	}

	/**
	 * Dispatches the capture AJAX action and returns the decoded response.
	 *
	 * @return array
	 */
	protected function dispatch_capture() {
		try {
			$this->_handleAjax( 'wcr_capture_guest' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}
}
