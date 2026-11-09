<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class AutoSchemaTest extends TestCase {

	private function context( array $override = array() ) {
		$page = array(
			'url'          => 'http://example.test/guides/a-post/',
			'name'         => 'A post',
			'description'  => 'About a post.',
			'kind'         => 'WebPage',
			'article_type' => 'Article',
			'published'    => '2026-01-01T10:00:00+00:00',
			'modified'     => '2026-02-01T10:00:00+00:00',
			'author'       => array(
				'name' => 'Sam',
				'url'  => 'http://example.test/author/sam/',
			),
			'image'        => 'http://example.test/img.jpg',
			'breadcrumbs'  => array(
				array( 'Home', 'http://example.test/' ),
				array( 'Guides', 'http://example.test/guides/' ),
				array( 'A post', 'http://example.test/guides/a-post/' ),
			),
		);

		return $override + array(
			'home_url'       => 'http://example.test/',
			'site_name'      => 'Example',
			'tagline'        => 'Just a tagline',
			'language'       => 'en-GB',
			'org_id'         => '',
			'is_front'       => false,
			'declared_types' => array(),
			'page'           => $page,
		);
	}

	private function types( array $nodes ) {
		return array_map(
			static function ( $node ) {
				return $node['@type'];
			},
			$nodes
		);
	}

	private function node( array $nodes, $type ) {
		foreach ( $nodes as $node ) {
			if ( $type === $node['@type'] ) {
				return $node;
			}
		}

		return null;
	}

	public function test_a_post_gets_the_full_graph() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context() );

		$this->assertSame( array( 'WebSite', 'WebPage', 'Article', 'ImageObject', 'BreadcrumbList' ), $this->types( $nodes ) );
	}

	public function test_ids_link_the_nodes_together() {
		$nodes   = lcp_yeast_seo_build_auto_graph( $this->context() );
		$webpage = $this->node( $nodes, 'WebPage' );
		$article = $this->node( $nodes, 'Article' );

		$this->assertSame( 'http://example.test/#website', $webpage['isPartOf']['@id'] );
		$this->assertSame( $webpage['@id'], $article['mainEntityOfPage']['@id'] );
		$this->assertSame( $webpage['breadcrumb']['@id'], $this->node( $nodes, 'BreadcrumbList' )['@id'] );
		$this->assertSame( $article['image']['@id'], $this->node( $nodes, 'ImageObject' )['@id'] );
	}

	public function test_breadcrumb_positions_and_last_item_has_no_url() {
		$items = $this->node( lcp_yeast_seo_build_auto_graph( $this->context() ), 'BreadcrumbList' )['itemListElement'];

		$this->assertSame( array( 1, 2, 3 ), array_column( $items, 'position' ) );
		$this->assertArrayHasKey( 'item', $items[1] );
		$this->assertArrayNotHasKey( 'item', $items[2] );
	}

	public function test_a_single_crumb_is_not_a_breadcrumb_trail() {
		$ctx                       = $this->context();
		$ctx['page']['breadcrumbs'] = array( array( 'Home', 'http://example.test/' ) );

		$this->assertNull( $this->node( lcp_yeast_seo_build_auto_graph( $ctx ), 'BreadcrumbList' ) );
	}

	public function test_a_page_without_an_article_type_gets_no_article() {
		$ctx                         = $this->context();
		$ctx['page']['article_type'] = '';

		$nodes = lcp_yeast_seo_build_auto_graph( $ctx );

		$this->assertNull( $this->node( $nodes, 'Article' ) );
		$this->assertNotNull( $this->node( $nodes, 'WebPage' ) );
	}

	public function test_types_the_page_already_declares_are_not_generated() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context( array( 'declared_types' => array( 'BlogPosting', 'ContactPage', 'WebSite', 'BreadcrumbList' ) ) ) );

		$this->assertSame( array(), $this->types( $nodes ), 'nothing is left to generate, not even the orphaned image' );
		$this->assertNull( $this->node( $nodes, 'WebSite' ) );
		$this->assertNull( $this->node( $nodes, 'WebPage' ), 'ContactPage counts as a WebPage' );
		$this->assertNull( $this->node( $nodes, 'Article' ), 'BlogPosting counts as an Article' );
		$this->assertNull( $this->node( $nodes, 'BreadcrumbList' ) );
	}

	public function test_a_faq_block_does_not_replace_the_webpage() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context( array( 'declared_types' => array( 'FAQPage' ) ) ) );

		$this->assertNotNull( $this->node( $nodes, 'WebPage' ), 'FAQPage sits alongside the WebPage' );
		$this->assertNotNull( $this->node( $nodes, 'Article' ) );
	}

	public function test_article_points_at_the_url_when_the_webpage_is_the_pages_own() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context( array( 'declared_types' => array( 'WebPage' ) ) ) );

		$article = $this->node( $nodes, 'Article' );

		$this->assertNull( $this->node( $nodes, 'WebPage' ) );
		$this->assertSame( 'http://example.test/guides/a-post/', $article['mainEntityOfPage']['@id'] );
		$this->assertArrayNotHasKey( 'isPartOf', $article );
	}

	public function test_publisher_is_referenced_only_when_there_is_an_organization() {
		$without = lcp_yeast_seo_build_auto_graph( $this->context() );
		$with    = lcp_yeast_seo_build_auto_graph( $this->context( array( 'org_id' => 'http://example.test/#organization' ) ) );

		$this->assertArrayNotHasKey( 'publisher', $this->node( $without, 'Article' ) );
		$this->assertSame( 'http://example.test/#organization', $this->node( $with, 'Article' )['publisher']['@id'] );
		$this->assertSame( 'http://example.test/#organization', $this->node( $with, 'WebSite' )['publisher']['@id'] );
	}

	public function test_front_page_gets_a_website_and_a_collection_page_but_no_breadcrumbs() {
		$ctx         = $this->context( array( 'is_front' => true ) );
		$ctx['page'] = array(
			'url'          => 'http://example.test/',
			'name'         => 'Example',
			'description'  => 'Just a tagline',
			'kind'         => 'CollectionPage',
			'article_type' => '',
		);

		$nodes = lcp_yeast_seo_build_auto_graph( $ctx );

		$this->assertSame( array( 'WebSite', 'CollectionPage' ), $this->types( $nodes ) );
	}

	public function test_no_page_means_just_the_website() {
		$this->assertSame( array( 'WebSite' ), $this->types( lcp_yeast_seo_build_auto_graph( $this->context( array( 'page' => null ) ) ) ) );
	}

	private function listing_context( array $page = array(), array $ctx = array() ) {
		return $this->context(
			$ctx + array(
				'page' => $page + array(
					'url'          => 'http://example.test/guides/',
					'name'         => 'Guides',
					'description'  => '',
					'kind'         => 'CollectionPage',
					'article_type' => '',
					'breadcrumbs'  => array(),
					'items'        => array(
						array( 'First guide', 'http://example.test/guides/first/' ),
						array( 'Second guide', 'http://example.test/guides/second/' ),
					),
					'items_start'  => 0,
				),
			)
		);
	}

	public function test_a_listing_page_carries_an_item_list_of_its_posts() {
		$webpage = $this->node( lcp_yeast_seo_build_auto_graph( $this->listing_context() ), 'CollectionPage' );
		$list    = $webpage['mainEntity'];

		$this->assertSame( 'ItemList', $list['@type'] );
		$this->assertSame( 'http://example.test/guides/#postlist', $list['@id'] );
		$this->assertSame( 2, $list['numberOfItems'] );
		$this->assertSame( array( 1, 2 ), array_column( $list['itemListElement'], 'position' ) );
		$this->assertSame( 'http://example.test/guides/second/', $list['itemListElement'][1]['url'] );
		$this->assertSame( 'First guide', $list['itemListElement'][0]['name'] );
	}

	public function test_item_positions_continue_on_later_pages() {
		$list = $this->node( lcp_yeast_seo_build_auto_graph( $this->listing_context( array( 'items_start' => 10 ) ) ), 'CollectionPage' )['mainEntity'];

		$this->assertSame( array( 11, 12 ), array_column( $list['itemListElement'], 'position' ) );
		$this->assertSame( 2, $list['numberOfItems'], 'counts the items on this page, not the whole archive' );
	}

	public function test_an_empty_listing_has_no_item_list() {
		$webpage = $this->node( lcp_yeast_seo_build_auto_graph( $this->listing_context( array( 'items' => array() ) ) ), 'CollectionPage' );

		$this->assertArrayNotHasKey( 'mainEntity', $webpage );
	}

	public function test_a_listing_page_is_about_the_organization_when_there_is_one() {
		$with    = $this->node( lcp_yeast_seo_build_auto_graph( $this->listing_context( array(), array( 'org_id' => 'http://example.test/#organization' ) ) ), 'CollectionPage' );
		$without = $this->node( lcp_yeast_seo_build_auto_graph( $this->listing_context() ), 'CollectionPage' );

		$this->assertSame( 'http://example.test/#organization', $with['about']['@id'] );
		$this->assertArrayNotHasKey( 'about', $without );
	}

	public function test_ordinary_pages_never_get_an_item_list_or_about() {
		$webpage = $this->node( lcp_yeast_seo_build_auto_graph( $this->context( array( 'org_id' => 'http://example.test/#organization' ) ) ), 'WebPage' );

		$this->assertArrayNotHasKey( 'mainEntity', $webpage );
		$this->assertArrayNotHasKey( 'about', $webpage );
	}

	public function test_a_custom_collection_page_means_no_generated_list() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->listing_context( array(), array( 'declared_types' => array( 'CollectionPage' ) ) ) );

		$this->assertNull( $this->node( $nodes, 'CollectionPage' ) );
	}

	public function test_author_is_a_person_unless_told_otherwise() {
		$article = $this->node( lcp_yeast_seo_build_auto_graph( $this->context() ), 'Article' );

		$this->assertSame( 'Person', $article['author']['@type'] );
		$this->assertSame( 'Sam', $article['author']['name'] );
	}

	public function test_author_can_be_the_organization() {
		$ctx                       = $this->context();
		$ctx['page']['author']     = array(
			'type' => 'Organization',
			'name' => 'Example',
			'url'  => 'http://example.test/',
		);

		$author = $this->node( lcp_yeast_seo_build_auto_graph( $ctx ), 'Article' )['author'];

		$this->assertSame( 'Organization', $author['@type'] );
		$this->assertSame( 'Example', $author['name'] );
		$this->assertSame( 'http://example.test/', $author['url'] );
	}

	public function test_no_author_means_no_author_property() {
		$ctx                   = $this->context();
		$ctx['page']['author'] = null;

		$this->assertArrayNotHasKey( 'author', $this->node( lcp_yeast_seo_build_auto_graph( $ctx ), 'Article' ) );
	}

	public function test_a_switched_off_kind_is_never_generated() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context( array( 'disabled_kinds' => array( 'breadcrumb', 'website' ) ) ) );

		$this->assertNull( $this->node( $nodes, 'BreadcrumbList' ) );
		$this->assertNull( $this->node( $nodes, 'WebSite' ) );
		$this->assertNotNull( $this->node( $nodes, 'WebPage' ) );
		$this->assertArrayNotHasKey( 'breadcrumb', $this->node( $nodes, 'WebPage' ), 'the WebPage must not point at a breadcrumb that is not there' );
	}

	public function test_switching_off_the_article_leaves_the_page() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context( array( 'disabled_kinds' => array( 'article' ) ) ) );

		$this->assertNull( $this->node( $nodes, 'Article' ) );
		$this->assertNotNull( $this->node( $nodes, 'WebPage' ) );
	}

	public function test_switching_off_the_page_leaves_the_article_pointing_at_the_url() {
		$nodes = lcp_yeast_seo_build_auto_graph( $this->context( array( 'disabled_kinds' => array( 'webpage' ) ) ) );

		$this->assertNull( $this->node( $nodes, 'WebPage' ) );
		$this->assertSame( 'http://example.test/guides/a-post/', $this->node( $nodes, 'Article' )['mainEntityOfPage']['@id'] );
	}

	public function test_switching_off_the_post_list_keeps_the_collection_page() {
		$nodes   = lcp_yeast_seo_build_auto_graph( $this->listing_context( array(), array( 'disabled_kinds' => array( 'itemlist' ) ) ) );
		$webpage = $this->node( $nodes, 'CollectionPage' );

		$this->assertNotNull( $webpage );
		$this->assertArrayNotHasKey( 'mainEntity', $webpage );
	}

	public function test_everything_is_on_until_switched_off() {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( array(), lcp_yeast_seo_auto_schema_disabled_kinds() );

		Functions\when( 'get_option' )->justReturn( array( 'auto_schema_kinds' => array( 'breadcrumb' => '0', 'website' => '1' ) ) );
		$this->assertSame( array( 'breadcrumb' ), lcp_yeast_seo_auto_schema_disabled_kinds() );
	}

	public function test_kind_switches_are_sanitized_to_zero_or_one() {
		$out = lcp_yeast_seo_sanitize_auto_schema_kinds( array( 'website' => '1', 'article' => 'yes', 'bogus' => '1' ) );

		$this->assertSame( '1', $out['website'] );
		$this->assertSame( '1', $out['article'] );
		$this->assertSame( '0', $out['breadcrumb'], 'a missing box means switched off' );
		$this->assertArrayNotHasKey( 'bogus', $out );
	}

	public function test_admin_list_types_for_a_post() {
		$this->assertSame(
			array( 'WebSite', 'WebPage', 'BlogPosting', 'BreadcrumbList' ),
			lcp_yeast_seo_auto_schema_kind_types( 'BlogPosting', false, false, array(), array() )
		);
	}

	public function test_admin_list_types_for_a_page_front_page_and_posts_page() {
		$this->assertSame( array( 'WebSite', 'WebPage', 'BreadcrumbList' ), lcp_yeast_seo_auto_schema_kind_types( '', false, false, array(), array() ) );
		$this->assertSame( array( 'WebSite', 'WebPage' ), lcp_yeast_seo_auto_schema_kind_types( '', true, false, array(), array() ), 'a static front page is a plain page with no breadcrumb trail' );
		$this->assertSame( array( 'WebSite', 'CollectionPage', 'BreadcrumbList' ), lcp_yeast_seo_auto_schema_kind_types( 'BlogPosting', false, true, array(), array() ), 'the posts page is a listing, not an article' );
	}

	public function test_admin_list_types_respect_switches_and_the_pages_own_schema() {
		$this->assertSame(
			array( 'WebSite', 'WebPage' ),
			lcp_yeast_seo_auto_schema_kind_types( 'BlogPosting', false, false, array( 'breadcrumb' ), array( 'BlogPosting' ) )
		);
		$this->assertSame( array(), lcp_yeast_seo_auto_schema_kind_types( '', false, false, array( 'website', 'webpage', 'breadcrumb' ), array() ) );
	}

	public function test_output_encodes_as_valid_json() {
		$json = json_encode( lcp_yeast_seo_build_auto_graph( $this->context() ), JSON_UNESCAPED_SLASHES );

		$this->assertNotFalse( $json );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}

	public function test_the_webpage_only_points_at_a_website_the_plugin_prints() {
		$mine   = lcp_yeast_seo_build_auto_graph( $this->context() );
		$theirs = lcp_yeast_seo_build_auto_graph( $this->context( array( 'declared_types' => array( 'WebSite' ) ) ) );
		$off    = lcp_yeast_seo_build_auto_graph( $this->context( array( 'disabled_kinds' => array( 'website' ) ) ) );
		$page   = static function ( array $nodes ) {
			return array_values( array_filter( $nodes, static function ( $node ) {
				return 'WebPage' === $node['@type'];
			} ) )[0];
		};

		$this->assertSame( 'http://example.test/#website', $page( $mine )['isPartOf']['@id'] );
		$this->assertArrayNotHasKey( 'isPartOf', $page( $theirs ), 'a theme-supplied WebSite has no @id to point at' );
		$this->assertArrayNotHasKey( 'isPartOf', $page( $off ) );
	}
}
