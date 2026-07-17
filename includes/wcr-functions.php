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
