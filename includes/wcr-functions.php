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
