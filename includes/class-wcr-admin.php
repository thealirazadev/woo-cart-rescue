<?php
/**
 * Admin UI: the settings tab (Settings API) and the server-rendered report tab.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the settings and report screens under the WooCommerce menu.
 */
class WCR_Admin {

	const MENU_SLUG    = 'wcr-dashboard';
	const OPTION_GROUP = 'wcr_settings_group';
	const PAGE_SECTION = 'wcr-settings-page';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Restricts the settings option page to shop managers.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Adds the plugin submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Cart Rescue', 'woo-cart-rescue' ),
			__( 'Cart Rescue', 'woo-cart-rescue' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the settings, section, and fields through the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'wcr_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'wcr_sanitize_settings',
				'default'           => wcr_default_settings(),
			)
		);

		add_settings_section(
			'wcr_general',
			__( 'General', 'woo-cart-rescue' ),
			array( $this, 'render_general_intro' ),
			self::PAGE_SECTION
		);

		add_settings_field( 'wcr_enabled', __( 'Enable cart recovery', 'woo-cart-rescue' ), array( $this, 'field_enabled' ), self::PAGE_SECTION, 'wcr_general' );
		add_settings_field( 'wcr_idle_window', __( 'Idle window (minutes)', 'woo-cart-rescue' ), array( $this, 'field_idle_window' ), self::PAGE_SECTION, 'wcr_general' );
		add_settings_field( 'wcr_retention_days', __( 'Retention (days)', 'woo-cart-rescue' ), array( $this, 'field_retention_days' ), self::PAGE_SECTION, 'wcr_general' );
		add_settings_field( 'wcr_consent_label', __( 'Consent checkbox label', 'woo-cart-rescue' ), array( $this, 'field_consent_label' ), self::PAGE_SECTION, 'wcr_general' );
	}

	/**
	 * Renders the section description.
	 *
	 * @return void
	 */
	public function render_general_intro() {
		echo '<p>' . esc_html__( 'Control how carts are captured and when idle carts become eligible for recovery.', 'woo-cart-rescue' ) . '</p>';
	}

	/**
	 * Renders the enable checkbox.
	 *
	 * @return void
	 */
	public function field_enabled() {
		$settings = wcr_get_settings();
		?>
		<label for="wcr_enabled">
			<input type="checkbox" id="wcr_enabled" name="wcr_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
			<?php esc_html_e( 'Track carts and send recovery emails.', 'woo-cart-rescue' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the idle-window field.
	 *
	 * @return void
	 */
	public function field_idle_window() {
		$settings = wcr_get_settings();
		?>
		<input type="number" id="wcr_idle_window" name="wcr_settings[idle_window]" min="5" max="10080" step="1" value="<?php echo esc_attr( (string) $settings['idle_window'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Minutes of inactivity before a cart is considered abandoned (5 to 10080).', 'woo-cart-rescue' ); ?></p>
		<?php
	}

	/**
	 * Renders the retention field.
	 *
	 * @return void
	 */
	public function field_retention_days() {
		$settings = wcr_get_settings();
		?>
		<input type="number" id="wcr_retention_days" name="wcr_settings[retention_days]" min="1" max="3650" step="1" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Days to keep non-recovered cart data before it is purged (1 to 3650).', 'woo-cart-rescue' ); ?></p>
		<?php
	}

	/**
	 * Renders the consent-label field.
	 *
	 * @return void
	 */
	public function field_consent_label() {
		$settings = wcr_get_settings();
		?>
		<input type="text" class="regular-text" id="wcr_consent_label" name="wcr_settings[consent_label]" value="<?php echo esc_attr( (string) $settings['consent_label'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Shown next to the checkout consent checkbox. Name what is stored and why.', 'woo-cart-rescue' ); ?></p>
		<?php
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cart Rescue', 'woo-cart-rescue' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SECTION );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
