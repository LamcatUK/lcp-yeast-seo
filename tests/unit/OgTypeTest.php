<?php
namespace Lcp\YeastSeo\Tests;

class OgTypeTest extends TestCase {

	public function test_posts_are_articles() {
		$this->assertSame( 'article', lcp_yeast_seo_og_type( 'post', true, false ) );
	}

	public function test_pages_are_websites() {
		$this->assertSame( 'website', lcp_yeast_seo_og_type( 'page', true, false ) );
	}

	public function test_the_front_page_and_listings_are_websites() {
		$this->assertSame( 'website', lcp_yeast_seo_og_type( 'page', true, true ) );
		$this->assertSame( 'website', lcp_yeast_seo_og_type( 'post', false, false ) );
	}
}
