<?php
/**
 * Data lifecycle: retention cleanup and the WordPress privacy exporter/eraser.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purges and anonymizes cart data and integrates the core privacy tools.
 */
class WCR_Privacy {

	/**
	 * Registers the cleanup handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wcr_retention_cleanup', array( $this, 'cleanup' ) );
	}

	/**
	 * Purges stale non-recovered carts and anonymizes stale recovered carts.
	 *
	 * @return void
	 */
	public function cleanup() {
		$settings  = wcr_get_settings();
		$retention = wcr_clamp( $settings['retention_days'], 1, 3650 );
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );

		$purged     = $this->purge_stale( $cutoff );
		$anonymized = $this->anonymize_recovered( $cutoff );

		wcr_log(
			'info',
			'Retention cleanup complete.',
			array(
				'purged'     => $purged,
				'anonymized' => $anonymized,
			)
		);
	}

	/**
	 * Deletes non-recovered carts older than the cutoff with their sends and events.
	 *
	 * @param string $cutoff UTC MySQL datetime.
	 * @return int Number of carts deleted.
	 */
	protected function purge_stale( $cutoff ) {
		global $wpdb;

		$carts  = wcr_table( 'carts' );
		$sends  = wcr_table( 'sends' );
		$events = wcr_table( 'events' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Trusted whitelisted table names; values are prepared.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$carts} WHERE last_activity_at < %s AND status NOT IN ('recovered','anonymized')", $cutoff )
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sends} WHERE cart_id IN ({$placeholders})", $ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE cart_id IN ({$placeholders})", $ids ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$carts} WHERE id IN ({$placeholders})", $ids ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return (int) $deleted;
	}

	/**
	 * Anonymizes recovered carts older than the cutoff, keeping aggregate figures.
	 *
	 * @param string $cutoff UTC MySQL datetime.
	 * @return int Number of carts anonymized.
	 */
	protected function anonymize_recovered( $cutoff ) {
		global $wpdb;

		$carts = wcr_table( 'carts' );
		$now   = wcr_now();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$affected = $wpdb->query(
			$wpdb->prepare( "UPDATE {$carts} SET email = NULL, user_id = NULL, cart_contents = NULL, status = 'anonymized', updated_at = %s WHERE status = 'recovered' AND recovered_at IS NOT NULL AND recovered_at < %s", $now, $cutoff )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $affected;
	}
}
