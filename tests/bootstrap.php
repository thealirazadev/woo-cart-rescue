<?php
/**
 * PHPUnit bootstrap.
 *
 * Runs in one of two modes. When the WordPress test suite is installed
 * (integration mode) it loads WordPress, WooCommerce, and the plugin so the
 * hook/table/Action Scheduler tests can run. When it is absent (unit mode) it
 * loads lightweight WordPress stubs plus the plugin's pure-PHP classes so the
 * token, settings-sanitization, and merge-tag tests run without a database.
 * Integration-only test classes early-return when WP_UnitTestCase is absent.
 *
 * @package Woo_Cart_Rescue
 */

$wcr_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wcr_tests_dir ) {
	$wcr_tests_dir = '/tmp/wordpress-tests-lib';
}

$wcr_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( file_exists( $wcr_polyfills ) ) {
	require_once $wcr_polyfills;
}

if ( file_exists( $wcr_tests_dir . '/includes/functions.php' ) ) {

	// Integration mode: full WordPress + WooCommerce + plugin.
	if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
	}

	require_once $wcr_tests_dir . '/includes/functions.php';

	/**
	 * Loads WooCommerce and this plugin for integration tests.
	 *
	 * @return void
	 */
	function wcr_tests_load_plugin() {
		$woocommerce_file = getenv( 'WCR_TESTS_WOOCOMMERCE' );

		if ( ! $woocommerce_file ) {
			$woocommerce_file = '/tmp/wordpress/wp-content/plugins/woocommerce/woocommerce.php';
		}

		if ( file_exists( $woocommerce_file ) ) {
			require $woocommerce_file;

			if ( class_exists( 'WC_Install' ) ) {
				WC_Install::create_tables();
			}
		}

		require dirname( __DIR__ ) . '/woo-cart-rescue.php';
	}

	tests_add_filter( 'muplugins_loaded', 'wcr_tests_load_plugin' );

	require $wcr_tests_dir . '/includes/bootstrap.php';

} else {

	// Unit mode: stubs plus the plugin's pure-PHP classes only.
	require_once __DIR__ . '/wp-stubs.php';

	if ( ! defined( 'WCR_VERSION' ) ) {
		define( 'WCR_VERSION', '1.0.0' );
	}

	if ( ! defined( 'WCR_PATH' ) ) {
		define( 'WCR_PATH', dirname( __DIR__ ) . '/' );
	}

	require_once dirname( __DIR__ ) . '/includes/wcr-functions.php';
	require_once dirname( __DIR__ ) . '/includes/class-wcr-token.php';
}
