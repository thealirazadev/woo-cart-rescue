<?php
/**
 * Integration tests for restore token validation and unsubscribe effects.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers restore token states and the unsubscribe side effects.
 */
class WCR_Test_Endpoints extends WP_UnitTestCase {

	const SECRET = 'b6f4c9d1e7b2486093ac1f0e5d7b8c2a4f6e9d0b1c3a5e7f9d2b4c6a8e0f1d3b5';

	/**
	 * Resets settings and the token secret.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wcr_settings', wcr_default_settings() );
		update_option( 'wcr_token_secret', self::SECRET );
	}

	/**
	 * Seeds a cart and a send carrying a valid token; returns [cart_id, send_id, token].
	 *
	 * @param string $cart_status Cart status.
	 * @param int    $expires     Token expiry timestamp.
	 * @return array
	 */
	protected function seed_with_token( $cart_status = 'abandoned', $expires = null ) {
		global $wpdb;

		$now     = wcr_now();
		$expires = null === $expires ? time() + HOUR_IN_SECONDS : $expires;

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
				'status'        => 'sent',
				'scheduled_for' => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$send_id = (int) $wpdb->insert_id;

		$token = WCR_Token::build( $send_id, $expires, 'deadbeefdeadbeefdeadbeefdeadbeef', self::SECRET );

		$wpdb->update(
			wcr_table( 'sends' ),
			array(
				'token_hash'       => WCR_Token::hash( $token ),
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
			),
			array( 'id' => $send_id )
		);

		return array( $cart_id, $send_id, $token );
	}

	/**
	 * A valid token validates, and reuse is rejected after it is marked used.
	 *
	 * @return void
	 */
	public function test_restore_token_is_single_use() {
		global $wpdb;
		list( , $send_id, $token ) = $this->seed_with_token();

		$this->assertTrue( WCR_Token::validate_restore( $token )['ok'] );

		$wpdb->update( wcr_table( 'sends' ), array( 'token_used_at' => wcr_now() ), array( 'id' => $send_id ) );

		$result = WCR_Token::validate_restore( $token );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'already_used', $result['code'] );
	}

	/**
	 * A cart in a terminal state is rejected as wrong_state.
	 *
	 * @return void
	 */
	public function test_restore_rejects_wrong_state() {
		list( , , $token ) = $this->seed_with_token( 'completed' );

		$result = WCR_Token::validate_restore( $token );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'wrong_state', $result['code'] );
	}

	/**
	 * An expired token is rejected.
	 *
	 * @return void
	 */
	public function test_restore_rejects_expired_token() {
		list( , , $token ) = $this->seed_with_token( 'abandoned', time() - 10 );

		$result = WCR_Token::validate_restore( $token );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'expired', $result['code'] );
	}

	/**
	 * A tampered token is rejected as bad signature.
	 *
	 * @return void
	 */
	public function test_restore_rejects_tampered_token() {
		list( , , $token ) = $this->seed_with_token();
		$parts             = explode( '.', $token );
		$parts[3]          = str_repeat( '0', 64 );

		$result = WCR_Token::validate_restore( implode( '.', $parts ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'bad_signature', $result['code'] );
	}

	/**
	 * Unsubscribe sets the cart unsubscribed, cancels sends, and records the opt-out. Idempotent.
	 *
	 * @return void
	 */
	public function test_unsubscribe_cancels_and_records_optout() {
		global $wpdb;

		$now = wcr_now();
		$wpdb->insert(
			wcr_table( 'carts' ),
			array(
				'cart_key'         => 'key-unsub',
				'email'            => 'bye@example.com',
				'consent'          => 1,
				'cart_contents'    => wp_json_encode(
					array(
						array(
							'product_id' => 1,
							'quantity'   => 1,
						),
					)
				),
				'cart_total'       => 3.0,
				'currency'         => 'USD',
				'status'           => 'abandoned',
				'last_activity_at' => $now,
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

		$sends_table   = wcr_table( 'sends' );
		$optouts_table = wcr_table( 'optouts' );

		$cart      = wcr_get_cart( $cart_id );
		$endpoints = new WCR_Endpoints();
		$endpoints->apply_unsubscribe( $cart );

		$this->assertSame( 'unsubscribed', wcr_get_cart( $cart_id )->status );
		$this->assertTrue( wcr_is_opted_out( 'bye@example.com' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion.
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sends_table} WHERE cart_id = %d AND status = %s", $cart_id, 'scheduled' ) );
		$this->assertSame( 0, $pending );

		// Idempotent: running again does not error or duplicate opt-outs.
		$endpoints->apply_unsubscribe( wcr_get_cart( $cart_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion.
		$optouts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$optouts_table} WHERE email_hash = %s", wcr_email_hash( 'bye@example.com' ) ) );
		$this->assertSame( 1, $optouts );
	}
}
