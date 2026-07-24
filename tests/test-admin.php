<?php
/**
 * Integration tests for admin screen accessibility markup.
 *
 * No-op unless the WordPress test suite is loaded.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers labelled settings controls and the report's accessible summary.
 */
class WCR_Test_Admin extends WP_UnitTestCase {

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
	 * Each scalar settings control has a `for` label and described help text.
	 *
	 * @return void
	 */
	public function test_settings_controls_are_labelled_and_described() {
		$admin = new WCR_Admin();
		$admin->register_settings();

		ob_start();
		do_settings_sections( WCR_Admin::PAGE_SECTION );
		$html = ob_get_clean();

		foreach ( array( 'wcr_idle_window', 'wcr_retention_days', 'wcr_token_ttl_days', 'wcr_attribution_days', 'wcr_consent_label' ) as $field ) {
			$this->assertStringContainsString( '<label for="' . $field . '">', $html, $field . ' has no associated label.' );
			$this->assertStringContainsString( 'aria-describedby="' . $field . '_description"', $html, $field . ' input is not tied to its help text.' );
			$this->assertStringContainsString( 'id="' . $field . '_description"', $html, $field . ' help text has no id to reference.' );
		}
	}

	/**
	 * The report summary list carries an accessible name when data is present.
	 *
	 * @return void
	 */
	public function test_report_summary_has_accessible_name() {
		global $wpdb;

		$now = wcr_now();
		$wpdb->insert(
			wcr_table( 'carts' ),
			array(
				'cart_key'           => 'key-a11y',
				'email'              => 'a11y@example.com',
				'consent'            => 1,
				'cart_contents'      => wp_json_encode( array( array( 'product_id' => 1 ) ) ),
				'cart_total'         => 40.0,
				'currency'           => 'USD',
				'status'             => 'recovered',
				'recovered_order_id' => 456,
				'recovered_total'    => 40.0,
				'recovered_at'       => $now,
				'last_activity_at'   => $now,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		wcr_log_event( (int) $wpdb->insert_id, null, 'abandoned' );

		$admin  = new WCR_Admin();
		$render = new ReflectionMethod( WCR_Admin::class, 'render_report_tab' );
		$render->setAccessible( true );

		ob_start();
		$render->invoke( $admin );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="wcr-stat-cards" aria-label=', $html );
		$this->assertStringContainsString( 'screen-reader-text', $html );
	}
}
