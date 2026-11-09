<?php
namespace Lcp\YeastSeo\Tests;

class TemplatesTest extends TestCase {

	private function vars( array $override = array() ) {
		return $override + array(
			'title'    => 'About us',
			'sitename' => 'Tidy Solutions',
			'tagline'  => 'Waste clearance',
			'sep'      => '|',
		);
	}

	public function test_template_fills_tags() {
		$this->assertSame(
			'About us | Tidy Solutions',
			lcp_yeast_seo_render_title_template( '%title% %sep% %sitename%', $this->vars() )
		);
	}

	public function test_empty_tag_does_not_leave_a_dangling_separator() {
		$this->assertSame(
			'About us | Tidy Solutions',
			lcp_yeast_seo_render_title_template( '%title% %sep% %sitename% %sep% %tagline%', $this->vars( array( 'tagline' => '' ) ) )
		);
	}

	public function test_leading_separator_is_dropped_when_title_is_empty() {
		$this->assertSame(
			'Tidy Solutions',
			lcp_yeast_seo_render_title_template( '%title% %sep% %sitename%', $this->vars( array( 'title' => '' ) ) )
		);
	}

	public function test_separator_inside_a_title_is_left_alone() {
		$this->assertSame(
			'Q|A guide | Tidy Solutions',
			lcp_yeast_seo_render_title_template( '%title% %sep% %sitename%', $this->vars( array( 'title' => 'Q|A guide' ) ) )
		);
	}

	public function test_unknown_tags_are_kept() {
		$this->assertSame( '%nope% | Tidy Solutions', lcp_yeast_seo_render_title_template( '%nope% %sep% %sitename%', $this->vars() ) );
	}

	public function test_truncate_leaves_short_text_alone() {
		$this->assertSame( 'Short text.', lcp_yeast_seo_truncate_text( 'Short text.', 160 ) );
	}

	public function test_truncate_cuts_at_a_word_and_adds_an_ellipsis() {
		$text   = str_repeat( 'word ', 60 );
		$result = lcp_yeast_seo_truncate_text( $text, 50 );

		$this->assertLessThanOrEqual( 50, mb_strlen( $result ) );
		$this->assertStringEndsWith( "\u{2026}", $result );
		$this->assertStringNotContainsString( 'wor' . "\u{2026}", $result, 'must not cut mid-word' );
	}

	public function test_truncate_counts_characters_not_bytes() {
		$result = lcp_yeast_seo_truncate_text( str_repeat( "caf\u{e9} ", 40 ), 30 );

		$this->assertLessThanOrEqual( 30, mb_strlen( $result ) );
		$this->assertTrue( mb_check_encoding( $result, 'UTF-8' ) );
	}

	public function test_description_prefers_the_excerpt() {
		$this->assertSame( 'The excerpt.', lcp_yeast_seo_description_from_text( 'The excerpt.', 'The content.' ) );
	}

	public function test_description_falls_back_to_content_and_strips_markup() {
		$this->assertSame(
			'Hello & welcome to the site.',
			lcp_yeast_seo_description_from_text( '', "<p>Hello &amp; welcome\n to the <strong>site</strong>.</p>" )
		);
	}

	public function test_description_is_empty_when_there_is_no_text() {
		$this->assertSame( '', lcp_yeast_seo_description_from_text( '  ', '<div></div>' ) );
	}
}
