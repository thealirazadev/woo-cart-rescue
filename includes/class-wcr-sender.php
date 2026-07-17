<?php
/**
 * Send handler: send-time recheck, atomic claim, and step chaining.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a scheduled recovery send race-safely.
 */
class WCR_Sender {

	/**
	 * Registers the send action handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wcr_send_step', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * Handles one scheduled send.
	 *
	 * Claims the row atomically, then rechecks cart state as late as possible so
	 * an order or unsubscribe that lands after scheduling but before dispatch
	 * cancels the send instead of mailing.
	 *
	 * @param int $send_id Send row id.
	 * @return void
	 */
	public function handle( $send_id ) {
		$send_id = absint( $send_id );
		$send    = wcr_get_send( $send_id );

		if ( ! $send ) {
			wcr_log( 'warning', 'Send row missing when handling a send.', array( 'send_id' => $send_id ) );
			return;
		}

		if ( 'scheduled' !== $send->status ) {
			return;
		}

		global $wpdb;

		$table = wcr_table( 'sends' );
		$now   = wcr_now();

		// Atomic claim: exactly one worker can move scheduled -> sending.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted whitelisted table name; values are prepared.
		$claimed = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET status = 'sending', updated_at = %s WHERE id = %d AND status = 'scheduled'", $now, $send_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $claimed ) {
			return;
		}

		$settings = wcr_get_settings();
		$cart     = wcr_get_cart( (int) $send->cart_id );

		// Send-time recheck: only mail a still-abandoned, still-eligible cart.
		if ( empty( $settings['enabled'] ) || ! $cart || 'abandoned' !== $cart->status || empty( $cart->email ) || wcr_is_opted_out( $cart->email ) ) {
			$this->mark( $send_id, 'cancelled' );
			return;
		}

		$ttl   = wcr_clamp( $settings['token_ttl_days'], 1, 365 ) * DAY_IN_SECONDS;
		$token = WCR_Token::generate( $send_id, $ttl );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Store only the token hash and expiry.
		$stored = $wpdb->update(
			$table,
			array(
				'token_hash'       => $token['hash'],
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', (int) $token['expires'] ),
				'updated_at'       => $now,
			),
			array( 'id' => $send_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $stored ) {
			wcr_log( 'error', 'Failed to store the send token.', array( 'send_id' => $send_id ) );
			$this->mark( $send_id, 'failed' );
			return;
		}

		$sent = $this->dispatch( $send, $cart, $token['token'] );

		if ( $sent ) {
			$this->mark( $send_id, 'sent', $now );
			wcr_log_event( (int) $cart->id, $send_id, 'email_sent', array( 'step' => (int) $send->step ) );
		} else {
			$this->mark( $send_id, 'failed' );
			wcr_log_event( (int) $cart->id, $send_id, 'email_failed', array( 'step' => (int) $send->step ) );
			wcr_log( 'error', 'Recovery email failed to send.', array( 'send_id' => $send_id ) );
		}
	}

	/**
	 * Builds the email context and dispatches through the recovery email class.
	 *
	 * @param object $send  Send row.
	 * @param object $cart  Cart row.
	 * @param string $token Full token string.
	 * @return bool
	 */
	protected function dispatch( $send, $cart, $token ) {
		require_once WCR_PATH . 'includes/class-wcr-email-recovery.php';

		if ( ! class_exists( 'WCR_Email_Recovery' ) ) {
			wcr_log( 'error', 'The recovery email class was unavailable.', array( 'send_id' => (int) $send->id ) );
			return false;
		}

		$contents = json_decode( (string) $cart->cart_contents, true );

		if ( ! is_array( $contents ) ) {
			$contents = array();
		}

		$restore_url     = add_query_arg(
			array(
				'wcr_action' => 'restore',
				'wcr_token'  => $token,
			),
			home_url( '/' )
		);
		$unsubscribe_url = add_query_arg(
			array(
				'wcr_action' => 'unsubscribe',
				'wcr_token'  => $token,
			),
			home_url( '/' )
		);

		$email = new WCR_Email_Recovery( (int) $send->step );

		return (bool) $email->trigger(
			array(
				'email'           => $cart->email,
				'first_name'      => $this->first_name( $cart ),
				'cart_contents'   => $contents,
				'cart_total'      => (float) $cart->cart_total,
				'currency'        => (string) $cart->currency,
				'restore_url'     => $restore_url,
				'unsubscribe_url' => $unsubscribe_url,
			)
		);
	}

	/**
	 * Resolves the customer first name, empty for guests.
	 *
	 * @param object $cart Cart row.
	 * @return string
	 */
	protected function first_name( $cart ) {
		if ( ! empty( $cart->user_id ) ) {
			$user = get_user_by( 'id', (int) $cart->user_id );

			if ( $user && '' !== $user->first_name ) {
				return $user->first_name;
			}
		}

		return '';
	}

	/**
	 * Updates a send row's status.
	 *
	 * @param int         $send_id Send row id.
	 * @param string      $status  New status.
	 * @param string|null $sent_at Optional sent timestamp for the sent status.
	 * @return void
	 */
	protected function mark( $send_id, $status, $sent_at = null ) {
		global $wpdb;

		$table  = wcr_table( 'sends' );
		$data   = array(
			'status'     => $status,
			'updated_at' => wcr_now(),
		);
		$format = array( '%s', '%s' );

		if ( 'sent' === $status ) {
			$data['sent_at'] = $sent_at ? $sent_at : wcr_now();
			$format[]        = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Status update on a trusted table.
		$updated = $wpdb->update( $table, $data, array( 'id' => absint( $send_id ) ), $format, array( '%d' ) );

		if ( false === $updated ) {
			wcr_log(
				'error',
				'Failed to update a send status.',
				array(
					'send_id' => absint( $send_id ),
					'status'  => $status,
				)
			);
		}
	}
}
