<?php
/**
 * Recovery email: a WC_Email subclass registered once per step.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * Renders and sends a recovery email step through the WooCommerce mailer.
 *
 * Rendered entirely from data passed to trigger(), never from WC()->cart, so it
 * is safe to run in Action Scheduler context with no session.
 */
class WCR_Email_Recovery extends WC_Email {

	/**
	 * Step number 1..3.
	 *
	 * @var int
	 */
	public $step;

	/**
	 * Template render context for the current trigger.
	 *
	 * @var array
	 */
	protected $render = array();

	/**
	 * Merge-tag values for subject and heading.
	 *
	 * @var array
	 */
	protected $merge_context = array();

	/**
	 * Constructor.
	 *
	 * @param int $step Step number 1..3.
	 */
	public function __construct( $step = 1 ) {
		$this->step           = max( 1, min( 3, (int) $step ) );
		$this->id             = 'wcr_recovery_step_' . $this->step;
		$this->customer_email = true;
		/* translators: %d: recovery step number. */
		$this->title          = sprintf( __( 'Cart recovery: step %d', 'woo-cart-rescue' ), $this->step );
		$this->description    = __( 'Reminder sent to a shopper who left items in their cart.', 'woo-cart-rescue' );
		$this->template_html  = 'emails/recovery.php';
		$this->template_plain = 'emails/plain/recovery.php';
		$this->template_base  = WCR_PATH . 'templates/';

		parent::__construct();

		// Triggered from the plugin's scheduler, not from a WooCommerce order status.
		$this->manual = true;
	}

	/**
	 * Default subject, owner-editable under WooCommerce > Settings > Emails.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'You left something in your cart', 'woo-cart-rescue' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your cart is waiting', 'woo-cart-rescue' );
	}

	/**
	 * Subject with merge tags resolved.
	 *
	 * @return string
	 */
	public function get_subject() {
		return wcr_render_merge_tags( $this->get_option( 'subject', $this->get_default_subject() ), $this->merge_context );
	}

	/**
	 * Heading with merge tags resolved.
	 *
	 * @return string
	 */
	public function get_heading() {
		return wcr_render_merge_tags( $this->get_option( 'heading', $this->get_default_heading() ), $this->merge_context );
	}

	/**
	 * Sends the recovery email from a data context.
	 *
	 * @param array $args Render data: email, first_name, cart_contents, cart_total, currency, restore_url, unsubscribe_url.
	 * @return bool Whether the mail was accepted for delivery.
	 */
	public function trigger( $args ) {
		$this->setup_locale();

		$args = wp_parse_args(
			$args,
			array(
				'email'           => '',
				'first_name'      => '',
				'cart_contents'   => array(),
				'cart_total'      => 0,
				'currency'        => '',
				'restore_url'     => '',
				'unsubscribe_url' => '',
			)
		);

		$this->recipient = $args['email'];

		if ( ! $this->is_enabled() || ! is_email( $this->get_recipient() ) ) {
			$this->restore_locale();
			return false;
		}

		$first_name = ( '' !== trim( (string) $args['first_name'] ) ) ? $args['first_name'] : __( 'there', 'woo-cart-rescue' );

		list( $items, $extra_items ) = $this->build_items( (array) $args['cart_contents'] );

		$cart_total = wc_price( (float) $args['cart_total'], array( 'currency' => (string) $args['currency'] ) );

		$this->merge_context = array(
			'customer_first_name' => $first_name,
			'site_title'          => $this->get_blogname(),
			'cart_total'          => wp_strip_all_tags( $cart_total ),
		);

		$this->render = array(
			'email'           => $this,
			'email_heading'   => $this->get_heading(),
			'first_name'      => $first_name,
			'items'           => $items,
			'extra_items'     => $extra_items,
			'cart_total'      => $cart_total,
			'restore_url'     => (string) $args['restore_url'],
			'unsubscribe_url' => (string) $args['unsubscribe_url'],
			'button_color'    => get_option( 'woocommerce_email_base_color', '#7f54b3' ),
		);

		$content = $this->ensure_unsubscribe_footer( $this->get_content(), (string) $args['unsubscribe_url'] );

		$result = $this->send( $this->get_recipient(), $this->get_subject(), $content, $this->get_headers(), $this->get_attachments() );

		$this->restore_locale();

		return (bool) $result;
	}

	/**
	 * Renders the HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->render, '', $this->template_base );
	}

	/**
	 * Renders the plain-text body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->render, '', $this->template_base );
	}

	/**
	 * Builds the display item list from stored cart contents, capped at 20.
	 *
	 * @param array $contents Decoded cart contents.
	 * @return array{0:array,1:int} Rendered items and the overflow count.
	 */
	protected function build_items( $contents ) {
		$items       = array();
		$rendered    = 0;
		$total_count = count( $contents );

		foreach ( $contents as $line ) {
			if ( $rendered >= 20 ) {
				break;
			}

			if ( ! is_array( $line ) ) {
				continue;
			}

			$product_id = ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : ( isset( $line['product_id'] ) ? (int) $line['product_id'] : 0 );
			$name       = __( 'Item', 'woo-cart-rescue' );

			if ( $product_id && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );

				if ( $product ) {
					$name = $product->get_name();
				}
			}

			$items[] = array(
				'name'     => $name,
				'quantity' => isset( $line['quantity'] ) ? (int) $line['quantity'] : 0,
				'total'    => wc_price( isset( $line['line_total'] ) ? (float) $line['line_total'] : 0 ),
			);

			++$rendered;
		}

		$extra = $total_count > 20 ? $total_count - 20 : 0;

		return array( $items, $extra );
	}

	/**
	 * Appends an unsubscribe link when a template override omitted it.
	 *
	 * @param string $content         Rendered content.
	 * @param string $unsubscribe_url Unsubscribe link.
	 * @return string
	 */
	protected function ensure_unsubscribe_footer( $content, $unsubscribe_url ) {
		if ( '' === $unsubscribe_url || false !== strpos( $content, $unsubscribe_url ) ) {
			return $content;
		}

		return $content . '<p style="font-size:12px;"><a href="' . esc_url( $unsubscribe_url ) . '">' . esc_html__( 'Unsubscribe from cart reminders', 'woo-cart-rescue' ) . '</a></p>';
	}
}
