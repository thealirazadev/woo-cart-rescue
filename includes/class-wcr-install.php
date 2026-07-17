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
	const DB_VERSION = 1;

	/**
	 * Runs the full activation routine: migrations, secret, and scheduled actions.
	 *
	 * @return void
	 */
	public static function activate() {
		self::run_migrations();
		self::ensure_token_secret();
		self::schedule_actions();
	}

	/**
	 * Registers the recurring sweep and daily cleanup actions, avoiding duplicates.
	 *
	 * @return void
	 */
	public static function schedule_actions() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			wcr_log( 'error', 'Action Scheduler was unavailable while scheduling recurring actions.' );
			return;
		}

		self::schedule_recurring( 'wcr_abandonment_sweep', 15 * MINUTE_IN_SECONDS );
		self::schedule_recurring( 'wcr_retention_cleanup', DAY_IN_SECONDS );
	}

	/**
	 * Schedules a single recurring action in the plugin's group when absent.
	 *
	 * @param string $hook     Action hook name.
	 * @param int    $interval Interval in seconds.
	 * @return void
	 */
	private static function schedule_recurring( $hook, $interval ) {
		if ( false !== as_next_scheduled_action( $hook, array(), 'woo-cart-rescue' ) ) {
			return;
		}

		$action_id = as_schedule_recurring_action( time() + $interval, $interval, $hook, array(), 'woo-cart-rescue' );

		if ( ! $action_id ) {
			wcr_log( 'error', 'Failed to schedule a recurring action.', array( 'hook' => $hook ) );
		}
	}

	/**
	 * Generates the per-site HMAC signing secret once and stores it non-autoloaded.
	 *
	 * A dedicated 32-byte secret, not a WordPress salt, so unrelated salt rotation
	 * never invalidates outstanding recovery links. Only regenerated when missing.
	 *
	 * @return void
	 */
	public static function ensure_token_secret() {
		$existing = get_option( 'wcr_token_secret' );

		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			wcr_log( 'error', 'Failed to generate the token signing secret.' );
			return;
		}

		add_option( 'wcr_token_secret', $secret, '', false );
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

	/**
	 * Migration 1: the four core tables (carts, sends, events, optouts).
	 *
	 * @return void
	 */
	private static function migrate_1() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$schemas = array();

		$schemas[] = "CREATE TABLE {$prefix}wcr_carts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_key VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			email VARCHAR(191) NULL,
			consent TINYINT(1) NOT NULL DEFAULT 0,
			cart_contents LONGTEXT NULL,
			cart_total DECIMAL(19,4) NOT NULL DEFAULT 0,
			currency CHAR(3) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			recovered_order_id BIGINT UNSIGNED NULL,
			recovered_total DECIMAL(19,4) NULL,
			recovered_at DATETIME NULL,
			last_activity_at DATETIME NOT NULL,
			abandoned_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cart_key (cart_key),
			KEY status_activity (status, last_activity_at),
			KEY email (email)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$prefix}wcr_sends (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_id BIGINT UNSIGNED NOT NULL,
			step TINYINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			token_hash CHAR(64) NULL,
			token_expires_at DATETIME NULL,
			token_used_at DATETIME NULL,
			scheduled_for DATETIME NOT NULL,
			sent_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cart_step (cart_id, step),
			KEY status_scheduled (status, scheduled_for)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$prefix}wcr_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_id BIGINT UNSIGNED NOT NULL,
			send_id BIGINT UNSIGNED NULL,
			type VARCHAR(32) NOT NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY type_created (type, created_at),
			KEY cart_id (cart_id)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$prefix}wcr_optouts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email_hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_hash (email_hash)
		) {$charset_collate};";

		foreach ( $schemas as $schema ) {
			dbDelta( $schema );
		}
	}
}
