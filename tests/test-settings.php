<?php
/**
 * Unit tests for settings sanitization and clamping.
 *
 * Pure PHP, no WordPress load required.
 *
 * @package Woo_Cart_Rescue
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers wcr_sanitize_settings() bounds, gating, and label handling.
 */
class WCR_Test_Settings extends TestCase {

	/**
	 * Below-minimum idle windows clamp up to the floor.
	 *
	 * @return void
	 */
	public function test_idle_window_clamped_below_minimum() {
		$clean = wcr_sanitize_settings( array( 'idle_window' => 0 ) );
		$this->assertSame( 5, $clean['idle_window'] );
	}

	/**
	 * Above-maximum idle windows clamp down to the ceiling.
	 *
	 * @return void
	 */
	public function test_idle_window_clamped_above_maximum() {
		$clean = wcr_sanitize_settings( array( 'idle_window' => 100000 ) );
		$this->assertSame( 10080, $clean['idle_window'] );
	}

	/**
	 * In-range idle windows pass through unchanged.
	 *
	 * @return void
	 */
	public function test_idle_window_within_range_preserved() {
		$clean = wcr_sanitize_settings( array( 'idle_window' => 90 ) );
		$this->assertSame( 90, $clean['idle_window'] );
	}

	/**
	 * Retention days are bounded on both ends.
	 *
	 * @return void
	 */
	public function test_retention_days_bounded() {
		$low = wcr_sanitize_settings( array( 'retention_days' => 0 ) );
		$this->assertSame( 1, $low['retention_days'] );

		$high = wcr_sanitize_settings( array( 'retention_days' => 99999 ) );
		$this->assertSame( 3650, $high['retention_days'] );
	}

	/**
	 * The enable flag mirrors checkbox presence.
	 *
	 * @return void
	 */
	public function test_enabled_reflects_checkbox_presence() {
		$off = wcr_sanitize_settings( array() );
		$this->assertFalse( $off['enabled'] );

		$on = wcr_sanitize_settings( array( 'enabled' => '1' ) );
		$this->assertTrue( $on['enabled'] );
	}

	/**
	 * A blank consent label reverts to the default.
	 *
	 * @return void
	 */
	public function test_empty_consent_label_falls_back_to_default() {
		$defaults = wcr_default_settings();
		$clean    = wcr_sanitize_settings( array( 'consent_label' => '   ' ) );
		$this->assertSame( $defaults['consent_label'], $clean['consent_label'] );
	}

	/**
	 * The consent label is stripped of markup.
	 *
	 * @return void
	 */
	public function test_consent_label_is_sanitized() {
		$clean = wcr_sanitize_settings( array( 'consent_label' => 'Save my cart <script>x</script>' ) );
		$this->assertStringNotContainsString( '<script>', $clean['consent_label'] );
	}

	/**
	 * The token lifetime is bounded to 1..365 days.
	 *
	 * @return void
	 */
	public function test_token_ttl_days_bounded() {
		$this->assertSame( 1, wcr_sanitize_settings( array( 'token_ttl_days' => 0 ) )['token_ttl_days'] );
		$this->assertSame( 365, wcr_sanitize_settings( array( 'token_ttl_days' => 5000 ) )['token_ttl_days'] );
	}

	/**
	 * Per-step enable flags and delays are sanitized and clamped.
	 *
	 * @return void
	 */
	public function test_steps_are_sanitized() {
		$clean = wcr_sanitize_settings(
			array(
				'steps' => array(
					1 => array(
						'enabled' => '1',
						'delay'   => 30,
					),
					2 => array( 'delay' => 999999 ),
					3 => array(
						'enabled' => '1',
						'delay'   => 0,
					),
				),
			)
		);

		$this->assertTrue( $clean['steps'][1]['enabled'] );
		$this->assertSame( 30, $clean['steps'][1]['delay'] );
		$this->assertFalse( $clean['steps'][2]['enabled'] );
		$this->assertSame( 43200, $clean['steps'][2]['delay'] );
		$this->assertSame( 1, $clean['steps'][3]['delay'] );
	}
}
