<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class ReadableSitemapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'trailingslashit' )->alias( static function ( $value ) {
			return rtrim( $value, '/\\' ) . '/';
		} );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/* Which file is being asked for */

	public function test_the_two_files_are_recognised_at_the_site_root_and_in_a_subfolder() {
		$this->assertSame( 'txt', lcp_yeast_seo_feed_route( '/sitemap.txt', '/' ) );
		$this->assertSame( 'json', lcp_yeast_seo_feed_route( '/sitemap.json', '/' ) );
		$this->assertSame( 'json', lcp_yeast_seo_feed_route( '/blog/sitemap.json', '/blog/' ) );
		$this->assertSame( '', lcp_yeast_seo_feed_route( '/sitemap.json', '/blog/' ) );
	}

	public function test_nothing_else_is_taken_over() {
		foreach ( array( '/sitemap.xml', '/sitemap_index.xml', '/sitemap.txt/', '/sitemap.json.bak', '/', '/wp-json/', '/post-sitemap.xml' ) as $path ) {
			$this->assertSame( '', lcp_yeast_seo_feed_route( $path, '/' ), $path );
		}
	}

	/* The options */

	public function test_options_are_cleaned_and_defaulted() {
		$this->assertSame( array( 'type' => '', 'page' => 1, 'full' => false ), lcp_yeast_seo_feed_args( array() ) );
		$this->assertSame( array( 'type' => 'song', 'page' => 3, 'full' => true ), lcp_yeast_seo_feed_args( array( 'type' => 'Song', 'page' => '3', 'full' => '1' ) ) );
	}

	public function test_a_hostile_type_is_reduced_to_safe_characters() {
		$this->assertSame( 'scriptalert1script', lcp_yeast_seo_feed_args( array( 'type' => '<script>alert(1)</script>' ) )['type'] );
		$this->assertSame( '', lcp_yeast_seo_feed_args( array( 'type' => array( 'x' ) ) )['type'] );
		$this->assertSame( 1, lcp_yeast_seo_feed_args( array( 'page' => '-5' ) )['page'] );
		$this->assertSame( 1, lcp_yeast_seo_feed_args( array( 'page' => 'abc' ) )['page'] );
		$this->assertFalse( lcp_yeast_seo_feed_args( array( 'full' => '0' ) )['full'] );
	}

	/* Pages of a long list */

	public function test_a_list_is_cut_into_pages_and_knows_whether_there_is_a_next() {
		$page = lcp_yeast_seo_feed_paginate( range( 1, 12 ), 2, 5 );

		$this->assertSame( array( 6, 7, 8, 9, 10 ), $page['items'] );
		$this->assertSame( array( 2, 3, 12 ), array( $page['page'], $page['pages'], $page['total'] ) );
		$this->assertTrue( $page['has_next'] );
		$this->assertFalse( lcp_yeast_seo_feed_paginate( range( 1, 12 ), 3, 5 )['has_next'] );
	}

	public function test_a_page_past_the_end_gives_the_last_and_an_empty_list_is_one_empty_page() {
		$this->assertSame( 3, lcp_yeast_seo_feed_paginate( range( 1, 12 ), 99, 5 )['page'] );

		$empty = lcp_yeast_seo_feed_paginate( array(), 1, 5 );

		$this->assertSame( array(), $empty['items'] );
		$this->assertSame( 1, $empty['pages'] );
		$this->assertFalse( $empty['has_next'] );
	}

	public function test_addresses_leave_out_what_is_default() {
		$base = 'https://a.test/sitemap.json';

		$this->assertSame( $base, lcp_yeast_seo_feed_link( $base, array( 'type' => '', 'full' => false, 'page' => 1 ) ) );
		$this->assertSame( $base . '?type=song', lcp_yeast_seo_feed_link( $base, array( 'type' => 'song' ) ) );
		$this->assertSame( $base . '?type=song&full=1&page=2', lcp_yeast_seo_feed_link( $base, array( 'type' => 'song', 'full' => true, 'page' => 2 ) ) );
	}

	/* The text file */

	public function test_the_text_file_is_one_address_per_line_and_empty_when_there_are_none() {
		$this->assertSame( "https://a.test/\nhttps://a.test/b/\n", lcp_yeast_seo_feed_text_body( array( 'https://a.test/', 'https://a.test/b/' ) ) );
		$this->assertSame( '', lcp_yeast_seo_feed_text_body( array() ) );
	}

	public function test_the_text_file_stops_at_the_protocol_limit() {
		$urls = array();

		for ( $i = 0; $i < LCP_YEAST_SEO_FEED_TEXT_CAP + 10; $i++ ) {
			$urls[] = 'https://a.test/' . $i . '/';
		}

		$this->assertSame( LCP_YEAST_SEO_FEED_TEXT_CAP, substr_count( lcp_yeast_seo_feed_text_body( $urls ), "\n" ) );
	}

	/* Readable text from HTML */

	public function test_html_becomes_text_with_paragraphs_kept_apart() {
		$text = lcp_yeast_seo_feed_plain_text( '<div class="c"><h2>Heading</h2><p class="x">First &amp; second.</p><p>Third<br>line.</p></div>' );

		$this->assertSame( "Heading\n\nFirst & second.\n\nThird\nline.", $text );
	}

	public function test_scripts_styles_and_markup_do_not_reach_the_text() {
		$text = lcp_yeast_seo_feed_plain_text( '<style>.a{color:red}</style><script>alert(1)</script><p>Kept <strong>this</strong>.</p>' );

		$this->assertSame( 'Kept this.', $text );
	}

	public function test_entities_and_odd_spaces_are_tidied() {
		$this->assertSame( 'Stool Chamber’s “first” set', lcp_yeast_seo_feed_plain_text( '<p>Stool   Chamber&rsquo;s &ldquo;first&rdquo;&nbsp;&nbsp;set</p>' ) );
		$this->assertSame( '', lcp_yeast_seo_feed_plain_text( '' ) );
	}

	/* Field values */

	public function test_field_values_come_out_as_plain_json_values() {
		$this->assertSame( 'a', lcp_yeast_seo_feed_clean_value( 'a' ) );
		$this->assertSame( 3, lcp_yeast_seo_feed_clean_value( 3 ) );
		$this->assertNull( lcp_yeast_seo_feed_clean_value( null ) );
		$this->assertSame( array( 'x' => array( 1, 'b' ) ), lcp_yeast_seo_feed_clean_value( array( 'x' => array( 1, 'b' ) ) ) );
		$this->assertSame( array( 'k' => 'v' ), lcp_yeast_seo_feed_clean_value( (object) array( 'k' => 'v' ) ) );
	}

	public function test_only_public_non_seo_fields_are_listed_as_meta() {
		$this->assertTrue( lcp_yeast_seo_feed_meta_key_is_public( 'lc_date_start', array( 'show_in_rest' => true ) ) );
		$this->assertFalse( lcp_yeast_seo_feed_meta_key_is_public( 'lc_secret', array( 'show_in_rest' => false ) ), 'not exposed to REST, so not here either' );
		$this->assertFalse( lcp_yeast_seo_feed_meta_key_is_public( 'lc_secret', array() ) );
		$this->assertFalse( lcp_yeast_seo_feed_meta_key_is_public( '_edit_lock', array( 'show_in_rest' => true ) ), 'protected fields stay out' );
		$this->assertFalse( lcp_yeast_seo_feed_meta_key_is_public( 'lcp_yeast_seo_schema_jsonld', array( 'show_in_rest' => true ) ), 'summarised under seo instead' );
	}
}
