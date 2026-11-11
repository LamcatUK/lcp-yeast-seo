<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class BrandTest extends TestCase {

	public function test_the_menu_icon_is_a_one_colour_fill_only_svg() {
		Functions\when( 'esc_attr' )->returnArg();

		$uri = lcp_yeast_seo_menu_icon();
		$svg = base64_decode( substr( $uri, strlen( 'data:image/svg+xml;base64,' ) ) );

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $uri );
		$this->assertStringNotContainsString( 'stroke', $svg, 'WordPress repaints fills only' );
		$this->assertSame( 1, substr_count( $svg, 'fill="black"' ), 'one colour, set once on the group' );
		$this->assertStringContainsString( 'fill-rule="evenodd"', $svg, 'the jar wall is one cut-out shape' );
	}

	public function test_the_inline_mark_is_decorative_and_uses_the_colours_given() {
		Functions\when( 'esc_attr' )->returnArg();

		$svg = lcp_yeast_seo_brand_mark( '#d9a21b', '#ffffff', 'x' );

		$this->assertStringContainsString( 'aria-hidden="true"', $svg );
		$this->assertStringContainsString( 'fill="#d9a21b"', $svg );
		$this->assertStringContainsString( 'fill="#ffffff"', $svg );
	}

	public function test_the_about_story_follows_the_voice_rules() {
		$text = strtolower( implode( ' ', lcp_yeast_seo_about_story() ) . ' ' . wp_json_encode_for_test( lcp_yeast_seo_about_features() ) );

		$this->assertCount( 3, lcp_yeast_seo_about_story() );

		foreach ( array( 'supercharge', 'unlock', 'boost', 'seamless', 'powerful', 'yoast' ) as $banned ) {
			$this->assertStringNotContainsString( $banned, $text );
		}
	}
}

function wp_json_encode_for_test( $value ) {
	return json_encode( $value );
}
