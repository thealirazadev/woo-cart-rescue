<?php
/**
 * Unit tests for merge-tag substitution.
 *
 * Pure PHP, no WordPress load required.
 *
 * @package Woo_Cart_Rescue
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers wcr_render_merge_tags().
 */
class WCR_Test_Merge_Tags extends TestCase {

	/**
	 * Known tags are replaced with their values.
	 *
	 * @return void
	 */
	public function test_replaces_known_tags() {
		$out = wcr_render_merge_tags(
			'Hi {customer_first_name}, your {cart_total} cart at {site_title} awaits.',
			array(
				'customer_first_name' => 'Sam',
				'cart_total'          => '$42.00',
				'site_title'          => 'Test Shop',
			)
		);

		$this->assertSame( 'Hi Sam, your $42.00 cart at Test Shop awaits.', $out );
	}

	/**
	 * A neutral fallback value renders when the name is empty.
	 *
	 * @return void
	 */
	public function test_neutral_fallback_for_empty_name() {
		$out = wcr_render_merge_tags(
			'Hello {customer_first_name}!',
			array( 'customer_first_name' => 'there' )
		);

		$this->assertSame( 'Hello there!', $out );
	}

	/**
	 * Unmatched tags are left untouched rather than blanked.
	 *
	 * @return void
	 */
	public function test_unknown_tags_are_left_intact() {
		$out = wcr_render_merge_tags( 'Keep {unknown_tag} as-is.', array( 'customer_first_name' => 'Sam' ) );

		$this->assertSame( 'Keep {unknown_tag} as-is.', $out );
	}

	/**
	 * A url value is substituted verbatim.
	 *
	 * @return void
	 */
	public function test_substitutes_links() {
		$out = wcr_render_merge_tags(
			'{restore_link} | {unsubscribe_url}',
			array(
				'restore_link'    => 'https://example.com/?wcr_action=restore&wcr_token=a.b.c.d',
				'unsubscribe_url' => 'https://example.com/?wcr_action=unsubscribe&wcr_token=a.b.c.d',
			)
		);

		$this->assertStringContainsString( 'wcr_action=restore', $out );
		$this->assertStringContainsString( 'wcr_action=unsubscribe', $out );
	}

	/**
	 * An empty data map returns the template unchanged.
	 *
	 * @return void
	 */
	public function test_empty_data_returns_template() {
		$this->assertSame( 'No {tags} here.', wcr_render_merge_tags( 'No {tags} here.', array() ) );
	}
}
