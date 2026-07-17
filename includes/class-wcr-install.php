<?php
/**
 * Activation, versioned schema migrations, secret generation, and action registration.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and upgrades the plugin's database schema and scheduled actions.
 */
class WCR_Install {

	/**
	 * Highest migration number the current code knows how to apply.
	 */
	const DB_VERSION = 0;

	/**
	 * Runs the full activation routine: migrations, secret, and scheduled actions.
	 *
	 * @return void
	 */
	public static function activate() {
		self::run_migrations();
	}

	/**
	 * Applies pending migrations in order, guarded by the wcr_db_version option.
	 *
	 * Idempotent: safe to call on every load and on repeated activation. Each
	 * migrate_N() is applied once, in ascending order, and the version option is
	 * advanced after each so an interrupted run resumes cleanly.
	 *
	 * @return void
	 */
	public static function run_migrations() {
		$current = (int) get_option( 'wcr_db_version', 0 );
		$target  = (int) self::DB_VERSION;

		if ( $current >= $target ) {
			return;
		}

		for ( $version = $current + 1; $version <= $target; $version++ ) {
			$method = 'migrate_' . $version;

			if ( method_exists( __CLASS__, $method ) ) {
				self::$method();
			}

			update_option( 'wcr_db_version', $version, false );
		}
	}
}
