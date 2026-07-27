<?php
/**
 * Shared data-access and utility functions.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a structured log entry through the WooCommerce logger.
 *
 * Context must contain scalars only (ids, hashes, codes) and never personal
 * data. Degrades to a silent no-op when the WooCommerce logger is unavailable.
 *
 * @param string $level   One of the WooCommerce/PSR log levels (error, warning, info, ...).
 * @param string $message Human-readable message, no personal data.
 * @param array  $context Structured scalar context merged with the log source.
 * @return void
 */
function wcr_log( $level, $message, $context = array() ) {
	if ( ! is_string( $message ) || '' === trim( $message ) ) {
		return;
	}

	if ( ! is_array( $context ) ) {
		$context = array();
	}

	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	$wcr_logger = wc_get_logger();

	if ( ! is_object( $wcr_logger ) || ! method_exists( $wcr_logger, 'log' ) ) {
		return;
	}

	$wcr_logger->log( (string) $level, $message, array_merge( array( 'source' => 'woo-cart-rescue' ), $context ) );
}

/**
 * Loads the plugin translation catalog.
 *
 * @return void
 */
function wcr_load_textdomain() {
	load_plugin_textdomain( 'woo-cart-rescue', false, dirname( plugin_basename( WCR_PATH . 'woo-cart-rescue.php' ) ) . '/languages' );
}

/**
 * Returns the plugin's default settings.
 *
 * Single source of truth for defaults; every consumer reads through
 * wcr_get_settings() so no feature invents its own default inline.
 *
 * @return array
 */
function wcr_default_settings() {
	return array(
		'enabled'              => true,
		'idle_window'          => 60,
		'retention_days'       => 30,
		'token_ttl_days'       => 7,
		'attribution_days'     => 7,
		'consent_label'        => __( 'Email me a reminder if I do not finish checking out.', 'woo-cart-rescue' ),
		'coupon_enabled'       => false,
		'coupon_type'          => 'percent',
		'coupon_amount'        => 10.0,
		'coupon_validity_days' => 7,
	);
}

/**
 * Returns the built-in per-step enable/delay defaults.
 *
 * Delays are in minutes: step 1 from abandonment, steps 2 and 3 from the
 * previous send.
 *
 * @return array
 */
function wcr_step_defaults() {
	return array(
		1 => array(
			'enabled' => true,
			'delay'   => 60,
			'coupon'  => false,
		),
		2 => array(
			'enabled' => true,
			'delay'   => 1440,
			'coupon'  => false,
		),
		3 => array(
			'enabled' => true,
			'delay'   => 4320,
			'coupon'  => true,
		),
	);
}

/**
 * Resolves the enable flag and delay for a step from settings over defaults.
 *
 * @param int $step Step number 1..3.
 * @return array{enabled:bool,delay:int,coupon:bool}
 */
function wcr_get_step_config( $step ) {
	$step     = (int) $step;
	$defaults = wcr_step_defaults();

	if ( ! isset( $defaults[ $step ] ) ) {
		return array(
			'enabled' => false,
			'delay'   => 0,
			'coupon'  => false,
		);
	}

	$settings = wcr_get_settings();
	$stored   = ( isset( $settings['steps'][ $step ] ) && is_array( $settings['steps'][ $step ] ) ) ? $settings['steps'][ $step ] : array();

	$enabled = isset( $stored['enabled'] ) ? (bool) $stored['enabled'] : $defaults[ $step ]['enabled'];
	$delay   = isset( $stored['delay'] ) ? wcr_clamp( $stored['delay'], 1, 43200 ) : $defaults[ $step ]['delay'];
	$coupon  = isset( $stored['coupon'] ) ? (bool) $stored['coupon'] : $defaults[ $step ]['coupon'];

	return array(
		'enabled' => $enabled,
		'delay'   => (int) $delay,
		'coupon'  => $coupon,
	);
}

/**
 * Returns the stored settings merged over the defaults.
 *
 * @return array
 */
