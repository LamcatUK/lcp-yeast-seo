<?php
namespace Lcp\YeastSeo\Tests;

class HealthTest extends TestCase {

	private $host = 'example.test';

	private function ld( array $data ) {
		return '<script type="application/ld+json">' . json_encode( $data ) . '</script>';
	}

	private function page( $body ) {
		return "<html><head><title>x</title></head><body>$body</body></html>";
	}

	private function codes( array $result ) {
		return array_column( $result['issues'], 'code' );
	}

	private function analyze( $html ) {
		return lcp_yeast_seo_health_analyze( $html, $this->host );
	}

	private function good_graph() {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebSite', '@id' => 'http://example.test/#website', 'name' => 'Example', 'url' => 'http://example.test/' ),
				array( '@type' => 'WebPage', '@id' => 'http://example.test/a/#webpage', 'name' => 'A', 'isPartOf' => array( '@id' => 'http://example.test/#website' ) ),
				array(
					'@type'         => 'BlogPosting',
					'@id'           => 'http://example.test/a/#article',
					'headline'      => 'A',
					'datePublished' => '2026-01-01',
					'author'        => array( '@type' => 'Person', 'name' => 'Sam' ),
					'image'         => array( '@id' => 'http://example.test/a/#image' ),
					'isPartOf'      => array( '@id' => 'http://example.test/a/#webpage' ),
				),
				array( '@type' => 'ImageObject', '@id' => 'http://example.test/a/#image', 'url' => 'http://example.test/i.jpg' ),
				array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'http://example.test/' ),
						array( '@type' => 'ListItem', 'position' => 2, 'name' => 'A' ),
					),
				),
			),
		);
	}

	public function test_a_complete_graph_is_healthy() {
		$result = $this->analyze( $this->page( $this->ld( $this->good_graph() ) ) );

		$this->assertSame( array(), $result['issues'] );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertEqualsCanonicalizing( array( 'WebSite', 'WebPage', 'BlogPosting', 'ImageObject', 'BreadcrumbList' ), $result['types'] );
	}

	public function test_a_page_with_no_schema_is_an_error() {
		$result = $this->analyze( $this->page( '<p>Hello</p>' ) );

		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( array( 'no_schema' ), $this->codes( $result ) );
	}

	public function test_microdata_counts_as_schema() {
		$result = $this->analyze( $this->page( '<nav itemscope itemtype="https://schema.org/BreadcrumbList"></nav>' ) );

		$this->assertNotContains( 'no_schema', $this->codes( $result ) );
		$this->assertContains( 'BreadcrumbList', $result['types'] );
	}

	public function test_invalid_json_is_an_error() {
		$result = $this->analyze( $this->page( '<script type="application/ld+json">{"@type": "Thing",}</script>' ) );

		$this->assertContains( 'invalid_json', $this->codes( $result ) );
		$this->assertSame( 'error', $result['status'] );
	}

	public function test_a_block_without_a_context_is_an_error() {
		$result = $this->analyze( $this->page( $this->ld( array( '@type' => 'WebSite', 'name' => 'x' ) ) ) );

		$this->assertContains( 'no_context', $this->codes( $result ) );
	}

	public function test_a_graph_without_a_context_is_an_error_even_if_entities_have_one() {
		$result = $this->analyze( $this->page( $this->ld( array( '@graph' => array( array( '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'x' ) ) ) ) ) );

		$this->assertContains( 'no_context', $this->codes( $result ) );
	}

	public function test_a_list_of_entities_each_with_a_context_is_fine() {
		$html = $this->ld( array( array( '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'x' ), array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'y' ) ) );

		$this->assertNotContains( 'no_context', $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_an_entity_without_a_type_is_a_warning() {
		$result = $this->analyze( $this->page( $this->ld( array( '@context' => 'https://schema.org', '@graph' => array( array( 'name' => 'Mystery' ) ) ) ) ) );

		$this->assertContains( 'no_type', $this->codes( $result ) );
		$this->assertSame( 'warning', $result['status'] );
	}

	public function test_two_page_entities_are_a_duplicate_error() {
		$graph = array( '@context' => 'https://schema.org', '@graph' => array( array( '@type' => 'WebPage', 'name' => 'a' ), array( '@type' => 'CollectionPage', 'name' => 'b' ) ) );

		$this->assertContains( 'duplicate_webpage', $this->codes( $this->analyze( $this->page( $this->ld( $graph ) ) ) ) );
	}

	public function test_a_faq_next_to_a_webpage_is_not_a_duplicate() {
		$html  = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'a' ) );
		$html .= $this->ld( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array( array( '@type' => 'Question', 'name' => 'Q?', 'acceptedAnswer' => array( 'text' => 'A.' ) ) ) ) );

		$this->assertSame( array(), $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_duplicates_are_found_across_separate_blocks() {
		$html  = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array( array( 'position' => 1, 'name' => 'Home' ) ) ) );
		$html .= $this->ld( array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array( array( 'position' => 1, 'name' => 'Home' ) ) ) );

		$this->assertContains( 'duplicate_breadcrumb', $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_an_article_missing_recommended_properties_is_warned_about() {
		$html   = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'A' ) );
		$result = $this->analyze( $this->page( $html ) );

		$this->assertEqualsCanonicalizing( array( 'article_no_datepublished', 'article_no_author', 'article_no_image' ), $this->codes( $result ) );
		$this->assertSame( 'warning', $result['status'] );
	}

	public function test_an_empty_breadcrumb_list_is_an_error() {
		$result = $this->analyze( $this->page( $this->ld( array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array() ) ) ) );

		$this->assertContains( 'breadcrumb_empty', $this->codes( $result ) );
	}

	public function test_breadcrumb_items_need_names_and_positions() {
		$html   = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array( array( '@type' => 'ListItem', 'item' => 'http://example.test/' ) ) ) );
		$result = $this->analyze( $this->page( $html ) );

		$this->assertEqualsCanonicalizing( array( 'breadcrumb_no_name', 'breadcrumb_no_position' ), $this->codes( $result ) );
	}

	public function test_a_faq_without_answers_is_warned_about() {
		$html = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array( array( '@type' => 'Question', 'name' => 'Q?' ) ) ) );

		$this->assertContains( 'faq_incomplete', $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_an_empty_faq_is_an_error() {
		$html = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array() ) );

		$this->assertContains( 'faq_empty', $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_a_reference_to_an_entity_that_is_not_on_the_page_is_flagged() {
		$html = $this->ld(
			array(
				'@context'  => 'https://schema.org',
				'@type'     => 'BlogPosting',
				'headline'  => 'A',
				'datePublished' => 'x',
				'author'    => array( '@type' => 'Person', 'name' => 's' ),
				'image'     => 'http://example.test/i.jpg',
				'publisher' => array( '@id' => 'http://example.test/#organization' ),
			)
		);

		$result = $this->analyze( $this->page( $html ) );

		$this->assertSame( array( 'dangling_ref' ), $this->codes( $result ) );
		$this->assertStringContainsString( '#organization', $result['issues'][0]['message'] );
	}

	public function test_references_to_plain_urls_and_other_sites_are_not_flagged() {
		$html = $this->ld(
			array(
				'@context'         => 'https://schema.org',
				'@type'            => 'WebPage',
				'name'             => 'A',
				'mainEntityOfPage' => array( '@id' => 'http://example.test/a/' ),
				'about'            => array( '@id' => 'https://other.test/#thing' ),
			)
		);

		$this->assertNotContains( 'dangling_ref', $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_an_id_defined_in_another_block_resolves_the_reference() {
		$html  = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'LocalBusiness', '@id' => 'http://example.test/#organization', 'name' => 'Biz', 'address' => 'Here' ) );
		$html .= $this->ld( array( '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'S', 'publisher' => array( '@id' => 'http://example.test/#organization' ) ) );

		$this->assertSame( array(), $this->codes( $this->analyze( $this->page( $html ) ) ) );
	}

	public function test_a_local_business_needs_an_address_and_names_are_expected() {
		$result = $this->analyze( $this->page( $this->ld( array( '@context' => 'https://schema.org', '@type' => 'LocalBusiness' ) ) ) );

		$this->assertEqualsCanonicalizing( array( 'no_name', 'localbusiness_no_address' ), $this->codes( $result ) );
	}

	public function test_an_error_outranks_a_warning_in_the_status() {
		$html   = $this->ld( array( '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'A' ) );
		$html  .= '<script type="application/ld+json">{oops</script>';
		$result = $this->analyze( $this->page( $html ) );

		$this->assertSame( 'error', $result['status'] );
	}

	public function test_every_kind_of_problem_the_scan_can_report_has_a_hint() {
		$source = file_get_contents( LCP_YEAST_SEO_PATH . 'inc/health.php' );

		preg_match_all( "/push\\( '(?:error|warning)', '([a-z_]+)'/", $source, $matches );
		preg_match_all( "/'code'\\s*=>\\s*'([a-z_]+)'/", $source, $more );

		$codes = array_unique( array_merge( $matches[1], $more[1] ) );

		$this->assertNotEmpty( $codes );

		foreach ( $codes as $code ) {
			if ( '_' === substr( $code, -1 ) ) {
				continue; // A prefix completed in code: duplicate_*, http_*, article_no_*.
			}

			$this->assertNotSame( '', lcp_yeast_seo_health_hint( $code ), "$code has no hint" );
		}

		foreach ( array( 'headline', 'datepublished', 'author', 'image' ) as $property ) {
			$this->assertNotSame( '', lcp_yeast_seo_health_hint( 'article_no_' . $property ), "article_no_$property has no hint" );
		}

		$this->assertNotSame( '', lcp_yeast_seo_health_hint( 'duplicate_breadcrumb' ) );
		$this->assertNotSame( '', lcp_yeast_seo_health_hint( 'http_404' ) );
		$this->assertSame( '', lcp_yeast_seo_health_hint( 'something_unknown' ) );
	}

	public function test_totals_count_only_pages_in_scope_that_were_checked() {
		$results = array(
			1 => array( 'status' => 'ok' ),
			2 => array( 'status' => 'warning' ),
			3 => array( 'status' => 'error' ),
			9 => array( 'status' => 'ok' ),
		);

		$this->assertSame(
			array( 'total' => 4, 'checked' => 3, 'ok' => 1, 'warning' => 1, 'error' => 1 ),
			lcp_yeast_seo_health_totals( array( 1, 2, 3, 4 ), $results )
		);
	}

	public function test_common_problems_are_ranked_errors_first_then_by_page_count() {
		$issue = static function ( $level, $code ) {
			return array( 'level' => $level, 'code' => $code, 'message' => $code );
		};
		$results = array(
			1 => array( 'issues' => array( $issue( 'warning', 'w_common' ), $issue( 'error', 'e_rare' ) ) ),
			2 => array( 'issues' => array( $issue( 'warning', 'w_common' ) ) ),
			3 => array( 'issues' => array( $issue( 'warning', 'w_common' ), $issue( 'warning', 'w_once' ) ) ),
		);

		$problems = lcp_yeast_seo_health_common_problems( array( 1, 2, 3 ), $results );

		$this->assertSame( array( 'e_rare', 'w_common', 'w_once' ), array_column( $problems, 'message' ) );
		$this->assertSame( array( 1, 3, 1 ), array_column( $problems, 'pages' ) );
	}
}
