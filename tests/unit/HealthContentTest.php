<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class HealthContentTest extends TestCase {

	private $page = 'https://example.test/about/';

	public function test_an_image_with_no_alt_attribute_is_flagged_but_an_empty_alt_is_not() {
		$found = lcp_yeast_seo_health_image_findings( '<img src="/a.jpg"><img src="/b.jpg" alt=""><img src="/c.jpg" alt="A van outside a house">' );

		$this->assertSame( 3, $found['total'] );
		$this->assertSame( array( 'a.jpg' ), $found['missing'], 'an empty alt marks a decorative image, which is allowed' );
		$this->assertSame( array(), $found['filename'] );
	}

	public function test_a_file_name_used_as_alt_text_is_flagged() {
		$found = lcp_yeast_seo_health_image_findings( '<img src="/a.jpg" alt="IMG_1234.jpg"><img src="/b.jpg" alt="DSC0042"><img src="/c.jpg" alt="Image 7"><img src="/d.jpg" alt="Our team at work">' );

		$this->assertSame( array( 'a.jpg', 'b.jpg', 'c.jpg' ), $found['filename'] );
	}

	public function test_decorative_images_and_tracking_pixels_are_not_counted() {
		$html = '<img src="/a.jpg" aria-hidden="true"><img src="/b.jpg" role="presentation"><img src="/px.gif" width="1" height="1"><img src="data:image/gif;base64,AAAA"><img src="/real.jpg">';
		$found = lcp_yeast_seo_health_image_findings( $html );

		$this->assertSame( 1, $found['total'] );
		$this->assertSame( array( 'real.jpg' ), $found['missing'] );
	}

	public function test_a_lazy_loaded_image_is_judged_by_its_real_source() {
		$found = lcp_yeast_seo_health_image_findings( '<img src="data:image/gif;base64,AAAA" data-src="https://cdn.test/photo.webp?x=1">' );

		$this->assertSame( array( 'photo.webp' ), $found['missing'] );
	}

	public function test_image_issues_name_the_files_and_cap_the_list() {
		$issues = lcp_yeast_seo_health_image_issues( array( 'total' => 8, 'missing' => array( 'a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg', 'f.jpg' ), 'filename' => array() ) );

		$this->assertSame( 'content_img_no_alt', $issues[0]['code'] );
		$this->assertSame( 'warning', $issues[0]['level'] );
		$this->assertStringContainsString( '6 images have no alt text: a.jpg, b.jpg, c.jpg, d.jpg and 2 more.', $issues[0]['message'] );
		$this->assertSame( array(), lcp_yeast_seo_health_image_issues( array( 'total' => 3, 'missing' => array(), 'filename' => array() ) ) );
	}

	public function test_links_are_made_absolute_and_junk_is_skipped() {
		$html = '<a href="/contact/">c</a><a href="team/">t</a><a href="https://other.test/x#frag">o</a><a href="#top">anchor</a><a href="mailto:a@b.test">m</a><a href="tel:+441">t</a><a href="javascript:void(0)">j</a><a href="/wp-json/wp/v2/posts">api</a><a href="/feed/">f</a><a href="/about/">self</a><a href="/contact/">dup</a>';

		$this->assertSame(
			array( 'https://example.test/contact/', 'https://example.test/about/team/', 'https://other.test/x' ),
			lcp_yeast_seo_health_extract_links( $html, $this->page )
		);
	}

	public function test_an_abbr_or_article_tag_is_not_mistaken_for_a_link() {
		$this->assertSame( array(), lcp_yeast_seo_health_extract_links( '<abbr href="/x/">a</abbr><article href="/y/">b</article>', $this->page ) );
	}

	public function test_links_to_other_scanned_pages_are_inbound_not_checked() {
		$map  = array( 'example.test/contact' => 12, 'example.test/about' => 7 );
		$html = '<a href="/contact/">c</a><a href="https://other.test/page/">o</a><a href="/about/">self</a><a href="/missing/">m</a>';

		$result = lcp_yeast_seo_health_analyze_content( $html, $this->page, 7, $map );

		$this->assertSame( array( 12 ), $result['facts']['inbound'] );
		$this->assertSame( array( 'https://other.test/page/', 'https://example.test/missing/' ), $result['facts']['links'], 'a link to a scanned page needs no check; the rest do' );
	}

	public function test_link_keys_ignore_scheme_trailing_slash_and_fragment() {
		$this->assertSame( lcp_yeast_seo_health_link_key( 'http://Example.test/a/' ), lcp_yeast_seo_health_link_key( 'https://example.test/a#top' ) );
		$this->assertNotSame( lcp_yeast_seo_health_link_key( 'https://example.test/a/' ), lcp_yeast_seo_health_link_key( 'https://example.test/a/?x=1' ) );
		$this->assertSame( '', lcp_yeast_seo_health_link_key( '/relative/' ) );
	}

	public function test_only_a_missing_page_counts_as_broken() {
		$this->assertSame( 'ok', lcp_yeast_seo_health_link_verdict( 200 ) );
		$this->assertSame( 'ok', lcp_yeast_seo_health_link_verdict( 301 ) );
		$this->assertSame( 'broken', lcp_yeast_seo_health_link_verdict( 404 ) );
		$this->assertSame( 'broken', lcp_yeast_seo_health_link_verdict( 410 ) );
		$this->assertSame( 'skipped', lcp_yeast_seo_health_link_verdict( 403 ), 'a refusal says nothing about the page' );
		$this->assertSame( 'skipped', lcp_yeast_seo_health_link_verdict( 429 ) );
		$this->assertSame( 'skipped', lcp_yeast_seo_health_link_verdict( 999 ) );
		$this->assertSame( 'error', lcp_yeast_seo_health_link_verdict( 503 ) );
		$this->assertSame( 'unreachable', lcp_yeast_seo_health_link_verdict( 0 ) );
	}

	public function test_a_good_link_is_trusted_for_a_day_and_a_bad_one_for_minutes() {
		$now = 1000000;

		$this->assertTrue( lcp_yeast_seo_health_link_fresh( array( 'code' => 200, 'time' => $now - 3600 ), $now ) );
		$this->assertFalse( lcp_yeast_seo_health_link_fresh( array( 'code' => 200, 'time' => $now - 90000 ), $now ) );
		$this->assertTrue( lcp_yeast_seo_health_link_fresh( array( 'code' => 404, 'time' => $now - 300 ), $now ) );
		$this->assertFalse( lcp_yeast_seo_health_link_fresh( array( 'code' => 404, 'time' => $now - 1200 ), $now ) );
		$this->assertFalse( lcp_yeast_seo_health_link_fresh( null, $now ) );
		$this->assertFalse( lcp_yeast_seo_health_link_fresh( array( 'code' => 200 ), $now ), 'an entry with no time is not trusted' );
	}

	public function test_pending_links_are_the_unique_unchecked_ones() {
		$now      = 1000000;
		$results  = array(
			1 => array( 'content' => array( 'links' => array( 'https://a.test/', 'https://b.test/' ) ) ),
			2 => array( 'content' => array( 'links' => array( 'https://b.test/', 'https://c.test/' ) ) ),
			3 => array( 'issues' => array() ),
		);
		$statuses = array( 'https://a.test/' => array( 'code' => 200, 'time' => $now - 10 ) );

		$this->assertSame( array( 'https://b.test/', 'https://c.test/' ), lcp_yeast_seo_health_pending_links( $results, $statuses, $now ) );
		$this->assertSame( array( 'https://b.test/' ), lcp_yeast_seo_health_pending_links( $results, $statuses, $now, 1 ), 'capped' );
	}

	public function test_broken_and_unreachable_links_become_issues_and_good_ones_do_not() {
		$statuses = array(
			'https://ok.test/'    => array( 'code' => 200, 'time' => 1 ),
			'https://gone.test/x' => array( 'code' => 404, 'time' => 1 ),
			'https://slow.test/'  => array( 'code' => 0, 'time' => 1 ),
			'https://block.test/' => array( 'code' => 403, 'time' => 1 ),
			'https://down.test/'  => array( 'code' => 503, 'time' => 1 ),
		);

		$issues = lcp_yeast_seo_health_link_issues( array_merge( array_keys( $statuses ), array( 'https://unchecked.test/' ) ), $statuses );
		$codes  = array_column( $issues, 'code', 'level' );

		$this->assertSame( 'content_broken_link', $codes['error'] );
		$this->assertSame( 'content_link_unreachable', $codes['warning'] );
		$this->assertStringContainsString( '1 broken link: gone.test/x (404).', $issues[0]['message'] );
		$this->assertStringContainsString( '2 links did not answer properly', $issues[1]['message'] );
		$this->assertSame( array(), lcp_yeast_seo_health_link_issues( array( 'https://ok.test/', 'https://block.test/' ), $statuses ), 'good, refused and unchecked links raise nothing' );
	}

	public function test_a_page_nothing_links_to_is_an_orphan_and_the_front_page_never_is() {
		$inbound = array(
			1 => array( 2, 3 ),
			2 => array( 1 ),
			3 => array( 3 ),
			4 => array(),
		);

		$this->assertSame( array( 4 ), lcp_yeast_seo_health_orphans( array( 1, 2, 3, 4 ), $inbound ), 'page 3 only links to itself, but page 1 links to it' );
		$this->assertSame( array(), lcp_yeast_seo_health_orphans( array( 1, 2, 3, 4 ), $inbound, 4 ), 'an exempt page' );
		$this->assertSame( array( 1, 2, 3, 4 ), lcp_yeast_seo_health_orphans( array( 1, 2, 3, 4 ), array( 1 => array( 1 ), 2 => array(), 3 => array(), 4 => array() ) ), 'a link to itself does not count' );
	}

	public function test_orphans_are_only_reported_once_every_page_has_been_scanned() {
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				return array( 'show_on_front' => 'posts', 'page_on_front' => 0 )[ $key ] ?? false;
			}
		);

		$partial = array(
			1 => array( 'status' => 'ok', 'issues' => array(), 'content' => array( 'links' => array(), 'inbound' => array( 2 ) ) ),
			2 => array( 'status' => 'ok', 'issues' => array() ),
		);

		$this->assertSame( array(), $partial[2]['issues'] );
		$this->assertSame( array(), lcp_yeast_seo_health_add_link_issues( array( 1, 2 ), $partial, array() )[1]['issues'], 'with page 2 unscanned, page 1 cannot be called an orphan' );

		$complete = array(
			1 => array( 'status' => 'ok', 'issues' => array(), 'content' => array( 'links' => array(), 'inbound' => array() ) ),
			2 => array( 'status' => 'ok', 'issues' => array(), 'content' => array( 'links' => array(), 'inbound' => array( 1 ) ) ),
		);

		$out = lcp_yeast_seo_health_add_link_issues( array( 1, 2 ), $complete, array() );

		$this->assertSame( array( 'content_orphan' ), array_column( $out[2]['issues'], 'code' ), 'page 2 is linked from nothing; page 1 is linked from page 2' );
		$this->assertSame( 'warning', $out[2]['status'] );
		$this->assertSame( 'ok', $out[1]['status'] );
	}

	public function test_broken_links_are_added_to_a_page_and_change_its_status() {
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				return array( 'show_on_front' => 'page', 'page_on_front' => 1 )[ $key ] ?? false;
			}
		);

		$results = array(
			1 => array( 'status' => 'ok', 'issues' => array(), 'content' => array( 'links' => array( 'https://gone.test/' ), 'inbound' => array( 1 ) ) ),
		);

		$out = lcp_yeast_seo_health_add_link_issues( array( 1 ), $results, array( 'https://gone.test/' => array( 'code' => 404, 'time' => 1 ) ) );

		$this->assertSame( 'error', $out[1]['status'] );
		$this->assertSame( array( 'content_broken_link' ), array_column( $out[1]['issues'], 'code' ) );
	}

	public function test_content_issues_are_their_own_area() {
		$this->assertSame( 'content', lcp_yeast_seo_health_area_of( 'content_orphan' ) );
		$this->assertSame( 'content', lcp_yeast_seo_health_area_of( 'content_img_no_alt' ) );
		$this->assertSame( 'meta', lcp_yeast_seo_health_area_of( 'meta_no_title' ) );
		$this->assertSame( 'schema', lcp_yeast_seo_health_area_of( 'dangling_ref' ) );

		$result = array( 'issues' => array( array( 'level' => 'warning', 'code' => 'content_orphan', 'message' => '' ) ), 'content' => array() );

		$this->assertSame( 'warning', lcp_yeast_seo_health_area_status( $result, 'content' ) );
		$this->assertSame( 'ok', lcp_yeast_seo_health_area_status( $result, 'schema' ) );
		$this->assertSame( 'none', lcp_yeast_seo_health_area_status( array( 'issues' => array() ), 'content' ), 'results from before these checks existed are not judged' );
	}
}
