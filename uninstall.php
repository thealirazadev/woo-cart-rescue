<?php
/**
 * Uninstall routine: drops tables, deletes options, and unschedules actions.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wcr_tables = array( 'wcr_carts', 'wcr_sends', 'wcr_events', 'wcr_optouts' );

foreach ( $wcr_tables as $wcr_table ) {
	$wcr_table_name = $wpdb->prefix . $wcr_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted prefixed table name; identifiers cannot be bound.
	$wpdb->query( "DROP TABLE IF EXISTS `{$wcr_table_name}`" );
}

$wcr_options = array(
	'wcr_settings',
	'wcr_db_version',
	'wcr_token_secret',
	'woocommerce_wcr_recovery_step_1_settings',
	'woocommerce_wcr_recovery_step_2_settings',
	'woocommerce_wcr_recovery_step_3_settings',
);

foreach ( $wcr_options as $wcr_option ) {
	delete_option( $wcr_option );
}

delete_transient( 'wcr_activation_blocked' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'wcr_abandonment_sweep', 'wcr_send_step', 'wcr_retention_cleanup' ) as $wcr_hook ) {
		as_unschedule_all_actions( $wcr_hook, array(), 'woo-cart-rescue' );
	}
}
