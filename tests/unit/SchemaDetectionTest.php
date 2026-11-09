<?php
namespace Lcp\YeastSeo\Tests;

class SchemaDetectionTest extends TestCase {

	private function ld( array $data ) {
		return '<script type="application/ld+json">' . json_encode( $data ) . '</script>';
	}

	private function context() {
		return array(
			'home_url'       => 'http://example.test/',
			'site_name'      => 'Example',
			'tagline'        => '',
			'language'       => 'en-GB',
			'org_id'         => '',
			'is_front'       => false,
			'declared_types' => array(),
			'page'           => array(
				'url'          => 'http://example.test/guides/a-post/',
				'name'         => 'A post',
				'description'  => '',
				'kind'         => 'WebPage',
				'article_type' => 'Article',
				'published'    => '2026-01-01T10:00:00+00:00',
				'modified'     => '2026-01-01T10:00:00+00:00',
				'author'       => null,
				'image'        => '',
				'breadcrumbs'  => array(
					array( 'Home', 'http://example.test/' ),
					array( 'A post', 'http://example.test/guides/a-post/' ),
				),
			),
		);
	}

	private function page( $head = '', $body = '' ) {
		return "<html><head><title>x</title>$head</head><body>$body</body></html>";
	}

	private function generated_types( $html ) {
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
		$types = array();

		foreach ( $m[1] as $json ) {
			$data = json_decode( $json, true );

			foreach ( $data['@graph'] ?? array( $data ) as $node ) {
				$types[] = $node['@type'];
			}
		}

		return $types;
	}

	public function test_detects_a_single_json_ld_entity() {
		$this->assertSame( array( 'BreadcrumbList' ), lcp_yeast_seo_detect_schema_types( $this->ld( array( '@type' => 'BreadcrumbList' ) ) ) );
	}

	public function test_detects_entities_inside_a_graph_and_list_types() {
		$html = $this->ld( array( '@graph' => array( array( '@type' => 'WebSite' ), array( '@type' => array( 'Article', 'BlogPosting' ) ) ) ) );

		$this->assertEqualsCanonicalizing( array( 'WebSite', 'Article', 'BlogPosting' ), lcp_yeast_seo_detect_schema_types( $html ) );
	}

	public function test_nested_types_are_not_counted_as_page_entities() {
		$html = $this->ld( array( '@type' => 'Article', 'author' => array( '@type' => 'Person' ), 'publisher' => array( '@type' => 'Organization' ) ) );

		$this->assertSame( array( 'Article' ), lcp_yeast_seo_detect_schema_types( $html ) );
	}

	public function test_detects_microdata() {
		$html = '<nav itemscope itemtype="https://schema.org/BreadcrumbList"><li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"></li></nav>';

		$this->assertEqualsCanonicalizing( array( 'BreadcrumbList', 'ListItem' ), lcp_yeast_seo_detect_schema_types( $html ) );
	}

	public function test_invalid_json_and_plain_pages_are_ignored() {
		$this->assertSame( array(), lcp_yeast_seo_detect_schema_types( '<script type="application/ld+json">{not json</script><p>Hello</p>' ) );
		$this->assertSame( array(), lcp_yeast_seo_detect_schema_types( '' ) );
	}

	public function test_detects_schema_from_the_body_as_well_as_the_head() {
		$html = $this->page( $this->ld( array( '@type' => 'WebSite' ) ), $this->ld( array( '@type' => 'FAQPage' ) ) );

		$this->assertEqualsCanonicalizing( array( 'WebSite', 'FAQPage' ), lcp_yeast_seo_detect_schema_types( $html ) );
	}

	public function test_injects_the_graph_before_the_closing_head_tag() {
		$out = lcp_yeast_seo_inject_auto_schema( $this->page(), $this->context() );

		$this->assertLessThan( stripos( $out, '</head>' ), strpos( $out, 'application/ld+json' ) );
		$this->assertEqualsCanonicalizing( array( 'WebSite', 'WebPage', 'Article', 'BreadcrumbList' ), $this->generated_types( $out ) );
	}

	public function test_skips_types_the_theme_prints_in_the_body() {
		$body = $this->ld( array( '@type' => 'BreadcrumbList' ) ) . $this->ld( array( '@type' => 'BlogPosting' ) );
		$out  = lcp_yeast_seo_inject_auto_schema( $this->page( '', $body ), $this->context() );

		$types = $this->generated_types( $out );

		$this->assertSame( 1, count( array_keys( $types, 'BreadcrumbList', true ) ), 'only the theme\'s breadcrumb' );
		$this->assertNotContains( 'Article', $types, 'BlogPosting counts as the article' );
		$this->assertContains( 'WebPage', $types );
		$this->assertContains( 'WebSite', $types );
	}

	public function test_skips_a_breadcrumb_printed_as_microdata() {
		$body = '<nav itemscope itemtype="https://schema.org/BreadcrumbList"></nav>';
		$out  = lcp_yeast_seo_inject_auto_schema( $this->page( '', $body ), $this->context() );

		$this->assertNotContains( 'BreadcrumbList', $this->generated_types( $out ) );
	}

	public function test_adds_nothing_when_everything_is_already_there() {
		$body = $this->ld( array( '@graph' => array( array( '@type' => 'WebSite' ), array( '@type' => 'WebPage' ), array( '@type' => 'Article' ), array( '@type' => 'BreadcrumbList' ) ) ) );
		$html = $this->page( '', $body );

		$this->assertSame( $html, lcp_yeast_seo_inject_auto_schema( $html, $this->context() ) );
	}

	public function test_a_page_without_a_head_is_returned_untouched() {
		$this->assertSame( '<p>fragment</p>', lcp_yeast_seo_inject_auto_schema( '<p>fragment</p>', $this->context() ) );
	}

	public function test_context_declared_types_are_still_respected() {
		$ctx                   = $this->context();
		$ctx['declared_types'] = array( 'FAQPage', 'WebPage' );

		$this->assertNotContains( 'WebPage', $this->generated_types( lcp_yeast_seo_inject_auto_schema( $this->page(), $ctx ) ) );
	}

	public function test_the_page_stays_otherwise_identical() {
		$html = $this->page( '<meta name="x" content="y">', '<p>Body</p>' );
		$out  = lcp_yeast_seo_inject_auto_schema( $html, $this->context() );

		$this->assertSame( $html, preg_replace( '#<script type="application/ld\+json">.*?</script>\n#s', '', $out ) );
	}
}