function wcr_get_settings() {
	$stored = get_option( 'wcr_settings', array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return wp_parse_args( $stored, wcr_default_settings() );
}

/**
 * Resolves a plugin table name from a fixed whitelist.
 *
 * Table names never come from user input; this is the single place they are
 * built, so every query interpolates a trusted value.
 *
 * @param string $name One of carts, sends, events, optouts.
 * @return string Fully-qualified table name, or empty string when unknown.
 */
function wcr_table( $name ) {
	global $wpdb;

	$allowed = array( 'carts', 'sends', 'events', 'optouts' );

	if ( ! in_array( $name, $allowed, true ) ) {
		return '';
	}

	return $wpdb->prefix . 'wcr_' . $name;
}

/**
 * Returns the current UTC time in MySQL DATETIME format.
 *
 * All plugin timestamps are stored in UTC so idle-window and TTL comparisons
 * against unix time are consistent regardless of the site timezone.
 *
 * @return string
 */
function wcr_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Serializes a WooCommerce cart into a compact, storable item list.
 *
 * Capped at 100 line items (documented cap). Stores only the identifiers needed
 * to rebuild the cart plus the quantity and line total for email rendering.
 *
 * @param WC_Cart $cart Cart to serialize.
 * @return array List of item arrays.
 */
function wcr_serialize_cart( $cart ) {
	$items = array();

	if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
		return $items;
	}

	$count = 0;

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( $count >= 100 ) {
			break;
		}

		if ( ! is_array( $cart_item ) ) {
			continue;
		}

		$variation = array();

		if ( isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
			foreach ( $cart_item['variation'] as $attr_key => $attr_value ) {
				$variation[ (string) $attr_key ] = (string) $attr_value;
			}
		}

		$items[] = array(
			'product_id'   => isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0,
			'variation_id' => isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0,
			'variation'    => $variation,
			'quantity'     => isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0,
			'line_total'   => isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0,
		);

		++$count;
	}

	return $items;
}

/**
 * Appends a row to the append-only event log.
 *
 * Meta must contain scalars only and never personal data.
 *
 * @param int      $cart_id Cart row id.
 * @param int|null $send_id Send row id, or null.
 * @param string   $type    Event type from the documented set.
 * @param array    $meta    Optional scalar detail.
 * @return void
 */
