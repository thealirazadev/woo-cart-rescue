<?php
/**
 * Abandonment sweep: marks idle carts abandoned and schedules the first send.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects abandoned carts and starts the recovery sequence.
 */
class WCR_Abandonment {

	/**
	 * Maximum carts processed per sweep run (documented cap).
	 */
	const BATCH = 200;

	/**
	 * Registers the sweep action handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wcr_abandonment_sweep', array( $this, 'sweep' ) );
	}

	/**
	 * Marks eligible idle carts abandoned and schedules their first send.
	 *
	 * @return void
	 */
	public function sweep() {
		$settings = wcr_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		global $wpdb;

		$table  = wcr_table( 'carts' );
		$idle   = wcr_clamp( $settings['idle_window'], 5, 10080 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $idle * MINUTE_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND last_activity_at < %s AND email IS NOT NULL AND email <> '' AND cart_contents IS NOT NULL ORDER BY last_activity_at ASC LIMIT %d",
				$cutoff,
				self::BATCH
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $candidates ) ) {
			return;
		}

		foreach ( $candidates as $cart ) {
			$this->maybe_abandon( $cart );
		}
	}

	/**
	 * Applies the remaining eligibility rules and abandons one cart.
	 *
	 * @param object $cart Cart row.
	 * @return void
	 */
	protected function maybe_abandon( $cart ) {
		$contents = json_decode( (string) $cart->cart_contents, true );

		if ( ! is_array( $contents ) || array() === $contents ) {
			return;
		}

		if ( wcr_is_opted_out( $cart->email ) ) {
			return;
		}

		if ( $this->has_order_since( $cart->email, (int) $cart->user_id, (string) $cart->created_at ) ) {
			return;
		}

		global $wpdb;

		$table = wcr_table( 'carts' );
		$now   = wcr_now();

		// Atomic transition: only abandon if still active, so concurrent activity wins.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'abandoned', abandoned_at = %s, updated_at = %s WHERE id = %d AND status = 'active'",
				$now,
				$now,
				absint( $cart->id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $updated ) {
			return;
		}

		wcr_log_event( (int) $cart->id, null, 'abandoned' );

		$this->schedule_first_step( (int) $cart->id, $now );
	}

	/**
	 * Schedules the first enabled, not-yet-sent step from the abandonment time.
	 *
	 * @param int    $cart_id Cart row id.
	 * @param string $from    UTC MySQL datetime the delay is measured from.
	 * @return void
	 */
	protected function schedule_first_step( $cart_id, $from ) {
		$config = wcr_get_step_config( 1 );

		if ( empty( $config['enabled'] ) ) {
			return;
		}

		$scheduled_for = gmdate( 'Y-m-d H:i:s', (int) strtotime( $from . ' UTC' ) + ( $config['delay'] * MINUTE_IN_SECONDS ) );

		wcr_enqueue_send( $cart_id, 1, $scheduled_for );
	}

	/**
	 * Whether the customer placed any order after the cart was captured.
	 *
	 * Uses the CRUD order query so it works with HPOS.
	 *
	 * @param string $email     Customer email.
	 * @param int    $user_id   User id, or 0.
	 * @param string $since_gmt UTC MySQL datetime of capture.
	 * @return bool
	 */
	protected function has_order_since( $email, $user_id, $since_gmt ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$since = (int) strtotime( $since_gmt . ' UTC' );

		$orders = wc_get_orders(
			array(
				'limit'        => 1,
				'return'       => 'ids',
				'date_created' => '>=' . $since,
				'customer'     => $user_id > 0 ? $user_id : $email,
			)
		);

		return ! empty( $orders );
	}
}
