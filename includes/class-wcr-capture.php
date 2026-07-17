<?php
/**
 * Cart capture: logged-in tracking, the checkout consent field, and guest capture AJAX.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records carts into wcr_carts under the consent rules.
 */
class WCR_Capture {

	/**
	 * Registers capture hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_cart_updated', array( $this, 'capture_logged_in' ) );
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_consent_field' ) );
		add_action( 'wp_ajax_wcr_capture_guest', array( $this, 'handle_guest_capture' ) );
		add_action( 'wp_ajax_nopriv_wcr_capture_guest', array( $this, 'handle_guest_capture' ) );
	}

	/**
	 * Handles the guest capture AJAX request under the consent gate.
	 *
	 * Enforces nonce, email validity, and explicit consent server-side. An
	 * opted-out email returns the same success body while silently skipping the
	 * write, so the endpoint never reveals opt-out status.
	 *
	 * @return void
	 */
	public function handle_guest_capture() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'wcr_capture' ) ) {
			wcr_log( 'info', 'Guest capture rejected.', array( 'code' => 'invalid_nonce' ) );
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Your session has expired. Please refresh the page and try again.', 'woo-cart-rescue' ),
				),
				403
			);
		}

		$settings = wcr_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error(
				array(
					'code'    => 'disabled',
					'message' => __( 'Cart saving is currently unavailable.', 'woo-cart-rescue' ),
				),
				400
			);
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_email',
					'message' => __( 'Please enter a valid email address.', 'woo-cart-rescue' ),
				),
				400
			);
		}

		$consent = isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '';

		if ( '1' !== $consent ) {
			wp_send_json_error(
				array(
					'code'    => 'consent_required',
					'message' => __( 'Please tick the consent box to save your cart.', 'woo-cart-rescue' ),
				),
				400
			);
		}

		// Opted-out addresses get the same success response but no row is written.
		if ( ! wcr_is_opted_out( $email ) ) {
			$this->upsert( $email, 0, true );
		}

		wp_send_json_success( array( 'captured' => true ) );
	}

	/**
	 * Renders the unchecked-by-default consent checkbox on the classic checkout.
	 *
	 * The checkbox gates capture only; it never blocks checkout and has no error
	 * state. A privacy-policy link is appended when the site defines one.
	 *
	 * @return void
	 */
	public function render_consent_field() {
		$settings = wcr_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$label = isset( $settings['consent_label'] ) ? trim( (string) $settings['consent_label'] ) : '';

		if ( '' === $label ) {
			$defaults = wcr_default_settings();
			$label    = $defaults['consent_label'];
		}

		$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		?>
		<p class="form-row wcr-consent-row" id="wcr_consent_field">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="wcr_consent">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="wcr_consent" id="wcr_consent" value="1" />
				<span><?php echo esc_html( $label ); ?></span>
				<?php if ( '' !== $privacy_url ) : ?>
					<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'woo-cart-rescue' ); ?></a>
				<?php endif; ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Captures a logged-in customer's cart on cart changes.
	 *
	 * @return void
	 */
	public function capture_logged_in() {
		$settings = wcr_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$this->upsert( sanitize_email( $user->user_email ), get_current_user_id(), true );
	}

	/**
	 * Inserts or updates the cart row for the current session.
	 *
	 * @param string $email    Customer email.
	 * @param int    $user_id  User id, or 0 for guests.
	 * @param bool   $consent  Whether consent is granted.
	 * @return int Cart row id, or 0 on failure or skip.
	 */
	public function upsert( $email, $user_id, $consent ) {
		global $wpdb;

		if ( ! function_exists( 'WC' ) ) {
			return 0;
		}

		$wc = WC();

		if ( ! $wc || null === $wc->cart || null === $wc->session ) {
			return 0;
		}

		$cart = $wc->cart;

		if ( $cart->is_empty() ) {
			return 0;
		}

		$cart_key = (string) $wc->session->get_customer_id();

		if ( '' === $cart_key ) {
			return 0;
		}

		$contents = wcr_serialize_cart( $cart );

		if ( empty( $contents ) ) {
			return 0;
		}

		$json = wp_json_encode( $contents );

		if ( false === $json ) {
			wcr_log( 'error', 'Failed to encode cart contents during capture.' );
			return 0;
		}

		$table    = wcr_table( 'carts' );
		$total    = (float) $cart->get_total( 'edit' );
		$currency = get_woocommerce_currency();
		$now      = wcr_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE cart_key = %s", $cart_key ) );

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- In-place update of the captured cart row.
			$updated = $wpdb->update(
				$table,
				array(
					'user_id'          => $user_id ? absint( $user_id ) : null,
					'email'            => $email,
					'consent'          => $consent ? 1 : 0,
					'cart_contents'    => $json,
					'cart_total'       => $total,
					'currency'         => $currency,
					'last_activity_at' => $now,
					'updated_at'       => $now,
				),
				array( 'id' => absint( $existing_id ) ),
				array( '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wcr_log( 'error', 'Failed to update a cart row.', array( 'cart_id' => absint( $existing_id ) ) );
				return 0;
			}

			return absint( $existing_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- First capture of a new session cart.
		$inserted = $wpdb->insert(
			$table,
			array(
				'cart_key'         => $cart_key,
				'user_id'          => $user_id ? absint( $user_id ) : null,
				'email'            => $email,
				'consent'          => $consent ? 1 : 0,
				'cart_contents'    => $json,
				'cart_total'       => $total,
				'currency'         => $currency,
				'status'           => 'active',
				'last_activity_at' => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wcr_log( 'error', 'Failed to insert a cart row.' );
			return 0;
		}

		$cart_id = (int) $wpdb->insert_id;
		wcr_log_event( $cart_id, null, 'captured' );

		return $cart_id;
	}
}
