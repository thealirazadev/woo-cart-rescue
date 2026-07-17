<?php
/**
 * Shared data-access and utility functions.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a structured log entry through the WooCommerce logger.
 *
 * Context must contain scalars only (ids, hashes, codes) and never personal
 * data. Degrades to a silent no-op when the WooCommerce logger is unavailable.
 *
 * @param string $level   One of the WooCommerce/PSR log levels (error, warning, info, ...).
 * @param string $message Human-readable message, no personal data.
 * @param array  $context Structured scalar context merged with the log source.
 * @return void
 */
function wcr_log( $level, $message, $context = array() ) {
	if ( ! is_string( $message ) || '' === trim( $message ) ) {
		return;
	}

	if ( ! is_array( $context ) ) {
		$context = array();
	}

	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	$wcr_logger = wc_get_logger();

	if ( ! is_object( $wcr_logger ) || ! method_exists( $wcr_logger, 'log' ) ) {
		return;
	}

	$wcr_logger->log( (string) $level, $message, array_merge( array( 'source' => 'woo-cart-rescue' ), $context ) );
}

/**
 * Returns the plugin's default settings.
 *
 * Single source of truth for defaults; every consumer reads through
 * wcr_get_settings() so no feature invents its own default inline.
 *
 * @return array
 */
function wcr_default_settings() {
	return array(
		'enabled'        => true,
		'idle_window'    => 60,
		'retention_days' => 30,
		'consent_label'  => __( 'Email me a reminder if I do not finish checking out.', 'woo-cart-rescue' ),
	);
}

/**
 * Returns the stored settings merged over the defaults.
 *
 * @return array
 */
function wcr_get_settings() {
	$stored = get_option( 'wcr_settings', array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return wp_parse_args( $stored, wcr_default_settings() );
}

/**
 * Resolves a plugin table name from a fixed whitelist.
 *
 * Table names never come from user input; this is the single place they are
 * built, so every query interpolates a trusted value.
 *
 * @param string $name One of carts, sends, events, optouts.
 * @return string Fully-qualified table name, or empty string when unknown.
 */
function wcr_table( $name ) {
	global $wpdb;

	$allowed = array( 'carts', 'sends', 'events', 'optouts' );

	if ( ! in_array( $name, $allowed, true ) ) {
		return '';
	}

	return $wpdb->prefix . 'wcr_' . $name;
}

/**
 * Returns the current UTC time in MySQL DATETIME format.
 *
 * All plugin timestamps are stored in UTC so idle-window and TTL comparisons
 * against unix time are consistent regardless of the site timezone.
 *
 * @return string
 */
function wcr_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Serializes a WooCommerce cart into a compact, storable item list.
 *
 * Capped at 100 line items (documented cap). Stores only the identifiers needed
 * to rebuild the cart plus the quantity and line total for email rendering.
 *
 * @param WC_Cart $cart Cart to serialize.
 * @return array List of item arrays.
 */
function wcr_serialize_cart( $cart ) {
	$items = array();

	if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
		return $items;
	}

	$count = 0;

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( $count >= 100 ) {
			break;
		}

		if ( ! is_array( $cart_item ) ) {
			continue;
		}

		$variation = array();

		if ( isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
			foreach ( $cart_item['variation'] as $attr_key => $attr_value ) {
				$variation[ (string) $attr_key ] = (string) $attr_value;
			}
		}

		$items[] = array(
			'product_id'   => isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0,
			'variation_id' => isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0,
			'variation'    => $variation,
			'quantity'     => isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0,
			'line_total'   => isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0,
		);

		++$count;
	}

	return $items;
}

/**
 * Appends a row to the append-only event log.
 *
 * Meta must contain scalars only and never personal data.
 *
 * @param int      $cart_id Cart row id.
 * @param int|null $send_id Send row id, or null.
 * @param string   $type    Event type from the documented set.
 * @param array    $meta    Optional scalar detail.
 * @return void
 */
function wcr_log_event( $cart_id, $send_id, $type, $meta = array() ) {
	global $wpdb;

	$table = wcr_table( 'events' );

	if ( '' === $table ) {
		return;
	}

	$inserted = $wpdb->insert(
		$table,
		array(
			'cart_id'    => absint( $cart_id ),
			'send_id'    => $send_id ? absint( $send_id ) : null,
			'type'       => (string) $type,
			'meta'       => empty( $meta ) ? null : wp_json_encode( $meta ),
			'created_at' => wcr_now(),
		),
		array( '%d', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		wcr_log( 'error', 'Failed to write an event row.', array( 'type' => (string) $type ) );
	}
}

/**
 * Hashes an email for the opt-out suppression list.
 *
 * Lowercased and trimmed first so address variants map to one hash. The plain
 * address is never stored anywhere in the plugin's tables.
 *
 * @param string $email Email address.
 * @return string Lowercase sha256 hex hash.
 */
function wcr_email_hash( $email ) {
	return hash( 'sha256', strtolower( trim( (string) $email ) ) );
}

/**
 * Checks whether an email is on the opt-out suppression list.
 *
 * @param string $email Email address.
 * @return bool
 */
function wcr_is_opted_out( $email ) {
	global $wpdb;

	$table = wcr_table( 'optouts' );

	if ( '' === $table ) {
		return false;
	}

	$hash = wcr_email_hash( $email );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
	$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $hash ) );

	return ! empty( $found );
}

/**
 * Clamps an integer into an inclusive range.
 *
 * @param mixed $value Raw value.
 * @param int   $min   Lower bound.
 * @param int   $max   Upper bound.
 * @return int
 */
function wcr_clamp( $value, $min, $max ) {
	$value = (int) $value;

	if ( $value < $min ) {
		return $min;
	}

	if ( $value > $max ) {
		return $max;
	}

	return $value;
}

/**
 * Sanitizes and bounds the settings option on save.
 *
 * Merges over the current settings so fields not present in a given form are
 * preserved. Out-of-range numbers are clamped and a settings-error notice
 * explains each clamp when the admin notices API is available.
 *
 * @param mixed $input Raw posted settings.
 * @return array Clean settings.
 */
function wcr_sanitize_settings( $input ) {
	$current  = wcr_get_settings();
	$defaults = wcr_default_settings();
	$clean    = $current;

	if ( ! is_array( $input ) ) {
		$input = array();
	}

	$clean['enabled'] = ! empty( $input['enabled'] );

	$idle                 = isset( $input['idle_window'] ) ? absint( $input['idle_window'] ) : (int) $current['idle_window'];
	$clean['idle_window'] = wcr_clamp( $idle, 5, 10080 );

	if ( $clean['idle_window'] !== $idle && function_exists( 'add_settings_error' ) ) {
		add_settings_error( 'wcr_settings', 'wcr_idle_clamped', __( 'The idle window was adjusted to the allowed range (5 to 10080 minutes).', 'woo-cart-rescue' ), 'warning' );
	}

	$retention               = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : (int) $current['retention_days'];
	$clean['retention_days'] = wcr_clamp( $retention, 1, 3650 );

	if ( $clean['retention_days'] !== $retention && function_exists( 'add_settings_error' ) ) {
		add_settings_error( 'wcr_settings', 'wcr_retention_clamped', __( 'The retention window was adjusted to the allowed range (1 to 3650 days).', 'woo-cart-rescue' ), 'warning' );
	}

	$label = isset( $input['consent_label'] ) ? trim( sanitize_text_field( $input['consent_label'] ) ) : (string) $current['consent_label'];

	if ( '' === $label ) {
		$label = $defaults['consent_label'];
	}

	$clean['consent_label'] = $label;

	return $clean;
}
