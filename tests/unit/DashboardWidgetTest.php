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

	/* IndexNow line */

	private function ping( array $override = array() ) {
		return lcp_yeast_seo_widget_indexnow_line( $override + array( 'enabled' => true, 'available' => true, 'paused_until' => '', 'queued' => 0, 'last' => null ) );
	}

	public function test_no_ping_line_when_switched_off() {
		$this->assertSame( '', $this->ping( array( 'enabled' => false ) ) );
	}

	public function test_it_says_when_nothing_can_be_sent_from_the_site() {
		$this->assertStringContainsString( 'nothing is sent from this site', $this->ping( array( 'available' => false ) ) );
	}

	public function test_it_says_when_nothing_has_been_sent_yet() {
		$this->assertSame( 'On. Nothing has been sent yet.', $this->ping() );
	}

	public function test_a_successful_ping_is_reported_as_accepted() {
		$this->assertSame( 'Last ping 14 minutes ago: 22 pages accepted.', $this->ping( array( 'last' => array( 'ago' => '14 minutes', 'count' => 22, 'code' => 200 ) ) ) );
		$this->assertStringContainsString( 'accepted', $this->ping( array( 'last' => array( 'ago' => '1 min', 'count' => 1, 'code' => 202 ) ) ) );
	}

	public function test_a_refused_ping_names_the_response() {
		$line = $this->ping( array( 'last' => array( 'ago' => '2 minutes', 'count' => 22, 'code' => 429 ) ) );

		$this->assertStringContainsString( 'not accepted (response 429)', $line );
		$this->assertStringContainsString( 'not accepted (no answer)', $this->ping( array( 'last' => array( 'ago' => '2 minutes', 'count' => 22, 'code' => 0 ) ) ) );
	}

	public function test_a_pause_and_a_queue_are_both_shown() {
		$line = $this->ping( array( 'paused_until' => '15:40', 'queued' => 22, 'last' => array( 'ago' => '2 minutes', 'count' => 22, 'code' => 429 ) ) );

		$this->assertStringContainsString( 'Paused until 15:40.', $line );
		$this->assertStringContainsString( '22 pages waiting to be sent.', $line );
	}
}
