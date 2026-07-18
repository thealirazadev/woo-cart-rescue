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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Enqueues the admin stylesheet on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wcr-admin', WCR_URL . 'assets/css/admin.css', array(), WCR_VERSION );
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
		add_settings_field( 'wcr_token_ttl_days', __( 'Restore link lifetime (days)', 'woo-cart-rescue' ), array( $this, 'field_token_ttl_days' ), self::PAGE_SECTION, 'wcr_general' );
		add_settings_field( 'wcr_attribution_days', __( 'Attribution window (days)', 'woo-cart-rescue' ), array( $this, 'field_attribution_days' ), self::PAGE_SECTION, 'wcr_general' );
		add_settings_field( 'wcr_consent_label', __( 'Consent checkbox label', 'woo-cart-rescue' ), array( $this, 'field_consent_label' ), self::PAGE_SECTION, 'wcr_general' );

		add_settings_section(
			'wcr_steps',
			__( 'Recovery email steps', 'woo-cart-rescue' ),
			array( $this, 'render_steps_intro' ),
			self::PAGE_SECTION
		);

		foreach ( array( 1, 2, 3 ) as $step ) {
			add_settings_field(
				'wcr_step_' . $step,
				/* translators: %d: recovery step number. */
				sprintf( __( 'Step %d', 'woo-cart-rescue' ), $step ),
				array( $this, 'field_step' ),
				self::PAGE_SECTION,
				'wcr_steps',
				array( 'step' => $step )
			);
		}
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
	 * Renders the restore-link lifetime field.
	 *
	 * @return void
	 */
	public function field_token_ttl_days() {
		$settings = wcr_get_settings();
		?>
		<input type="number" id="wcr_token_ttl_days" name="wcr_settings[token_ttl_days]" min="1" max="365" step="1" value="<?php echo esc_attr( (string) $settings['token_ttl_days'] ); ?>" />
		<p class="description"><?php esc_html_e( 'How long a restore link stays valid after it is sent (1 to 365 days).', 'woo-cart-rescue' ); ?></p>
		<?php
	}

	/**
	 * Renders the attribution-window field.
	 *
	 * @return void
	 */
	public function field_attribution_days() {
		$settings = wcr_get_settings();
		?>
		<input type="number" id="wcr_attribution_days" name="wcr_settings[attribution_days]" min="0" max="365" step="1" value="<?php echo esc_attr( (string) $settings['attribution_days'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Days after a restore click during which an order counts as recovered (0 to 365).', 'woo-cart-rescue' ); ?></p>
		<?php
	}

	/**
	 * Renders the steps section description.
	 *
	 * @return void
	 */
	public function render_steps_intro() {
		echo '<p>' . esc_html__( 'Up to three reminders. Step 1 is delayed from abandonment; steps 2 and 3 from the previous send. Disabled steps are skipped.', 'woo-cart-rescue' ) . '</p>';
	}

	/**
	 * Renders one step's enable toggle and delay field.
	 *
	 * @param array $args Field args with the step number.
	 * @return void
	 */
	public function field_step( $args ) {
		$step   = isset( $args['step'] ) ? (int) $args['step'] : 1;
		$config = wcr_get_step_config( $step );
		?>
		<label for="wcr_step_<?php echo esc_attr( (string) $step ); ?>_enabled">
			<input type="checkbox" id="wcr_step_<?php echo esc_attr( (string) $step ); ?>_enabled" name="wcr_settings[steps][<?php echo esc_attr( (string) $step ); ?>][enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> />
			<?php esc_html_e( 'Send this reminder', 'woo-cart-rescue' ); ?>
		</label>
		<p>
			<label for="wcr_step_<?php echo esc_attr( (string) $step ); ?>_delay"><?php esc_html_e( 'Delay (minutes):', 'woo-cart-rescue' ); ?></label>
			<input type="number" id="wcr_step_<?php echo esc_attr( (string) $step ); ?>_delay" name="wcr_settings[steps][<?php echo esc_attr( (string) $step ); ?>][delay]" min="1" max="43200" step="1" value="<?php echo esc_attr( (string) $config['delay'] ); ?>" />
		</p>
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
	 * Renders the plugin page with Settings and Report tabs.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection on a capability-gated admin page.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tab = in_array( $tab, array( 'settings', 'report' ), true ) ? $tab : 'settings';
		?>
		<div class="wrap wcr-admin">
			<h1><?php esc_html_e( 'Cart Rescue', 'woo-cart-rescue' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'woo-cart-rescue' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=report' ) ); ?>" class="nav-tab <?php echo 'report' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Report', 'woo-cart-rescue' ); ?></a>
			</nav>
			<?php
			if ( 'report' === $tab ) {
				$this->render_report_tab();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the settings form.
	 *
	 * @return void
	 */
	protected function render_settings_tab() {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::OPTION_GROUP );
			do_settings_sections( self::PAGE_SECTION );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Renders the recovery report for a date range.
	 *
	 * @return void
	 */
	protected function render_report_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only date filter on a capability-gated admin page.
		$from = isset( $_GET['wcr_from'] ) ? sanitize_text_field( wp_unslash( $_GET['wcr_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only date filter on a capability-gated admin page.
		$to = isset( $_GET['wcr_to'] ) ? sanitize_text_field( wp_unslash( $_GET['wcr_to'] ) ) : '';

		$from = $this->normalize_date( $from, gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ) );
		$to   = $this->normalize_date( $to, gmdate( 'Y-m-d' ) );

		$data = $this->get_report_data( $from, $to );
		?>
		<form method="get" class="wcr-report-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="report" />
			<label for="wcr_from"><?php esc_html_e( 'From', 'woo-cart-rescue' ); ?></label>
			<input type="date" id="wcr_from" name="wcr_from" value="<?php echo esc_attr( $from ); ?>" />
			<label for="wcr_to"><?php esc_html_e( 'To', 'woo-cart-rescue' ); ?></label>
			<input type="date" id="wcr_to" name="wcr_to" value="<?php echo esc_attr( $to ); ?>" />
			<?php submit_button( __( 'Filter', 'woo-cart-rescue' ), 'secondary', '', false ); ?>
		</form>

		<?php if ( $data['has_data'] ) : ?>
			<ul class="wcr-stat-cards">
				<li class="wcr-card">
					<span class="wcr-card-label"><?php esc_html_e( 'Carts abandoned', 'woo-cart-rescue' ); ?></span>
					<span class="wcr-card-value"><?php echo esc_html( number_format_i18n( $data['abandoned'] ) ); ?></span>
				</li>
				<li class="wcr-card">
					<span class="wcr-card-label"><?php esc_html_e( 'Emails sent', 'woo-cart-rescue' ); ?></span>
					<span class="wcr-card-value"><?php echo esc_html( number_format_i18n( $data['sent_total'] ) ); ?></span>
					<span class="wcr-card-note">
						<?php
						printf(
							/* translators: 1: step 1 count, 2: step 2 count, 3: step 3 count. */
							esc_html__( 'Step 1: %1$s, Step 2: %2$s, Step 3: %3$s', 'woo-cart-rescue' ),
							esc_html( number_format_i18n( $data['sent_by_step'][1] ) ),
							esc_html( number_format_i18n( $data['sent_by_step'][2] ) ),
							esc_html( number_format_i18n( $data['sent_by_step'][3] ) )
						);
						?>
					</span>
				</li>
				<li class="wcr-card">
					<span class="wcr-card-label"><?php esc_html_e( 'Recovered orders', 'woo-cart-rescue' ); ?></span>
					<span class="wcr-card-value"><?php echo esc_html( number_format_i18n( $data['recovered'] ) ); ?></span>
				</li>
				<li class="wcr-card">
					<span class="wcr-card-label"><?php esc_html_e( 'Recovered revenue', 'woo-cart-rescue' ); ?></span>
					<span class="wcr-card-value"><?php echo wp_kses_post( wc_price( $data['revenue'] ) ); ?></span>
				</li>
				<li class="wcr-card">
					<span class="wcr-card-label"><?php esc_html_e( 'Recovery rate', 'woo-cart-rescue' ); ?></span>
					<span class="wcr-card-value"><?php echo esc_html( $data['rate'] . '%' ); ?></span>
				</li>
			</ul>
			<p class="wcr-report-note"><?php esc_html_e( 'Opens: not tracked.', 'woo-cart-rescue' ); ?></p>
		<?php else : ?>
			<p class="wcr-empty"><?php esc_html_e( 'No cart activity in this range yet.', 'woo-cart-rescue' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Validates a Y-m-d date string, falling back to a default.
	 *
	 * @param string $value    Raw date.
	 * @param string $fallback Default Y-m-d.
	 * @return string
	 */
	protected function normalize_date( $value, $fallback ) {
		$date = DateTime::createFromFormat( 'Y-m-d', $value );

		if ( $date && $date->format( 'Y-m-d' ) === $value ) {
			return $value;
		}

		return $fallback;
	}

	/**
	 * Aggregates report figures for a date range.
	 *
	 * @param string $from Start date Y-m-d.
	 * @param string $to   End date Y-m-d.
	 * @return array
	 */
	public function get_report_data( $from, $to ) {
		global $wpdb;

		$carts  = wcr_table( 'carts' );
		$sends  = wcr_table( 'sends' );
		$events = wcr_table( 'events' );

		$start = $from . ' 00:00:00';
		$end   = $to . ' 23:59:59';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table names; values are prepared.
		$abandoned = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE type = 'abandoned' AND created_at BETWEEN %s AND %s", $start, $end )
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT step, COUNT(*) AS total FROM {$sends} WHERE status = 'sent' AND sent_at BETWEEN %s AND %s GROUP BY step", $start, $end )
		);

		$recovered = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$carts} WHERE recovered_order_id IS NOT NULL AND recovered_at BETWEEN %s AND %s", $start, $end )
		);

		$revenue = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(recovered_total),0) FROM {$carts} WHERE recovered_order_id IS NOT NULL AND recovered_at BETWEEN %s AND %s", $start, $end )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$sent_by_step = array(
			1 => 0,
			2 => 0,
			3 => 0,
		);

		foreach ( (array) $rows as $row ) {
			$step = (int) $row->step;

			if ( isset( $sent_by_step[ $step ] ) ) {
				$sent_by_step[ $step ] = (int) $row->total;
			}
		}

		$sent_total = array_sum( $sent_by_step );
		$rate       = $abandoned > 0 ? round( ( $recovered / $abandoned ) * 100, 1 ) : 0.0;

		return array(
			'has_data'     => ( $abandoned > 0 || $sent_total > 0 || $recovered > 0 ),
			'abandoned'    => $abandoned,
			'sent_total'   => $sent_total,
			'sent_by_step' => $sent_by_step,
			'recovered'    => $recovered,
			'revenue'      => $revenue,
			'rate'         => $rate,
		);
	}
}