function wcr_log_event( $cart_id, $send_id, $type, $meta = array() ) {
	global $wpdb;

	$table = wcr_table( 'events' );

	if ( '' === $table ) {
		return;
	}

	$inserted = $wpdb->insert(
		$table,
		array(
			'cart_id'    => absint( $cart_id ),
			'send_id'    => $send_id ? absint( $send_id ) : null,
			'type'       => (string) $type,
			'meta'       => empty( $meta ) ? null : wp_json_encode( $meta ),
			'created_at' => wcr_now(),
		),
		array( '%d', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		wcr_log( 'error', 'Failed to write an event row.', array( 'type' => (string) $type ) );
	}
}

/**
 * Schedules a recovery send for a cart step, resume-safe.
 *
 * Reuses the unique (cart_id, step) row. An already sent, scheduled, or sending
 * step is left untouched so a step is never re-sent or duplicated; a cancelled
 * or failed step is reset to scheduled so an interrupted sequence resumes.
 *
 * @param int    $cart_id       Cart row id.
 * @param int    $step          Step number 1..3.
 * @param string $scheduled_for UTC MySQL datetime to run at.
 * @return int Send row id, or 0 when skipped or on failure.
 */
function wcr_enqueue_send( $cart_id, $step, $scheduled_for ) {
	global $wpdb;

	$table = wcr_table( 'sends' );

	if ( '' === $table ) {
		return 0;
	}

	$cart_id = absint( $cart_id );
	$step    = absint( $step );
	$now     = wcr_now();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE cart_id = %d AND step = %d", $cart_id, $step ) );

	if ( $existing ) {
		if ( in_array( $existing->status, array( 'sent', 'scheduled', 'sending' ), true ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset a cancelled/failed step so the sequence can resume.
		$reset = $wpdb->update(
			$table,
			array(
				'status'           => 'scheduled',
				'scheduled_for'    => $scheduled_for,
				'token_hash'       => null,
				'token_expires_at' => null,
				'token_used_at'    => null,
				'sent_at'          => null,
				'updated_at'       => $now,
			),
			array( 'id' => absint( $existing->id ) ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $reset ) {
			wcr_log( 'error', 'Failed to reset a send row for resume.', array( 'send_id' => absint( $existing->id ) ) );
			return 0;
		}

		$send_id = absint( $existing->id );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- First send row for this cart step.
		$inserted = $wpdb->insert(
			$table,
			array(
				'cart_id'       => $cart_id,
				'step'          => $step,
				'status'        => 'scheduled',
				'scheduled_for' => $scheduled_for,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wcr_log(
				'error',
				'Failed to insert a send row.',
				array(
					'cart_id' => $cart_id,
					'step'    => $step,
				)
			);
			return 0;
		}

		$send_id = (int) $wpdb->insert_id;
	}

	if ( function_exists( 'as_schedule_single_action' ) ) {
		$timestamp = (int) strtotime( $scheduled_for . ' UTC' );
		$action_id = as_schedule_single_action( $timestamp, 'wcr_send_step', array( $send_id ), 'woo-cart-rescue' );

		if ( ! $action_id ) {
			wcr_log( 'error', 'Failed to schedule a send action.', array( 'send_id' => $send_id ) );
		}
	}

	return $send_id;
}

/**
 * Returns the first enabled step that has not yet been sent for a cart.
 *
 * Used to resume an interrupted sequence without re-sending a completed step.
 *
 * @param int $cart_id Cart row id.
 * @return int Step number 1..3, or 0 when none remain.
 */
function wcr_next_unsent_step( $cart_id ) {
	global $wpdb;

	$table   = wcr_table( 'sends' );
	$cart_id = absint( $cart_id );

	if ( '' === $table || 0 === $cart_id ) {
		return 0;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT step FROM {$table} WHERE cart_id = %d AND status = 'sent'", $cart_id ) );

	$sent = array();

	foreach ( (array) $rows as $row ) {
		$sent[ (int) $row->step ] = true;
	}

	for ( $step = 1; $step <= 3; $step++ ) {
		$config = wcr_get_step_config( $step );

		if ( empty( $config['enabled'] ) || isset( $sent[ $step ] ) ) {
			continue;
		}

		return $step;
	}

	return 0;
}

/**
 * Cancels every pending send for a cart and unschedules its actions.
 *
 * Targets scheduled and sending rows. The authoritative stop is the send-time
 * cart-status recheck in the worker; this is the cleanup that keeps the queue
 * from running at all.
 *
 * @param int $cart_id Cart row id.
 * @return void
 */
function wcr_cancel_pending_sends( $cart_id ) {
	global $wpdb;

	$table   = wcr_table( 'sends' );
	$cart_id = absint( $cart_id );

	if ( '' === $table || 0 === $cart_id ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
	$pending = $wpdb->get_results(
		$wpdb->prepare( "SELECT id FROM {$table} WHERE cart_id = %d AND status IN ('scheduled','sending')", $cart_id )
	);

	$cancelled = $wpdb->query(
		$wpdb->prepare( "UPDATE {$table} SET status = 'cancelled', updated_at = %s WHERE cart_id = %d AND status IN ('scheduled','sending')", wcr_now(), $cart_id )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( false === $cancelled ) {
		wcr_log( 'error', 'Failed to cancel pending sends.', array( 'cart_id' => $cart_id ) );
		return;
	}

	if ( ! empty( $pending ) && function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( $pending as $row ) {
			as_unschedule_all_actions( 'wcr_send_step', array( (int) $row->id ), 'woo-cart-rescue' );
		}
	}
}

/**
 * Hashes an email for the opt-out suppression list.
 *
 * Lowercased and trimmed first so address variants map to one hash. The plain
 * address is never stored anywhere in the plugin's tables.
 *
 * @param string $email Email address.
 * @return string Lowercase sha256 hex hash.
 */
function wcr_email_hash( $email ) {
	return hash( 'sha256', strtolower( trim( (string) $email ) ) );
}

/**
 * Checks whether an email is on the opt-out suppression list.
 *
 * @param string $email Email address.
 * @return bool
 */
function wcr_is_opted_out( $email ) {
	global $wpdb;

	$table = wcr_table( 'optouts' );

	if ( '' === $table ) {
		return false;
	}

	$hash = wcr_email_hash( $email );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
	$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $hash ) );

	return ! empty( $found );
}

/**
 * Loads a cart row by id.
 *
 * @param int $cart_id Cart row id.
 * @return object|null Row object, or null when absent.
 */
function wcr_get_cart( $cart_id ) {
	global $wpdb;

	$table = wcr_table( 'carts' );

	if ( '' === $table ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $cart_id ) ) );

	return $row ? $row : null;
}

/**
 * Loads a cart row by its unique session key.
 *
 * @param string $cart_key WooCommerce session customer id.
 * @return object|null Row object, or null when absent.
 */
function wcr_get_cart_by_key( $cart_key ) {
	global $wpdb;

	$table = wcr_table( 'carts' );

	if ( '' === $table ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_key = %s", (string) $cart_key ) );

	return $row ? $row : null;
}

/**
 * Loads a send row by id.
 *
 * @param int $send_id Send row id.
 * @return object|null Row object, or null when absent.
 */
function wcr_get_send( $send_id ) {
	global $wpdb;

	$table = wcr_table( 'sends' );

	if ( '' === $table ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; value is prepared.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $send_id ) ) );

	return $row ? $row : null;
}

/**
 * Creates a unique, single-use, expiring WooCommerce coupon for a send.
 *
 * The generated coupon carries the configured discount, a usage limit of one,
 * and an expiry set from the validity window. It is tagged with the
 * `_wcr_auto_generated` meta so the retention cleanup can find and delete it.
 *
 * @param int   $send_id  Send row id, stored on the coupon for auditing.
 * @param array $settings Plugin settings supplying type, amount, and validity.
 * @return string The coupon code on success, or an empty string when disabled,
 *                misconfigured, or the coupon could not be created.
 */
function wcr_generate_recovery_coupon( $send_id, $settings ) {
	if ( ! class_exists( 'WC_Coupon' ) ) {
		wcr_log( 'error', 'The WooCommerce coupon API was unavailable while generating a recovery coupon.', array( 'send_id' => absint( $send_id ) ) );
		return '';
	}

	$amount = isset( $settings['coupon_amount'] ) ? (float) $settings['coupon_amount'] : 0.0;

	if ( $amount <= 0 ) {
		return '';
	}

	$discount_type = ( isset( $settings['coupon_type'] ) && 'fixed' === $settings['coupon_type'] ) ? 'fixed_cart' : 'percent';
	$validity_days = wcr_clamp( isset( $settings['coupon_validity_days'] ) ? $settings['coupon_validity_days'] : 7, 1, 365 );
	$expires       = time() + ( $validity_days * DAY_IN_SECONDS );

	try {
		$code = 'wcr-' . bin2hex( random_bytes( 8 ) );
	} catch ( Exception $e ) {
		wcr_log( 'error', 'Failed to generate a coupon code.', array( 'send_id' => absint( $send_id ) ) );
		return '';
	}

	try {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( $expires );
		$coupon->set_description( __( 'Auto-generated abandoned cart recovery coupon.', 'woo-cart-rescue' ) );
		$coupon->update_meta_data( '_wcr_auto_generated', 'yes' );
		$coupon->update_meta_data( '_wcr_send_id', absint( $send_id ) );
		$coupon_id = $coupon->save();
	} catch ( Exception $e ) {
		wcr_log( 'error', 'Failed to create a recovery coupon.', array( 'send_id' => absint( $send_id ) ) );
		return '';
	}

	if ( ! $coupon_id ) {
		wcr_log( 'error', 'Recovery coupon did not persist.', array( 'send_id' => absint( $send_id ) ) );
		return '';
	}

	return $code;
}

/**
 * Formats the configured coupon discount as a short human-readable offer.
 *
 * Used in the recovery email to describe the incentive, for example "10% off"
 * or "$10.00 off". Returns an empty string when the amount is not positive.
 *
 * @param array  $settings Plugin settings supplying type and amount.
 * @param string $currency Currency code for a fixed-amount discount.
 * @return string
 */
function wcr_format_coupon_offer( $settings, $currency = '' ) {
	$amount = isset( $settings['coupon_amount'] ) ? (float) $settings['coupon_amount'] : 0.0;

	if ( $amount <= 0 ) {
		return '';
	}

	if ( isset( $settings['coupon_type'] ) && 'fixed' === $settings['coupon_type'] ) {
		$value = function_exists( 'wc_price' )
			? wp_strip_all_tags( wc_price( $amount, array( 'currency' => (string) $currency ) ) )
			: number_format( $amount, 2 );
	} else {
		$formatted = function_exists( 'number_format_i18n' ) ? number_format_i18n( $amount ) : (string) $amount;
		$value     = $formatted . '%';
	}

	/* translators: %s: discount amount, for example "10%" or "$10.00". */
	return sprintf( __( '%s off', 'woo-cart-rescue' ), $value );
}

/**
 * Substitutes {tag} placeholders in a template string. Pure.
 *
 * Unmatched tags are left untouched. Callers supply already-resolved values,
 * including any neutral fallback (for example an empty customer name).
 *
 * @param string $template Template containing {tag} placeholders.
 * @param array  $data     Map of tag name (without braces) to replacement value.
 * @return string
 */
function wcr_render_merge_tags( $template, $data ) {
	$template = (string) $template;

	if ( ! is_array( $data ) || array() === $data ) {
		return $template;
	}

	$search  = array();
	$replace = array();

	foreach ( $data as $key => $value ) {
		$search[]  = '{' . $key . '}';
		$replace[] = (string) $value;
	}

	return str_replace( $search, $replace, $template );
}

/**
 * Clamps an integer into an inclusive range.
 *
 * @param mixed $value Raw value.
 * @param int   $min   Lower bound.
 * @param int   $max   Upper bound.
 * @return int
 */
function wcr_clamp( $value, $min, $max ) {
	$value = (int) $value;

	if ( $value < $min ) {
		return $min;
	}

	if ( $value > $max ) {
		return $max;
	}

	return $value;
}

/**
 * Sanitizes and bounds the settings option on save.
 *
 * Merges over the current settings so fields not present in a given form are
 * preserved. Out-of-range numbers are clamped and a settings-error notice
 * explains each clamp when the admin notices API is available.
 *
 * @param mixed $input Raw posted settings.
 * @return array Clean settings.
 */
function wcr_sanitize_settings( $input ) {
	$current  = wcr_get_settings();
	$defaults = wcr_default_settings();
	$clean    = $current;

	if ( ! is_array( $input ) ) {
		$input = array();
	}

	$clean['enabled'] = ! empty( $input['enabled'] );

	$idle                 = isset( $input['idle_window'] ) ? absint( $input['idle_window'] ) : (int) $current['idle_window'];
	$clean['idle_window'] = wcr_clamp( $idle, 5, 10080 );

	if ( $clean['idle_window'] !== $idle && function_exists( 'add_settings_error' ) ) {
		add_settings_error( 'wcr_settings', 'wcr_idle_clamped', __( 'The idle window was adjusted to the allowed range (5 to 10080 minutes).', 'woo-cart-rescue' ), 'warning' );
	}

	$retention               = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : (int) $current['retention_days'];
	$clean['retention_days'] = wcr_clamp( $retention, 1, 3650 );

	if ( $clean['retention_days'] !== $retention && function_exists( 'add_settings_error' ) ) {
		add_settings_error( 'wcr_settings', 'wcr_retention_clamped', __( 'The retention window was adjusted to the allowed range (1 to 3650 days).', 'woo-cart-rescue' ), 'warning' );
	}

	$ttl                     = isset( $input['token_ttl_days'] ) ? absint( $input['token_ttl_days'] ) : (int) $current['token_ttl_days'];
	$clean['token_ttl_days'] = wcr_clamp( $ttl, 1, 365 );

	$attribution               = isset( $input['attribution_days'] ) ? absint( $input['attribution_days'] ) : (int) $current['attribution_days'];
	$clean['attribution_days'] = wcr_clamp( $attribution, 0, 365 );

	$label = isset( $input['consent_label'] ) ? trim( sanitize_text_field( $input['consent_label'] ) ) : (string) $current['consent_label'];

	if ( '' === $label ) {
		$label = $defaults['consent_label'];
	}

	$clean['consent_label'] = $label;

	$clean['coupon_enabled'] = ! empty( $input['coupon_enabled'] );

	$type                 = isset( $input['coupon_type'] ) ? sanitize_key( $input['coupon_type'] ) : (string) $current['coupon_type'];
	$clean['coupon_type'] = in_array( $type, array( 'percent', 'fixed' ), true ) ? $type : 'percent';

	$amount = isset( $input['coupon_amount'] ) ? (float) $input['coupon_amount'] : (float) $current['coupon_amount'];

	if ( $amount < 0 ) {
		$amount = 0.0;
	}

	// Percentage discounts cannot exceed 100; fixed amounts are left to the store owner.
	if ( 'percent' === $clean['coupon_type'] && $amount > 100 ) {
		$amount = 100.0;
	}

	$clean['coupon_amount'] = round( $amount, 2 );

	$validity                      = isset( $input['coupon_validity_days'] ) ? absint( $input['coupon_validity_days'] ) : (int) $current['coupon_validity_days'];
	$clean['coupon_validity_days'] = wcr_clamp( $validity, 1, 365 );

	$step_defaults  = wcr_step_defaults();
	$clean['steps'] = array();

	foreach ( array( 1, 2, 3 ) as $step ) {
		$in = ( isset( $input['steps'][ $step ] ) && is_array( $input['steps'][ $step ] ) ) ? $input['steps'][ $step ] : array();

		$clean['steps'][ $step ] = array(
			'enabled' => ! empty( $in['enabled'] ),
			'delay'   => wcr_clamp( isset( $in['delay'] ) ? absint( $in['delay'] ) : $step_defaults[ $step ]['delay'], 1, 43200 ),
			'coupon'  => ! empty( $in['coupon'] ),
		);
	}

	return $clean;
}
