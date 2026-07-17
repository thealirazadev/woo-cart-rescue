<?php
/**
 * Plugin Name: WooCommerce Cart Rescue
 * Plugin URI:  https://github.com/thealirazadev/woo-cart-rescue
 * Description: Consent-gated abandoned cart recovery for WooCommerce with signed restore links, a race-safe email sequence, and a GDPR data lifecycle.
 * Version:     1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 * License:     MIT
 * License URI: https://opensource.org/license/mit/
 * Text Domain: woo-cart-rescue
 * Domain Path: /languages
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCR_VERSION', '1.0.0' );
define( 'WCR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCR_URL', plugin_dir_url( __FILE__ ) );

$wcr_functions_file = WCR_PATH . 'includes/wcr-functions.php';

if ( ! is_readable( $wcr_functions_file ) ) {
	return;
}

require_once $wcr_functions_file;

/**
 * Loads and starts plugin components once WooCommerce is available.
 *
 * @return void
 */
function wcr_boot_plugin() {
	$wcr_plugin_file = WCR_PATH . 'includes/class-wcr-plugin.php';

	if ( ! is_readable( $wcr_plugin_file ) ) {
		return;
	}

	require_once $wcr_plugin_file;

	if ( ! class_exists( 'WCR_Plugin' ) ) {
		return;
	}

	$wcr_plugin = WCR_Plugin::get_instance();
	$wcr_plugin->register_hooks();
}

add_action( 'plugins_loaded', 'wcr_boot_plugin', 20 );
