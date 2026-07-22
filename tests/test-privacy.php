<?php
/**
 * Integration tests for the retention cleanup, exporter, and eraser.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers purge, anonymization, and the privacy tools.
 */
class WCR_Test_Privacy extends WP_UnitTestCase {

	/**
	 * Resets settings before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wcr_settings', array_merge( wcr_default_settings(), array( 'retention_days' => 30 ) ) );
	}

	/**
	 * Inserts a cart with a given age and status.
	 *
	 * @param string $status    Cart status.
	 * @param int    $days_ago  Age in days for last_activity_at and recovered_at.
	 * @param string $email     Cart email.
	 * @return int
	 */
	protected function seed_cart( $status, $days_ago, $email = 'shopper@example.com' ) {
		global $wpdb;

		$stamp = gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );

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
				'cart_total'       => 9.0,
				'currency'         => 'USD',
				'status'           => $status,
				'recovered_at'     => 'recovered' === $status ? $stamp : null,
				'last_activity_at' => $stamp,
				'created_at'       => $stamp,
				'updated_at'       => $stamp,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Stale non-recovered carts and their sends and events are purged.
	 *
	 * @return void
	 */
	public function test_purges_stale_non_recovered() {
		global $wpdb;
		$cart_id = $this->seed_cart( 'abandoned', 40 );

		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 1,
				'status'        => 'sent',
				'scheduled_for' => wcr_now(),
				'created_at'    => wcr_now(),
				'updated_at'    => wcr_now(),
			)
		);
		wcr_log_event( $cart_id, null, 'abandoned' );

		( new WCR_Privacy() )->cleanup();

		$sends_table = wcr_table( 'sends' );
		$this->assertNull( wcr_get_cart( $cart_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion.
		$sends = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sends_table} WHERE cart_id = %d", $cart_id ) );
		$this->assertSame( 0, $sends );
	}

	/**
	 * Recent non-recovered carts are kept.
	 *
	 * @return void
	 */
	public function test_keeps_recent_carts() {
		$cart_id = $this->seed_cart( 'abandoned', 1 );

		( new WCR_Privacy() )->cleanup();

		$this->assertNotNull( wcr_get_cart( $cart_id ) );
	}

	/**
	 * Stale recovered carts are anonymized, keeping totals.
	 *
	 * @return void
	 */
	public function test_anonymizes_stale_recovered() {
		$cart_id = $this->seed_cart( 'recovered', 40 );

		( new WCR_Privacy() )->cleanup();

		$cart = wcr_get_cart( $cart_id );
		$this->assertSame( 'anonymized', $cart->status );
		$this->assertNull( $cart->email );
		$this->assertNull( $cart->cart_contents );
	}

	/**
	 * The exporter returns cart records for an email.
	 *
	 * @return void
	 */
	public function test_exporter_returns_records() {
		$this->seed_cart( 'abandoned', 1, 'export@example.com' );

		$result = ( new WCR_Privacy() )->export_personal_data( 'export@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['data'] );
	}

	/**
	 * The exporter includes the stored cart contents, the user's own data.
	 *
	 * @return void
	 */
	public function test_exporter_includes_cart_contents() {
		$this->seed_cart( 'abandoned', 1, 'contents@example.com' );

		$result = ( new WCR_Privacy() )->export_personal_data( 'contents@example.com' );

		$fields = array();

		foreach ( $result['data'] as $group ) {
			foreach ( $group['data'] as $field ) {
				$fields[ $field['name'] ] = $field['value'];
			}
		}

		$this->assertArrayHasKey( 'Cart contents', $fields );
		$this->assertNotEmpty( $fields['Cart contents'] );
	}

	/**
	 * A recovered cart still within the retention window is not anonymized.
	 *
	 * @return void
	 */
	public function test_keeps_recent_recovered() {
		$cart_id = $this->seed_cart( 'recovered', 1, 'recent-recovered@example.com' );

		( new WCR_Privacy() )->cleanup();

		$cart = wcr_get_cart( $cart_id );
		$this->assertSame( 'recovered', $cart->status );
		$this->assertSame( 'recent-recovered@example.com', $cart->email );
	}

	/**
	 * The eraser anonymizes cart records for an email.
	 *
	 * @return void
	 */
	public function test_eraser_anonymizes_records() {
		$cart_id = $this->seed_cart( 'abandoned', 1, 'erase@example.com' );

		$result = ( new WCR_Privacy() )->erase_personal_data( 'erase@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( 'anonymized', wcr_get_cart( $cart_id )->status );
		$this->assertNull( wcr_get_cart( $cart_id )->email );
	}
}
