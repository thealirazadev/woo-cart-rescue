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
 * Checks whether WooCommerce is loaded in the current request.
 *
 * @return bool
 */
function wcr_has_woocommerce() {
	return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
}

/**
 * Checks whether WooCommerce is in an active plugin list.
 *
 * @return bool
 */
function wcr_has_active_woocommerce() {
	$active_plugins = get_option( 'active_plugins', array() );

	if ( ! is_array( $active_plugins ) ) {
		$active_plugins = array();
	}

	if ( in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
		return true;
	}

	if ( ! is_multisite() ) {
		return false;
	}

	$network_plugins = get_site_option( 'active_sitewide_plugins', array() );

	if ( ! is_array( $network_plugins ) ) {
		return false;
	}

	return isset( $network_plugins['woocommerce/woocommerce.php'] );
}

/**
 * Prevents activation when WooCommerce is unavailable.
 *
 * @return void
 */
function wcr_activate() {
	if ( ! wcr_has_active_woocommerce() ) {
		wcr_log( 'error', 'Activation was rejected because WooCommerce was unavailable.' );

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			$wcr_plugin_functions = ABSPATH . 'wp-admin/includes/plugin.php';

			if ( is_readable( $wcr_plugin_functions ) ) {
				require_once $wcr_plugin_functions;
			}
		}

		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ), true );
		}

		set_transient( 'wcr_activation_blocked', 1, MINUTE_IN_SECONDS );
		return;
	}

	$wcr_install_file = WCR_PATH . 'includes/class-wcr-install.php';

	if ( is_readable( $wcr_install_file ) ) {
		require_once $wcr_install_file;
	}

	if ( class_exists( 'WCR_Install' ) ) {
		WCR_Install::activate();
	} else {
		wcr_log( 'error', 'The install class was unavailable during activation.' );
	}
}

/**
 * Shows the dependency notice after a blocked activation and clears it once.
 *
 * @return void
 */
function wcr_maybe_show_activation_notice() {
	if ( ! get_transient( 'wcr_activation_blocked' ) ) {
		return;
	}

	delete_transient( 'wcr_activation_blocked' );
	add_action( 'admin_notices', 'wcr_render_dependency_notice' );
}

/**
 * Warns when WooCommerce is missing at bootstrap so the plugin no-ops instead of fataling.
 *
 * @return void
 */
function wcr_check_woocommerce() {
	if ( wcr_has_woocommerce() ) {
		return;
	}

	wcr_log( 'error', 'WooCommerce was unavailable during plugin bootstrap.' );
	add_action( 'admin_notices', 'wcr_render_dependency_notice' );
}

/**
 * Renders the WooCommerce dependency notice.
 *
 * @return void
 */
function wcr_render_dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'WooCommerce Cart Rescue requires WooCommerce to be installed and active.', 'woo-cart-rescue' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Loads and starts plugin components once WooCommerce is available.
 *
 * @return void
 */
function wcr_boot_plugin() {
	if ( ! wcr_has_woocommerce() ) {
		return;
	}

	$wcr_plugin_file = WCR_PATH . 'includes/class-wcr-plugin.php';

	if ( ! is_readable( $wcr_plugin_file ) ) {
		wcr_log( 'error', 'The orchestrator class file was unavailable.' );
		return;
	}

	require_once $wcr_plugin_file;

	if ( ! class_exists( 'WCR_Plugin' ) ) {
		wcr_log( 'error', 'The orchestrator class could not be loaded.' );
		return;
	}

	$wcr_plugin = WCR_Plugin::get_instance();
	$wcr_plugin->register_hooks();
}

register_activation_hook( __FILE__, 'wcr_activate' );
add_action( 'admin_init', 'wcr_maybe_show_activation_notice' );
add_action( 'plugins_loaded', 'wcr_check_woocommerce', 1 );
add_action( 'plugins_loaded', 'wcr_boot_plugin', 20 );
