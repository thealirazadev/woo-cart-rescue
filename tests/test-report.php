<?php
/**
 * Integration tests for the report aggregation queries.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers WCR_Admin::get_report_data() figures.
 */
class WCR_Test_Report extends WP_UnitTestCase {

	/**
	 * Seeds an abandoned event, a sent send, and a recovered cart in-range.
	 *
	 * @return void
	 */
	protected function seed_range() {
		global $wpdb;

		$now = wcr_now();

		$wpdb->insert(
			wcr_table( 'carts' ),
			array(
				'cart_key'           => 'key-report',
				'email'              => 'r@example.com',
				'consent'            => 1,
				'cart_contents'      => wp_json_encode( array( array( 'product_id' => 1 ) ) ),
				'cart_total'         => 50.0,
				'currency'           => 'USD',
				'status'             => 'recovered',
				'recovered_order_id' => 123,
				'recovered_total'    => 50.0,
				'recovered_at'       => $now,
				'last_activity_at'   => $now,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$cart_id = (int) $wpdb->insert_id;

		wcr_log_event( $cart_id, null, 'abandoned' );

		$wpdb->insert(
			wcr_table( 'sends' ),
			array(
				'cart_id'       => $cart_id,
				'step'          => 1,
				'status'        => 'sent',
				'scheduled_for' => $now,
				'sent_at'       => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
	}

	/**
	 * The report counts abandoned, sent, recovered, and revenue in range.
	 *
	 * @return void
	 */
	public function test_report_totals_match_seeded_rows() {
		$this->seed_range();

		$admin = new WCR_Admin();
		$data  = $admin->get_report_data( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		$this->assertTrue( $data['has_data'] );
		$this->assertSame( 1, $data['abandoned'] );
		$this->assertSame( 1, $data['sent_by_step'][1] );
		$this->assertSame( 1, $data['recovered'] );
		$this->assertEquals( 50.0, $data['revenue'] );
		$this->assertSame( 100.0, $data['rate'] );
	}

	/**
	 * An empty range reports no data.
	 *
	 * @return void
	 */
	public function test_empty_range_has_no_data() {
		$admin = new WCR_Admin();
		$data  = $admin->get_report_data( '2000-01-01', '2000-01-02' );

		$this->assertFalse( $data['has_data'] );
		$this->assertSame( 0, $data['recovered'] );
	}
}
