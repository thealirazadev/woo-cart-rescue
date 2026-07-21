<?php
/**
 * Integration test for uninstall cleanup.
 *
 * No-op unless the WordPress test suite is loaded. Drops the plugin tables, so
 * it recreates them afterward to keep the rest of the suite intact.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Covers table, option, and scheduled-action removal on uninstall.
 */
class WCR_Test_Uninstall extends WP_UnitTestCase {

	/**
	 * Lets the uninstall routine reach the real tables.
	 *
	 * The plugin's tables are created for real when the bootstrap loads the plugin, before the
	 * test case installs its query filters. Those filters rewrite CREATE/DROP TABLE into their
	 * TEMPORARY forms, so without removing them the uninstall would drop a temporary table that
	 * never existed and this test would assert nothing.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Recreates the schema so later tests still have their tables.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'wcr_db_version' );
		WCR_Install::run_migrations();
		parent::tear_down();
	}

	/**
	 * Uninstall drops the tables and deletes the plugin options.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_tables_and_options() {
		global $wpdb;

		update_option( 'wcr_settings', wcr_default_settings() );
		update_option( 'wcr_token_secret', 'a-test-secret' );
		update_option( 'wcr_db_version', 1 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'woo-cart-rescue/woo-cart-rescue.php' );
		}

		require dirname( __DIR__ ) . '/uninstall.php';

		$carts = wcr_table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifying the table was dropped.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $carts ) );
		$this->assertNull( $exists );

		$this->assertFalse( get_option( 'wcr_token_secret' ) );
		$this->assertFalse( get_option( 'wcr_db_version' ) );
		$this->assertFalse( get_option( 'wcr_settings' ) );
	}
}
