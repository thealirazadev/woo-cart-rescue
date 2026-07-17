<?php
/**
 * Plugin orchestrator: instantiates components and registers their hooks.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's components together.
 */
class WCR_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WCR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return WCR_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor for the singleton.
	 */
	private function __construct() {}

	/**
	 * Loads component classes and registers their hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$this->load_dependencies();

		if ( class_exists( 'WCR_Install' ) ) {
			WCR_Install::run_migrations();
		}
	}

	/**
	 * Requires the component class files.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$wcr_files = array(
			'includes/class-wcr-install.php',
		);

		foreach ( $wcr_files as $wcr_file ) {
			$wcr_path = WCR_PATH . $wcr_file;

			if ( is_readable( $wcr_path ) ) {
				require_once $wcr_path;
			} else {
				wcr_log( 'error', 'A component class file was unavailable.', array( 'file' => basename( $wcr_path ) ) );
			}
		}
	}
}
