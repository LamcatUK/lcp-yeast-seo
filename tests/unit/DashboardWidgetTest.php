<?php
namespace Lcp\YeastSeo\Tests;

class DashboardWidgetTest extends TestCase {

	public function test_index_counts_treat_missing_settings_as_indexable() {
		$this->assertSame(
			array( 'indexable' => 2, 'noindex' => 2 ),
			lcp_yeast_seo_widget_index_counts( array( 1, 2, 3, 4 ), array( 1 => 'noindex', 2 => 'index', 3 => '', 4 => 'noindex' ) )
		);
		$this->assertSame( array( 'indexable' => 0, 'noindex' => 0 ), lcp_yeast_seo_widget_index_counts( array(), array() ) );
	}

	public function test_a_scan_is_stale_when_missing_or_over_a_week_old() {
		$now = 1000000000;

		$this->assertTrue( lcp_yeast_seo_widget_scan_is_stale( 0, $now ) );
		$this->assertTrue( lcp_yeast_seo_widget_scan_is_stale( $now - 8 * DAY_IN_SECONDS, $now ) );
		$this->assertFalse( lcp_yeast_seo_widget_scan_is_stale( $now - 6 * DAY_IN_SECONDS, $now ) );
	}

	public function test_top_problems_keeps_the_order_and_limit() {
		$problems = array();

		foreach ( range( 1, 6 ) as $n ) {
			$problems[] = array( 'code' => "c$n", 'level' => 'error', 'message' => "m$n", 'pages' => $n );
		}

		$top = lcp_yeast_seo_widget_top_problems( $problems, 4 );

		$this->assertSame( array( 'c1', 'c2', 'c3', 'c4' ), array_column( $top, 'code' ) );
		$this->assertCount( 1, lcp_yeast_seo_widget_top_problems( $problems, 0 ), 'never asks for fewer than one' );
	}
}
