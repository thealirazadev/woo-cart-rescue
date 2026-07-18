/* Guest email + consent capture on the classic WooCommerce checkout. */
( function () {
	'use strict';

	var cfg = window.wcrCapture || {};

	if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	var lastSent = '';

	function getEmail() {
		var field = document.getElementById( 'billing_email' );
		return field ? field.value.trim() : '';
	}

	function isConsented() {
		var box = document.getElementById( 'wcr_consent' );
		return !! ( box && box.checked );
	}

	function looksLikeEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
	}

	function maybeCapture() {
		var email = getEmail();

		if ( ! isConsented() || ! looksLikeEmail( email ) ) {
			return;
		}

		var key = email.toLowerCase();

		if ( key === lastSent ) {
			return;
		}

		lastSent = key;

		var body = 'action=wcr_capture_guest' +
			'&nonce=' + encodeURIComponent( cfg.nonce ) +
			'&email=' + encodeURIComponent( email ) +
			'&consent=1';

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} ).catch( function () {
			// Allow a retry on the next change if the request failed.
			lastSent = '';
		} );
	}

	function bind() {
		var email = document.getElementById( 'billing_email' );
		var consent = document.getElementById( 'wcr_consent' );

		if ( email ) {
			email.addEventListener( 'blur', maybeCapture );
			email.addEventListener( 'change', maybeCapture );
		}

		if ( consent ) {
			consent.addEventListener( 'change', maybeCapture );
		}
	}

	if ( 'loading' !== document.readyState ) {
		bind();
	} else {
		document.addEventListener( 'DOMContentLoaded', bind );
	}

	// WooCommerce may re-render checkout fragments; rebinding is de-duplicated
	// because the listener is a stable function reference.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', bind );
	}
}() );
