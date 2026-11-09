<?php
namespace Lcp\YeastSeo\Tests;

class CanonicalTest extends TestCase {

	private $keep = array( 'paged', 'cat', 'post_type' );

	public function test_tracking_parameters_are_removed() {
		$this->assertSame(
			'https://example.test/guides/',
			lcp_yeast_seo_strip_query_args( 'https://example.test/guides/?utm_source=x&fbclid=abc&v=123', $this->keep )
		);
	}

	public function test_wordpress_query_vars_are_kept() {
		$this->assertSame(
			'https://example.test/?cat=3&paged=2',
			lcp_yeast_seo_strip_query_args( 'https://example.test/?cat=3&utm_medium=email&paged=2', $this->keep )
		);
	}

	public function test_pretty_pagination_is_untouched() {
		$this->assertSame(
			'https://example.test/guides/page/2/',
			lcp_yeast_seo_strip_query_args( 'https://example.test/guides/page/2/?utm_source=x', $this->keep )
		);
	}

	public function test_a_url_without_a_query_is_returned_as_is() {
		$this->assertSame( 'https://example.test/guides/', lcp_yeast_seo_strip_query_args( 'https://example.test/guides/', $this->keep ) );
	}

	public function test_an_empty_keep_list_removes_everything() {
		$this->assertSame( 'https://example.test/a/', lcp_yeast_seo_strip_query_args( 'https://example.test/a/?paged=2&x=1', array() ) );
	}
}
