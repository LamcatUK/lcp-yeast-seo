<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class SitemapTest extends TestCase {

	private $home = 'http://example.test/';

	public function test_finds_images_in_document_order() {
		$html = '<p><img src="https://cdn.test/a.jpg" alt=""></p><figure><img class="x" src=\'/wp-content/uploads/b.png\'></figure>';

		$this->assertSame(
			array( 'https://cdn.test/a.jpg', 'http://example.test/wp-content/uploads/b.png' ),
			lcp_yeast_seo_extract_content_images( $html, $this->home )
		);
	}

	public function test_ignores_data_uris_and_duplicates_and_resolves_protocol_relative_urls() {
		$html = '<img src="data:image/gif;base64,R0lG"><img src="//cdn.test/a.jpg"><img src="https://cdn.test/a.jpg">';

		$this->assertSame( array( 'https://cdn.test/a.jpg' ), lcp_yeast_seo_extract_content_images( $html, $this->home ) );
	}

	public function test_decodes_entities_in_urls() {
		$this->assertSame(
			array( 'https://cdn.test/a.jpg?w=1&h=2' ),
			lcp_yeast_seo_extract_content_images( '<img src="https://cdn.test/a.jpg?w=1&amp;h=2">', $this->home )
		);
	}

	public function test_no_images_gives_an_empty_list() {
		$this->assertSame( array(), lcp_yeast_seo_extract_content_images( '<p>Nothing here</p>', $this->home ) );
		$this->assertSame( array(), lcp_yeast_seo_extract_content_images( '', $this->home ) );
	}

	public function test_robots_txt_gets_the_sitemap_line() {
		$out = lcp_yeast_seo_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", '1' );

		$this->assertStringEndsWith( "\n\nSitemap: http://example.test/sitemap_index.xml\n", $out );
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /wp-admin/", $out );
	}

	public function test_robots_txt_is_left_alone_when_the_site_is_hidden() {
		$this->assertSame( "User-agent: *\nDisallow: /\n", lcp_yeast_seo_robots_txt( "User-agent: *\nDisallow: /\n", '0' ) );
	}

	public function test_robots_txt_is_left_alone_when_the_site_is_hidden_as_a_boolean() {
		$this->assertSame( "User-agent: *\n", lcp_yeast_seo_robots_txt( "User-agent: *\n", false ) );
		$this->assertStringContainsString( 'Sitemap:', lcp_yeast_seo_robots_txt( "User-agent: *\n", true ) );
	}

	public function test_robots_txt_does_not_repeat_the_line() {
		$once = lcp_yeast_seo_robots_txt( "User-agent: *\n", '1' );

		$this->assertSame( $once, lcp_yeast_seo_robots_txt( $once, '1' ) );
	}

	public function test_robots_txt_is_left_alone_when_sitemaps_are_off() {
		Functions\when( 'get_option' )->justReturn( array( 'enable_sitemaps' => '0' ) );

		$this->assertSame( "User-agent: *\n", lcp_yeast_seo_robots_txt( "User-agent: *\n", '1' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_robots_txt_is_left_alone_when_yoast_is_active() {
		define( 'WPSEO_VERSION', '1.0' );

		$this->assertSame( "User-agent: *\n", lcp_yeast_seo_robots_txt( "User-agent: *\n", '1' ) );
	}

	public function test_sitemap_image_setting_defaults_on() {
		$this->assertTrue( lcp_yeast_seo_sitemap_images_enabled() );

		Functions\when( 'get_option' )->justReturn( array( 'sitemap_images' => '0' ) );

		$this->assertFalse( lcp_yeast_seo_sitemap_images_enabled() );
	}
}
