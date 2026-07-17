<?php
/**
 * Minimal WordPress function/constant shims for unit-mode tests.
 *
 * Loaded only when the WordPress test suite is unavailable. Each shim is guarded
 * so the real WordPress function always wins when present. This file mimics WP
 * core signatures on purpose and is excluded from the coding-standards ruleset.
 *
 * @package Woo_Cart_Rescue
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( wp_strip_all_tags( $str ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return trim( strip_tags( (string) $string ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		$email = (string) $email;
		return ( false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ? $email : false;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return (string) $data;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! isset( $GLOBALS['wcr_stub_options'] ) ) {
	$GLOBALS['wcr_stub_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['wcr_stub_options'] ) ? $GLOBALS['wcr_stub_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['wcr_stub_options'][ $name ] = $value;
		return true;
	}
}
