<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

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
				'publisher' => array( '@id' => 'http://example.test/a/#publisher' ),
			)
		);

		$result = $this->analyze( $this->page( $html ) );

		$this->assertSame( array( 'dangling_ref' ), $this->codes( $result ) );
		$this->assertStringContainsString( '#publisher', $result['issues'][0]['message'] );
	}

	public function test_references_to_the_home_page_organization_and_website_are_not_flagged() {
		$page = static function ( $ref ) {
			return array(
				'@context' => 'https://schema.org',
				'@type'    => 'WebPage',
				'name'     => 'A',
				'about'    => array( '@id' => $ref ),
			);
		};

		foreach ( array( 'http://example.test/#organization', 'http://example.test/#website', 'http://example.test#organization' ) as $ref ) {
			$this->assertNotContains( 'dangling_ref', $this->codes( $this->analyze( $this->page( $this->ld( $page( $ref ) ) ) ) ), $ref );
		}

		foreach ( array( 'http://example.test/blog/#organization', 'http://example.test/#person' ) as $ref ) {
			$this->assertContains( 'dangling_ref', $this->codes( $this->analyze( $this->page( $this->ld( $page( $ref ) ) ) ) ), $ref );
		}
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
		$this->assertNotSame( '', lcp_yeast_seo_health_hint( 'meta_duplicate_title' ) );
		$this->assertNotSame( '', lcp_yeast_seo_health_hint( 'meta_duplicate_description' ) );
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

	private function head( array $over = array() ) {
		$parts = array_merge(
			array(
				'title'     => '<title>A good page title for search</title>',
				'desc'      => '<meta name="description" content="' . str_repeat( 'A useful summary of the page. ', 3 ) . '">',
				'canonical' => '<link rel="canonical" href="http://example.test/a/">',
				'og_title'  => '<meta property="og:title" content="A">',
				'og_image'  => '<meta property="og:image" content="http://example.test/i.jpg">',
				'robots'    => '',
				'h1'        => '<h1>A</h1>',
			),
			$over
		);

		return '<html><head>' . implode( '', array_diff_key( $parts, array( 'h1' => 1 ) ) ) . '</head><body>' . $parts['h1'] . '</body></html>';
	}

	private function meta_codes( $html, $url = 'http://example.test/a/' ) {
		return array_column( lcp_yeast_seo_health_analyze_meta( $html, $url )['issues'], 'code' );
	}

	public function test_a_page_with_good_meta_has_no_meta_issues() {
		$this->assertSame( array(), $this->meta_codes( $this->head() ) );
	}

	public function test_a_missing_title_and_description_are_errors() {
		$result = lcp_yeast_seo_health_analyze_meta( $this->head( array( 'title' => '', 'desc' => '' ) ), 'http://example.test/a/' );
		$levels = array_column( $result['issues'], 'level', 'code' );

		$this->assertSame( 'error', $levels['meta_no_title'] );
		$this->assertSame( 'error', $levels['meta_no_description'] );
	}

	public function test_an_empty_description_counts_as_missing() {
		$this->assertContains( 'meta_no_description', $this->meta_codes( $this->head( array( 'desc' => '<meta name="description" content="  ">' ) ) ) );
	}

	public function test_title_and_description_length_are_warnings() {
		$long  = $this->meta_codes( $this->head( array( 'title' => '<title>' . str_repeat( 'a', 61 ) . '</title>', 'desc' => '<meta name="description" content="' . str_repeat( 'a', 161 ) . '">' ) ) );
		$short = $this->meta_codes( $this->head( array( 'title' => '<title>Hi</title>', 'desc' => '<meta name="description" content="Short">' ) ) );

		$this->assertContains( 'meta_title_long', $long );
		$this->assertContains( 'meta_description_long', $long );
		$this->assertContains( 'meta_title_short', $short );
		$this->assertContains( 'meta_description_short', $short );
		$this->assertSame( array(), array_diff( array_merge( $long, $short ), array( 'meta_title_long', 'meta_description_long', 'meta_title_short', 'meta_description_short' ) ) );
	}

	public function test_lengths_at_the_limits_are_fine() {
		$html = $this->head( array( 'title' => '<title>' . str_repeat( 'a', 60 ) . '</title>', 'desc' => '<meta name="description" content="' . str_repeat( 'a', 160 ) . '">' ) );

		$this->assertSame( array(), $this->meta_codes( $html ) );
	}

	public function test_title_entities_and_attribute_order_are_read() {
		$html = $this->head( array( 'title' => '<title>Fish &amp; chips: the very best in town</title>', 'desc' => '<meta content="' . str_repeat( 'Nice summary. ', 6 ) . '" name="description">' ) );
		$meta = lcp_yeast_seo_health_analyze_meta( $html, 'http://example.test/a/' );

		$this->assertSame( 'Fish & chips: the very best in town', $meta['facts']['title'] );
		$this->assertNotSame( '', $meta['facts']['description'] );
		$this->assertSame( array(), array_column( $meta['issues'], 'code' ) );
	}

	public function test_a_noindex_page_in_the_sitemap_is_noted_and_skips_the_other_checks() {
		$result = lcp_yeast_seo_health_analyze_meta( $this->head( array( 'title' => '', 'desc' => '', 'robots' => '<meta name="robots" content="noindex, follow">' ) ), 'http://example.test/a/' );

		$this->assertSame( array( 'meta_noindex' ), array_column( $result['issues'], 'code' ) );
		$this->assertFalse( $result['facts']['indexable'] );
	}

	public function test_a_site_wide_noindex_is_ignored_and_the_checks_still_run() {
		$html   = $this->head( array( 'desc' => '', 'robots' => '<meta name="robots" content="noindex, nofollow">' ) );
		$result = lcp_yeast_seo_health_analyze_meta( $html, 'http://example.test/a/', true );

		$this->assertSame( array( 'meta_no_description' ), array_column( $result['issues'], 'code' ) );
		$this->assertTrue( $result['facts']['indexable'] );
	}

	public function test_canonical_must_exist_and_match_the_page() {
		$this->assertContains( 'meta_no_canonical', $this->meta_codes( $this->head( array( 'canonical' => '' ) ) ) );
		$this->assertContains( 'meta_canonical_other', $this->meta_codes( $this->head( array( 'canonical' => '<link rel="canonical" href="http://example.test/b/">' ) ) ) );
		$this->assertContains( 'meta_canonical_other', $this->meta_codes( $this->head( array( 'canonical' => '<link rel="canonical" href="http://example.test/a/?lcp_health=1">' ) ) ) );
		$this->assertNotContains( 'meta_canonical_other', $this->meta_codes( $this->head( array( 'canonical' => '<link rel="canonical" href="HTTP://EXAMPLE.TEST/a/#top">' ) ) ) );
		$this->assertContains( 'meta_canonical_other', $this->meta_codes( $this->head( array( 'canonical' => '<link rel="canonical" href="http://example.test/a">' ) ) ) );
	}

	public function test_exactly_one_h1_is_expected() {
		$this->assertContains( 'meta_no_h1', $this->meta_codes( $this->head( array( 'h1' => '<h2>A</h2>' ) ) ) );
		$this->assertContains( 'meta_multiple_h1', $this->meta_codes( $this->head( array( 'h1' => '<h1>A</h1><h1 class="x">B</h1>' ) ) ) );
	}

	public function test_social_tags_are_checked() {
		$codes = $this->meta_codes( $this->head( array( 'og_title' => '', 'og_image' => '<meta property="og:image" content="">' ) ) );

		$this->assertContains( 'meta_no_og_title', $codes );
		$this->assertContains( 'meta_no_og_image', $codes );
	}

	public function test_meta_issues_are_told_apart_from_schema_issues() {
		$this->assertSame( 'meta', lcp_yeast_seo_health_area_of( 'meta_no_title' ) );
		$this->assertSame( 'schema', lcp_yeast_seo_health_area_of( 'dangling_ref' ) );

		$result = array(
			'issues' => array(
				array( 'level' => 'error', 'code' => 'meta_no_title', 'message' => '' ),
				array( 'level' => 'warning', 'code' => 'article_no_image', 'message' => '' ),
			),
			'meta'   => array(),
		);

		$this->assertSame( 'error', lcp_yeast_seo_health_area_status( $result, 'meta' ) );
		$this->assertSame( 'warning', lcp_yeast_seo_health_area_status( $result, 'schema' ) );
		$this->assertSame( 'none', lcp_yeast_seo_health_area_status( null, 'schema' ) );
		unset( $result['meta'] );
		$this->assertSame( 'none', lcp_yeast_seo_health_area_status( $result, 'meta' ), 'results from before meta checks existed are not judged' );
	}

	public function test_pages_sharing_a_title_or_description_are_flagged_and_status_follows() {
		Functions\when( 'get_the_title' )->alias( static function ( $id ) {
			return "Page $id";
		} );

		$meta = static function ( $title, $desc, $indexable = true ) {
			return array( 'title' => $title, 'description' => $desc, 'indexable' => $indexable );
		};
		$base = array( 'status' => 'ok', 'issues' => array() );

		$results = array(
			1 => $base + array( 'meta' => $meta( 'Same title', 'One' ) ),
			2 => $base + array( 'meta' => $meta( 'same TITLE', 'Two' ) ),
			3 => $base + array( 'meta' => $meta( 'Unique', 'Two' ) ),
			4 => $base + array( 'meta' => $meta( 'Same title', 'Four', false ) ),
			5 => $base,
		);

		$effective = lcp_yeast_seo_health_effective( array( 1, 2, 3, 4, 5, 6 ), $results );

		$this->assertSame( array( 'meta_duplicate_title' ), array_column( $effective[1]['issues'], 'code' ) );
		$this->assertSame( array( 'meta_duplicate_title', 'meta_duplicate_description' ), array_column( $effective[2]['issues'], 'code' ) );
		$this->assertSame( array( 'meta_duplicate_description' ), array_column( $effective[3]['issues'], 'code' ) );
		$this->assertSame( 'error', $effective[1]['status'] );
		$this->assertSame( 'ok', $effective[4]['status'], 'a noindex page is not part of the comparison' );
		$this->assertSame( 'ok', $effective[5]['status'] );
		$this->assertArrayNotHasKey( 6, $effective, 'unchecked pages are left out' );
		$this->assertStringContainsString( 'Page 2', $effective[1]['issues'][0]['message'] );
	}

	public function test_duplicates_are_listed_once_with_a_page_neutral_summary() {
		Functions\when( 'get_the_title' )->justReturn( 'x' );

		$issues  = array();
		$results = array();

		foreach ( array( 1, 2 ) as $id ) {
			$results[ $id ] = array( 'status' => 'ok', 'issues' => $issues, 'meta' => array( 'title' => 'Same', 'description' => 'd' . $id, 'indexable' => true ) );
		}

		$problems = lcp_yeast_seo_health_common_problems( array( 1, 2 ), lcp_yeast_seo_health_effective( array( 1, 2 ), $results ) );

		$this->assertSame( 1, count( $problems ) );
		$this->assertSame( 2, $problems[0]['pages'] );
		$this->assertSame( 'Pages share the same title.', $problems[0]['message'] );
	}

	public function test_connection_failures_are_told_apart_from_slow_or_failed_pages() {
		foreach ( array(
			'cURL error 28: Failed to connect to www.example.test port 443 after 10001 ms: Timeout was reached',
			'cURL error 7: Failed to connect to example.test port 443: Connection refused',
			'cURL error 6: Could not resolve host: example.test',
			'cURL error 28: Connection timed out after 10001 milliseconds',
		) as $message ) {
			$this->assertTrue( lcp_yeast_seo_health_is_connect_failure( $message ), $message );
		}

		foreach ( array(
			'cURL error 28: Operation timed out after 12001 milliseconds with 0 bytes received',
			'cURL error 60: SSL certificate problem',
			'',
		) as $message ) {
			$this->assertFalse( lcp_yeast_seo_health_is_connect_failure( $message ), $message );
		}
	}

	public function test_local_addresses_are_valid_unique_and_end_with_loopback() {
		$_SERVER['SERVER_ADDR'] = '10.1.2.3';
		$this->assertSame( array( '10.1.2.3', '127.0.0.1' ), lcp_yeast_seo_health_local_ips() );

		$_SERVER['SERVER_ADDR'] = '127.0.0.1';
		$this->assertSame( array( '127.0.0.1' ), lcp_yeast_seo_health_local_ips() );

		$_SERVER['SERVER_ADDR'] = 'not an ip; rm -rf';
		$this->assertSame( array( '127.0.0.1' ), lcp_yeast_seo_health_local_ips() );

		unset( $_SERVER['SERVER_ADDR'] );
	}

	public function test_a_location_header_becomes_an_absolute_url() {
		$base = 'https://example.test/shop/page/?lcp_health=1';

		$this->assertSame( 'https://other.test/p/1/', lcp_yeast_seo_health_resolve_location( 'https://other.test/p/1/', $base ) );
		$this->assertSame( 'https://example.test/cart/', lcp_yeast_seo_health_resolve_location( '/cart/', $base ) );
		$this->assertSame( 'https://elsewhere.test/x', lcp_yeast_seo_health_resolve_location( '//elsewhere.test/x', $base ) );
		$this->assertSame( 'https://example.test/shop/page/next', lcp_yeast_seo_health_resolve_location( 'next', $base ) );
		$this->assertSame( '', lcp_yeast_seo_health_resolve_location( '  ', $base ) );
	}

	public function test_the_scans_cache_buster_is_not_shown_in_a_redirect_target() {
		$this->assertSame( 'https://example.test/cart/', lcp_yeast_seo_health_strip_scan_arg( 'https://example.test/cart/?lcp_health=123' ) );
		$this->assertSame( 'https://example.test/cart/?a=1', lcp_yeast_seo_health_strip_scan_arg( 'https://example.test/cart/?lcp_health=123&a=1' ) );
		$this->assertSame( 'https://example.test/cart/?a=1', lcp_yeast_seo_health_strip_scan_arg( 'https://example.test/cart/?a=1&lcp_health=123' ) );
	}
}
