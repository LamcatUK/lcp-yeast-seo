<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class AdminColumnsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
	}

	public function test_a_clipped_cell_carries_the_whole_text_for_the_tooltip() {
		$html = lcp_yeast_seo_clip( 'A long title that will not fit' );

		$this->assertStringContainsString( 'class="lcp-yeast-seo-clip"', $html );
		$this->assertStringContainsString( 'title="A long title that will not fit"', $html );
		$this->assertStringContainsString( '>A long title that will not fit</span>', $html );
	}

	public function test_a_clipped_cell_escapes_what_it_shows() {
		$html = lcp_yeast_seo_clip( '<script>alert(1)</script> & "quotes"' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_muted_fallback_style_is_kept() {
		$this->assertStringContainsString( 'style="color:#757575"', lcp_yeast_seo_clip( 'Title', 'color:#757575' ) );
		$this->assertStringNotContainsString( 'style=', lcp_yeast_seo_clip( 'Title' ) );
	}
}
