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
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Registers suggested privacy-policy text with the core policy guide.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = wp_kses_post(
			wpautop(
				__( 'This store recovers abandoned carts. When you are signed in, or when you enter your email at checkout and tick the consent box, we store your email address, the contents of your cart, and activity timestamps so we can send you a reminder with a link back to your cart. Every reminder includes an unsubscribe link. Saved cart data is deleted or anonymized after a retention period, and can be exported or erased through this site\'s personal data tools.', 'woo-cart-rescue' )
			)
		);

		wp_add_privacy_policy_content( 'WooCommerce Cart Rescue', $content );
	}

	/**
	 * Registers the personal-data exporter with the core privacy tools.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['woo-cart-rescue'] = array(
			'exporter_friendly_name' => __( 'WooCommerce Cart Rescue', 'woo-cart-rescue' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Registers the personal-data eraser with the core privacy tools.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['woo-cart-rescue'] = array(
			'eraser_friendly_name' => __( 'WooCommerce Cart Rescue', 'woo-cart-rescue' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Exports the cart records held for an email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number (unused; all rows returned at once).
	 * @return array
	 */
	public function export_personal_data( $email, $page = 1 ) {
		unset( $page );

		global $wpdb;

		$carts = wcr_table( 'carts' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$carts} WHERE email = %s", $email ) );

		$items = array();

		foreach ( (array) $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Status', 'woo-cart-rescue' ),
					'value' => $row->status,
				),
				array(
					'name'  => __( 'Cart total', 'woo-cart-rescue' ),
					'value' => $row->cart_total . ' ' . $row->currency,
				),
				array(
					'name'  => __( 'Captured at', 'woo-cart-rescue' ),
					'value' => $row->created_at,
				),
				array(
					'name'  => __( 'Last activity at', 'woo-cart-rescue' ),
					'value' => $row->last_activity_at,
				),
			);

			$contents = $this->format_cart_contents( $row->cart_contents );

			// Stored cart contents are the visitor's own personal data; include them when present
			// (anonymized rows carry a null value and add nothing here).
			if ( '' !== $contents ) {
				$data[] = array(
					'name'  => __( 'Cart contents', 'woo-cart-rescue' ),
					'value' => $contents,
				);
			}

			$items[] = array(
				'group_id'    => 'wcr_carts',
				'group_label' => __( 'Abandoned cart records', 'woo-cart-rescue' ),
				'item_id'     => 'wcr-cart-' . (int) $row->id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Renders stored cart contents as a readable summary for export.
	 *
	 * Uses the product name when the product still exists, falling back to the
	 * stored id, and is null-safe for anonymized or malformed JSON.
	 *
	 * @param string|null $json Stored cart_contents JSON.
	 * @return string Comma-separated item summary, empty when there is nothing to show.
	 */
	protected function format_cart_contents( $json ) {
		$contents = json_decode( (string) $json, true );

		if ( ! is_array( $contents ) || array() === $contents ) {
			return '';
		}

		$lines = array();

		foreach ( $contents as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
			$variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
			$quantity     = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;
			$lookup       = $variation_id ? $variation_id : $product_id;
			$name         = '';

			if ( $lookup && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $lookup );

				if ( $product ) {
					$name = $product->get_name();
				}
			}

			if ( '' === $name ) {
				/* translators: %d: stored product id. */
				$name = sprintf( __( 'Product #%d', 'woo-cart-rescue' ), $lookup );
			}

			/* translators: 1: product name, 2: quantity. */
			$lines[] = sprintf( __( '%1$s (qty %2$d)', 'woo-cart-rescue' ), $name, $quantity );
		}

		return implode( ', ', $lines );
	}

	/**
	 * Anonymizes the cart records held for an email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number (unused; all rows handled at once).
	 * @return array
	 */
	public function erase_personal_data( $email, $page = 1 ) {
		unset( $page );

		global $wpdb;

		$carts = wcr_table( 'carts' );
		$now   = wcr_now();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$affected = $wpdb->query(
			$wpdb->prepare( "UPDATE {$carts} SET email = NULL, user_id = NULL, cart_contents = NULL, status = 'anonymized', updated_at = %s WHERE email = %s", $now, $email )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items_removed'  => ( $affected > 0 ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
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
