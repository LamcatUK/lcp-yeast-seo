<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class BulkActionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	public function test_only_our_actions_map_to_a_robots_value() {
		$this->assertSame( 'noindex', lcp_yeast_seo_bulk_robots_value( 'lcp_yeast_seo_noindex' ) );
		$this->assertSame( 'index', lcp_yeast_seo_bulk_robots_value( 'lcp_yeast_seo_index' ) );
		$this->assertSame( '', lcp_yeast_seo_bulk_robots_value( 'trash' ) );
	}

	public function test_both_actions_are_added_to_the_menu_without_losing_existing_ones() {
		$actions = lcp_yeast_seo_add_bulk_actions( array( 'trash' => 'Move to Trash' ) );

		$this->assertSame( array( 'trash', 'lcp_yeast_seo_noindex', 'lcp_yeast_seo_index' ), array_keys( $actions ) );
	}

	public function test_other_bulk_actions_are_left_alone() {
		Functions\expect( 'update_post_meta' )->never();

		$this->assertSame( 'http://x.test/edit.php', lcp_yeast_seo_handle_bulk_action( 'http://x.test/edit.php', 'trash', array( 1, 2 ) ) );
	}

	public function test_noindex_is_written_only_where_it_changes_and_only_where_allowed() {
		$meta = array( 1 => 'index', 2 => 'noindex', 3 => '', 4 => 'index' );

		Functions\when( 'current_user_can' )->alias(
			static function ( $cap, $id ) {
				return 4 !== $id;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) use ( $meta ) {
				return $meta[ $id ];
			}
		);
		Functions\expect( 'update_post_meta' )->once()->with( 1, LCP_YEAST_SEO_ROBOTS_META_KEY, 'noindex' );
		Functions\expect( 'update_post_meta' )->once()->with( 3, LCP_YEAST_SEO_ROBOTS_META_KEY, 'noindex' );

		$url = lcp_yeast_seo_handle_bulk_action( 'http://x.test/edit.php', 'lcp_yeast_seo_noindex', array( '1', '2', '3', '4', 0, 'abc' ) );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'noindex', $query['lcp_yeast_seo_bulk'] );
		$this->assertSame( '2', $query['lcp_yeast_seo_changed'] );
		$this->assertSame( '1', $query['lcp_yeast_seo_same'] );
		$this->assertSame( '1', $query['lcp_yeast_seo_denied'] );
	}

	public function test_index_treats_a_missing_value_as_already_index() {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'update_post_meta' )->never();

		$url = lcp_yeast_seo_handle_bulk_action( 'http://x.test/edit.php', 'lcp_yeast_seo_index', array( 5 ) );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '0', $query['lcp_yeast_seo_changed'] );
		$this->assertSame( '1', $query['lcp_yeast_seo_same'] );
	}

	public function test_the_notice_reports_what_happened() {
		$this->assertSame( array( 'success', '3 items set to noindex.' ), lcp_yeast_seo_bulk_notice( 'noindex', 3, 0, 0 ) );
		$this->assertSame( array( 'success', '1 item set to index. 2 items were already index.' ), lcp_yeast_seo_bulk_notice( 'index', 1, 2, 0 ) );
		$this->assertSame( array( 'success', '1 item was already noindex.' ), lcp_yeast_seo_bulk_notice( 'noindex', 0, 1, 0 ) );
		$this->assertSame( 'warning', lcp_yeast_seo_bulk_notice( 'noindex', 2, 0, 1 )[0] );
		$this->assertStringContainsString( '1 item skipped because you can not edit it.', lcp_yeast_seo_bulk_notice( 'noindex', 2, 0, 1 )[1] );
		$this->assertSame( array( 'warning', 'No items were selected.' ), lcp_yeast_seo_bulk_notice( 'noindex', 0, 0, 0 ) );
	}

	public function test_setting_to_index_on_a_hidden_site_says_it_is_still_noindex() {
		$this->assertStringContainsString( 'discourage search engines', lcp_yeast_seo_bulk_notice( 'index', 2, 0, 0, true )[1] );
		$this->assertStringNotContainsString( 'discourage', lcp_yeast_seo_bulk_notice( 'noindex', 2, 0, 0, true )[1] );
		$this->assertStringNotContainsString( 'discourage', lcp_yeast_seo_bulk_notice( 'index', 2, 0, 0, false )[1] );
	}
}
