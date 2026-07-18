<?php
/**
 * Restore/unsubscribe token: format, signing, parsing, and validation.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and validates HMAC-signed, single-use, expiring tokens.
 *
 * Token format: "{send_id}.{expires}.{nonce}.{sig}" where
 * sig = hash_hmac('sha256', "{send_id}.{expires}.{nonce}", secret). Only the
 * sha256 of the full token is ever persisted (on the send row).
 */
class WCR_Token {

	/**
	 * Returns the per-site signing secret.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( 'wcr_token_secret' );

		return is_string( $secret ) ? $secret : '';
	}

	/**
	 * Builds a token from its parts and a secret. Pure.
	 *
	 * @param int    $send_id Send row id.
	 * @param int    $expires Unix expiry timestamp.
	 * @param string $nonce   Random hex nonce.
	 * @param string $secret  Signing secret.
	 * @return string
	 */
	public static function build( $send_id, $expires, $nonce, $secret ) {
		$payload = (int) $send_id . '.' . (int) $expires . '.' . $nonce;
		$sig     = hash_hmac( 'sha256', $payload, $secret );

		return $payload . '.' . $sig;
	}

	/**
	 * Generates a fresh token for a send row using the current secret.
	 *
	 * @param int $send_id     Send row id.
	 * @param int $ttl_seconds Time-to-live in seconds.
	 * @return array{token:string,hash:string,expires:int}
	 */
	public static function generate( $send_id, $ttl_seconds ) {
		$expires = time() + (int) $ttl_seconds;
		$nonce   = bin2hex( random_bytes( 16 ) );
		$token   = self::build( $send_id, $expires, $nonce, self::secret() );

		return array(
			'token'   => $token,
			'hash'    => self::hash( $token ),
			'expires' => $expires,
		);
	}

	/**
	 * Returns the sha256 hex of a full token.
	 *
	 * @param string $token Token string.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Parses a token into its parts with structural checks. Pure.
	 *
	 * @param string $token Token string.
	 * @return array{send_id:int,expires:int,nonce:string,sig:string}|false
	 */
	public static function parse( $token ) {
		$parts = explode( '.', (string) $token );

		if ( 4 !== count( $parts ) ) {
			return false;
		}

		list( $send_id, $expires, $nonce, $sig ) = $parts;

		if ( ! ctype_digit( $send_id ) || ! ctype_digit( $expires ) ) {
			return false;
		}

		if ( '' === $nonce || '' === $sig ) {
			return false;
		}

		return array(
			'send_id' => (int) $send_id,
			'expires' => (int) $expires,
			'nonce'   => $nonce,
			'sig'     => $sig,
		);
	}

	/**
	 * Parses and verifies the signature with hash_equals. Pure.
	 *
	 * @param string $token  Token string.
	 * @param string $secret Signing secret.
	 * @return array|false Parsed parts on success, false on any structural or signature failure.
	 */
	public static function verify( $token, $secret ) {
		$parsed = self::parse( $token );

		if ( false === $parsed ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $parsed['send_id'] . '.' . $parsed['expires'] . '.' . $parsed['nonce'], $secret );

		if ( ! hash_equals( $expected, $parsed['sig'] ) ) {
			return false;
		}

		return $parsed;
	}

	/**
	 * Whether a unix expiry timestamp is in the past. Pure.
	 *
	 * @param int $expires Unix timestamp.
	 * @return bool
	 */
	public static function is_expired( $expires ) {
		return (int) $expires <= time();
	}

	/**
	 * Validates a restore token against send and cart state.
	 *
	 * Runs every documented step in order; the first failure returns a reason
	 * code for logging only. All failures look identical to the visitor.
	 *
	 * @param string $token Token string.
	 * @return array{ok:bool,code:string,send:object|null,cart:object|null}
	 */
	public static function validate_restore( $token ) {
		$parsed = self::verify( $token, self::secret() );

		if ( false === $parsed ) {
			$code = ( false === self::parse( $token ) ) ? 'malformed' : 'bad_signature';
			return self::failure( $code );
		}

		if ( self::is_expired( $parsed['expires'] ) ) {
			return self::failure( 'expired' );
		}

		$send = wcr_get_send( $parsed['send_id'] );

		if ( ! $send || ! is_string( $send->token_hash ) || ! hash_equals( $send->token_hash, self::hash( $token ) ) ) {
			return self::failure( 'unknown_send' );
		}

		if ( null !== $send->token_used_at ) {
			return self::failure( 'already_used' );
		}

		$cart = wcr_get_cart( (int) $send->cart_id );

		if ( ! $cart || ! in_array( $cart->status, array( 'abandoned', 'active' ), true ) ) {
			return self::failure( 'wrong_state' );
		}

		$contents = json_decode( (string) $cart->cart_contents, true );

		if ( ! is_array( $contents ) || array() === $contents ) {
			return self::failure( 'empty_cart' );
		}

		return array(
			'ok'   => true,
			'code' => '',
			'send' => $send,
			'cart' => $cart,
		);
	}

	/**
	 * Validates an unsubscribe token: signature and known send only.
	 *
	 * Expiry and single-use are deliberately not enforced so an unsubscribe
	 * always works, even from an old email.
	 *
	 * @param string $token Token string.
	 * @return array{ok:bool,code:string,send:object|null,cart:object|null}
	 */
	public static function validate_unsubscribe( $token ) {
		$parsed = self::verify( $token, self::secret() );

		if ( false === $parsed ) {
			$code = ( false === self::parse( $token ) ) ? 'malformed' : 'bad_signature';
			return self::failure( $code );
		}

		$send = wcr_get_send( $parsed['send_id'] );

		if ( ! $send || ! is_string( $send->token_hash ) || ! hash_equals( $send->token_hash, self::hash( $token ) ) ) {
			return self::failure( 'unknown_send' );
		}

		return array(
			'ok'   => true,
			'code' => '',
			'send' => $send,
			'cart' => wcr_get_cart( (int) $send->cart_id ),
		);
	}

	/**
	 * Builds a uniform failure result.
	 *
	 * @param string $code Reason code, for logging only.
	 * @return array{ok:bool,code:string,send:null,cart:null}
	 */
	private static function failure( $code ) {
		return array(
			'ok'   => false,
			'code' => $code,
			'send' => null,
			'cart' => null,
		);
	}
}
