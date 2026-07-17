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
	 * Registers component hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {}
}
