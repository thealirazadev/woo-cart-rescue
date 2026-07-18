<?php
/**
 * Unit tests for the token signer: build, parse, verify, tamper, expiry.
 *
 * Pure PHP, no WordPress load required.
 *
 * @package Woo_Cart_Rescue
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers WCR_Token signing, parsing, and signature verification.
 */
class WCR_Test_Token extends TestCase {

	const SECRET = 'a5f3c9d1e7b2486093ac1f0e5d7b8c2a4f6e9d0b1c3a5e7f9d2b4c6a8e0f1d3b5';

	/**
	 * A freshly built token round-trips through verify.
	 *
	 * @return void
	 */
	public function test_build_and_verify_round_trip() {
		$token  = WCR_Token::build( 42, 2000000000, 'deadbeef', self::SECRET );
		$parsed = WCR_Token::verify( $token, self::SECRET );

		$this->assertIsArray( $parsed );
		$this->assertSame( 42, $parsed['send_id'] );
		$this->assertSame( 2000000000, $parsed['expires'] );
		$this->assertSame( 'deadbeef', $parsed['nonce'] );
	}

	/**
	 * A token built with one secret does not verify under another.
	 *
	 * @return void
	 */
	public function test_verify_fails_with_wrong_secret() {
		$token = WCR_Token::build( 42, 2000000000, 'deadbeef', self::SECRET );

		$this->assertFalse( WCR_Token::verify( $token, 'a-different-secret' ) );
	}

	/**
	 * Tampering any field breaks the signature.
	 *
	 * @return void
	 */
	public function test_tampered_payload_is_rejected() {
		$token = WCR_Token::build( 42, 2000000000, 'deadbeef', self::SECRET );
		$parts = explode( '.', $token );

		$tampered_id = implode( '.', array( '43', $parts[1], $parts[2], $parts[3] ) );
		$this->assertFalse( WCR_Token::verify( $tampered_id, self::SECRET ) );

		$tampered_exp = implode( '.', array( $parts[0], '1999999999', $parts[2], $parts[3] ) );
		$this->assertFalse( WCR_Token::verify( $tampered_exp, self::SECRET ) );

		$tampered_nonce = implode( '.', array( $parts[0], $parts[1], 'cafebabe', $parts[3] ) );
		$this->assertFalse( WCR_Token::verify( $tampered_nonce, self::SECRET ) );

		$tampered_sig = implode( '.', array( $parts[0], $parts[1], $parts[2], str_repeat( '0', 64 ) ) );
		$this->assertFalse( WCR_Token::verify( $tampered_sig, self::SECRET ) );
	}

	/**
	 * Structurally malformed tokens fail to parse.
	 *
	 * @return void
	 */
	public function test_parse_rejects_malformed_tokens() {
		$this->assertFalse( WCR_Token::parse( 'only.three.parts' ) );
		$this->assertFalse( WCR_Token::parse( 'a.b.c.d.e' ) );
		$this->assertFalse( WCR_Token::parse( 'x.2000000000.nonce.sig' ) );
		$this->assertFalse( WCR_Token::parse( '42.notnumeric.nonce.sig' ) );
		$this->assertFalse( WCR_Token::parse( '42.2000000000..sig' ) );
		$this->assertFalse( WCR_Token::parse( '42.2000000000.nonce.' ) );
	}

	/**
	 * The token hash is a deterministic sha256 of the full token.
	 *
	 * @return void
	 */
	public function test_hash_is_deterministic_sha256() {
		$token = WCR_Token::build( 7, 2000000000, 'abc123', self::SECRET );

		$this->assertSame( hash( 'sha256', $token ), WCR_Token::hash( $token ) );
		$this->assertSame( WCR_Token::hash( $token ), WCR_Token::hash( $token ) );
	}

	/**
	 * Expiry boundary: past is expired, far future is not.
	 *
	 * @return void
	 */
	public function test_is_expired_boundary() {
		$this->assertTrue( WCR_Token::is_expired( time() - 1 ) );
		$this->assertFalse( WCR_Token::is_expired( time() + 3600 ) );
	}

	/**
	 * A generated token verifies against the stored secret and carries a matching hash.
	 *
	 * @return void
	 */
	public function test_generate_produces_verifiable_token() {
		update_option( 'wcr_token_secret', self::SECRET );

		$generated = WCR_Token::generate( 99, 3600 );
		$parsed    = WCR_Token::verify( $generated['token'], self::SECRET );

		$this->assertIsArray( $parsed );
		$this->assertSame( 99, $parsed['send_id'] );
		$this->assertSame( WCR_Token::hash( $generated['token'] ), $generated['hash'] );
		$this->assertGreaterThan( time(), $generated['expires'] );
	}
}
