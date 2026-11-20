<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class IndexNowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	private function facts( array $override = array() ) {
		return $override + array(
			'enabled'       => true,
			'host'          => 'tidysolutions.im',
			'blog_public'   => true,
			'post_type'     => 'post',
			'sitemap_types' => array( 'post', 'page' ),
			'status'        => 'publish',
			'password'      => false,
		);
	}

	/* Who may send */

	public function test_a_public_published_post_in_the_sitemap_is_eligible() {
		$this->assertTrue( lcp_yeast_seo_indexnow_eligible( $this->facts() ) );
	}

	public function test_nothing_is_sent_unless_switched_on() {
		$this->assertFalse( lcp_yeast_seo_indexnow_eligible( $this->facts( array( 'enabled' => false ) ) ) );
	}

	public function test_nothing_is_sent_from_a_site_that_discourages_search_engines() {
		$this->assertFalse( lcp_yeast_seo_indexnow_eligible( $this->facts( array( 'blog_public' => false ) ) ) );
	}

	public function test_nothing_is_sent_from_local_or_test_hosts() {
		foreach ( array( 'tidynew.local', 'localhost', '127.0.0.1', 'site.test', 'x.localhost', 'a.internal', '' ) as $host ) {
			$this->assertTrue( lcp_yeast_seo_indexnow_host_is_local( $host ), $host );
			$this->assertFalse( lcp_yeast_seo_indexnow_eligible( $this->facts( array( 'host' => $host ) ) ), $host );
		}

		$this->assertFalse( lcp_yeast_seo_indexnow_host_is_local( 'tidysolutions.im' ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_host_is_local( 'localtest.com' ), 'only a real .local or .test ending counts' );
	}

	public function test_drafts_password_protected_and_unlisted_types_are_skipped() {
		$this->assertFalse( lcp_yeast_seo_indexnow_eligible( $this->facts( array( 'status' => 'draft' ) ) ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_eligible( $this->facts( array( 'password' => true ) ) ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_eligible( $this->facts( array( 'post_type' => 'attachment' ) ) ) );
	}

	/* Only real changes */

	public function test_the_fingerprint_changes_with_the_text_and_the_seo_settings() {
		$base = array( 'title' => 'A', 'content' => 'B', 'seo_title' => 'T', 'robots' => 'index' );

		$this->assertSame( lcp_yeast_seo_indexnow_fingerprint( $base ), lcp_yeast_seo_indexnow_fingerprint( $base ) );
		$this->assertNotSame( lcp_yeast_seo_indexnow_fingerprint( $base ), lcp_yeast_seo_indexnow_fingerprint( array( 'content' => 'B2' ) + $base ) );
		$this->assertNotSame( lcp_yeast_seo_indexnow_fingerprint( $base ), lcp_yeast_seo_indexnow_fingerprint( array( 'seo_title' => 'T2' ) + $base ) );
	}

	public function test_the_fingerprint_says_whether_the_page_is_noindex() {
		$this->assertStringStartsWith( 'i:', lcp_yeast_seo_indexnow_fingerprint( array( 'robots' => 'index' ) ) );
		$this->assertStringStartsWith( 'n:', lcp_yeast_seo_indexnow_fingerprint( array( 'robots' => 'noindex' ) ) );
	}

	public function test_an_unchanged_page_is_not_sent_again() {
		$this->assertFalse( lcp_yeast_seo_indexnow_changed( 'i:abc', 'i:abc' ) );
	}

	public function test_a_new_or_edited_page_is_sent() {
		$this->assertTrue( lcp_yeast_seo_indexnow_changed( '', 'i:abc' ) );
		$this->assertTrue( lcp_yeast_seo_indexnow_changed( 'i:abc', 'i:def' ) );
	}

	public function test_a_page_that_turns_noindex_is_sent_once_but_not_while_it_stays_noindex() {
		$this->assertTrue( lcp_yeast_seo_indexnow_changed( 'i:abc', 'n:def' ), 'engines should look again and drop it' );
		$this->assertFalse( lcp_yeast_seo_indexnow_changed( 'n:abc', 'n:def' ), 'edits to a page that is not indexed do not matter' );
		$this->assertTrue( lcp_yeast_seo_indexnow_changed( 'n:abc', 'i:abc' ), 'back in the index' );
	}

	/* The waiting list */

	public function test_a_url_is_added_once() {
		$state = lcp_yeast_seo_indexnow_state_add( array(), array( 'https://a.test/x/', 'https://a.test/x/', 'https://a.test/y/' ), 1000 );
		$state = lcp_yeast_seo_indexnow_state_add( $state, array( 'https://a.test/x/' ), 1010 );

		$this->assertSame( array( 'https://a.test/x/', 'https://a.test/y/' ), array_keys( $state['pending'] ) );
		$this->assertSame( 1000, $state['pending']['https://a.test/x/']['time'], 'a later edit does not push the send back' );
	}

	public function test_nothing_is_taken_before_the_delay_has_passed() {
		$state = lcp_yeast_seo_indexnow_state_add( array(), array( 'https://a.test/x/' ), 1000 );

		list( , $batch ) = lcp_yeast_seo_indexnow_state_take( $state, 1000 + LCP_YEAST_SEO_INDEXNOW_DELAY - 1 );
		$this->assertSame( array(), $batch );

		list( $after, $batch ) = lcp_yeast_seo_indexnow_state_take( $state, 1000 + LCP_YEAST_SEO_INDEXNOW_DELAY );
		$this->assertSame( array( 'https://a.test/x/' ), $batch );
		$this->assertSame( array(), $after['pending'] );
		$this->assertArrayHasKey( 'https://a.test/x/', $after['sent'] );
	}

	public function test_a_url_sent_a_moment_ago_is_not_queued_again_until_the_window_passes() {
		$state = lcp_yeast_seo_indexnow_state_add( array(), array( 'https://a.test/x/' ), 1000 );

		list( $state ) = lcp_yeast_seo_indexnow_state_take( $state, 1100 );

		$again = lcp_yeast_seo_indexnow_state_add( $state, array( 'https://a.test/x/' ), 1200 );
		$this->assertSame( array(), $again['pending'] );

		$later = lcp_yeast_seo_indexnow_state_add( $state, array( 'https://a.test/x/' ), 1100 + LCP_YEAST_SEO_INDEXNOW_RESEND_WINDOW + 1 );
		$this->assertArrayHasKey( 'https://a.test/x/', $later['pending'] );
	}

	public function test_a_failed_send_is_tried_again_a_little_later_and_then_given_up_on() {
		$state = lcp_yeast_seo_indexnow_state_add( array(), array( 'https://a.test/x/' ), 1000 );

		list( $state, $batch, $tries ) = lcp_yeast_seo_indexnow_state_take( $state, 1100 );
		$state                         = lcp_yeast_seo_indexnow_state_retry( $state, $batch, $tries, 1100 );

		$this->assertSame( 1, $state['pending']['https://a.test/x/']['tries'] );
		$this->assertSame( 1400, $state['pending']['https://a.test/x/']['time'] );
		$this->assertArrayNotHasKey( 'https://a.test/x/', $state['sent'], 'a failed send does not block the retry' );

		// Each later attempt takes it off the list first, as the scheduler does; after the last allowed try it is dropped.
		for ( $attempt = 2; $attempt <= LCP_YEAST_SEO_INDEXNOW_MAX_TRIES; $attempt++ ) {
			$this->assertArrayHasKey( 'https://a.test/x/', $state['pending'] );

			list( $state, $batch, $tries ) = lcp_yeast_seo_indexnow_state_take( $state, 100000 * $attempt );
			$state                         = lcp_yeast_seo_indexnow_state_retry( $state, $batch, $tries, 100000 * $attempt );
		}

		$this->assertArrayNotHasKey( 'https://a.test/x/', $state['pending'] );
	}

	public function test_a_batch_is_capped_at_the_protocol_limit() {
		$urls  = array();

		for ( $i = 0; $i < LCP_YEAST_SEO_INDEXNOW_MAX_URLS + 5; $i++ ) {
			$urls[] = 'https://a.test/p' . $i . '/';
		}

		list( $state, $batch ) = lcp_yeast_seo_indexnow_state_take( lcp_yeast_seo_indexnow_state_add( array(), $urls, 0 ), 1000 );

		$this->assertCount( LCP_YEAST_SEO_INDEXNOW_MAX_URLS, $batch );
		$this->assertCount( 5, $state['pending'] );
	}

	/* What goes over the wire */

	public function test_the_payload_names_the_site_the_key_and_only_this_sites_addresses() {
		$payload = lcp_yeast_seo_indexnow_payload( 'tidysolutions.im', 'abc12345', 'https://tidysolutions.im/abc12345.txt', array( 'https://tidysolutions.im/a/', 'https://evil.test/b/', 'https://TidySolutions.im/c/' ) );

		$this->assertSame( 'tidysolutions.im', $payload['host'] );
		$this->assertSame( 'abc12345', $payload['key'] );
		$this->assertSame( 'https://tidysolutions.im/abc12345.txt', $payload['keyLocation'] );
		$this->assertSame( array( 'https://tidysolutions.im/a/', 'https://TidySolutions.im/c/' ), $payload['urlList'] );
	}

	public function test_keys_must_be_8_to_128_letters_numbers_and_dashes() {
		$this->assertTrue( lcp_yeast_seo_indexnow_key_is_valid( 'abcdef0123456789abcdef0123456789' ) );
		$this->assertTrue( lcp_yeast_seo_indexnow_key_is_valid( 'abc-1234' ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_key_is_valid( 'short' ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_key_is_valid( 'has space in it' ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_key_is_valid( str_repeat( 'a', 129 ) ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_key_is_valid( '' ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_key_is_valid( null ) );
	}

	public function test_responses_are_read_as_done_retry_or_stop() {
		$this->assertSame( 'ok', lcp_yeast_seo_indexnow_outcome( 200 ) );
		$this->assertSame( 'ok', lcp_yeast_seo_indexnow_outcome( 202 ) );
		$this->assertSame( 'retry', lcp_yeast_seo_indexnow_outcome( 0 ) );
		$this->assertSame( 'retry', lcp_yeast_seo_indexnow_outcome( 429 ) );
		$this->assertSame( 'retry', lcp_yeast_seo_indexnow_outcome( 503 ) );
		$this->assertSame( 'stop', lcp_yeast_seo_indexnow_outcome( 403 ), 'a wrong key will not fix itself by retrying' );
		$this->assertSame( 'stop', lcp_yeast_seo_indexnow_outcome( 422 ) );
		$this->assertSame( 'stop', lcp_yeast_seo_indexnow_outcome( 400 ) );
	}

	public function test_every_response_has_a_plain_description() {
		foreach ( array( 0, 200, 202, 400, 403, 422, 429, 500, 418 ) as $code ) {
			$this->assertNotSame( '', lcp_yeast_seo_indexnow_describe( $code ), (string) $code );
		}
	}

	/* Waiting out a busy server */

	public function test_the_retry_wait_follows_retry_after_within_limits() {
		$this->assertSame( LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY, lcp_yeast_seo_indexnow_retry_delay( '' ), 'no header: the default' );
		$this->assertSame( LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY, lcp_yeast_seo_indexnow_retry_delay( '5' ), 'never hammer sooner than the default' );
		$this->assertSame( 900, lcp_yeast_seo_indexnow_retry_delay( '900' ) );
		$this->assertSame( 3600, lcp_yeast_seo_indexnow_retry_delay( '99999' ), 'and never wait more than an hour' );
		$this->assertSame( LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY, lcp_yeast_seo_indexnow_retry_delay( 'Wed, 21 Oct 2026 07:28:00 GMT' ), 'a date is not understood, so the default' );
	}

	public function test_a_retry_waits_as_long_as_asked() {
		$state = lcp_yeast_seo_indexnow_state_retry( array(), array( 'https://a.test/x/' ), array(), 1000, 900 );

		$this->assertSame( 1900, $state['pending']['https://a.test/x/']['time'] );
	}

	public function test_the_next_send_is_scheduled_for_when_the_earliest_url_is_due() {
		$state = array( 'pending' => array( 'https://a.test/x/' => array( 'time' => 5000, 'tries' => 1 ), 'https://a.test/y/' => array( 'time' => 4000, 'tries' => 0 ) ) );

		$this->assertSame( 4000 + LCP_YEAST_SEO_INDEXNOW_DELAY + 15, lcp_yeast_seo_indexnow_next_run( $state, 1000 ) );
		$this->assertSame( 9030, lcp_yeast_seo_indexnow_next_run( $state, 9000 ), 'already overdue: soon, never in the past' );
		$this->assertNull( lcp_yeast_seo_indexnow_next_run( array( 'pending' => array() ), 1000 ) );
	}

	/* Pausing after "too many requests" */

	public function test_only_too_many_requests_starts_a_pause() {
		$this->assertSame( 1000 + LCP_YEAST_SEO_INDEXNOW_PAUSE, lcp_yeast_seo_indexnow_pause_until( 429, 300, 1000 ) );
		$this->assertSame( 0, lcp_yeast_seo_indexnow_pause_until( 200, 300, 1000 ) );
		$this->assertSame( 0, lcp_yeast_seo_indexnow_pause_until( 503, 300, 1000 ), 'a server error is retried, not paused for an hour' );
		$this->assertSame( 0, lcp_yeast_seo_indexnow_pause_until( 403, 300, 1000 ) );
		$this->assertSame( 0, lcp_yeast_seo_indexnow_pause_until( 0, 300, 1000 ) );
	}

	public function test_the_pause_is_an_hour_or_what_the_server_asked_if_longer() {
		$this->assertSame( 1000 + 3600, lcp_yeast_seo_indexnow_pause_until( 429, 900, 1000 ) );
		$this->assertSame( 1000 + 7200, lcp_yeast_seo_indexnow_pause_until( 429, 7200, 1000 ) );
	}

	public function test_a_pause_is_over_once_its_time_has_passed() {
		$this->assertTrue( lcp_yeast_seo_indexnow_is_paused( 5000, 4999 ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_is_paused( 5000, 5000 ) );
		$this->assertFalse( lcp_yeast_seo_indexnow_is_paused( 0, 1 ) );
	}

	public function test_the_next_send_waits_for_the_pause_to_end() {
		$state = array( 'pending' => array( 'https://a.test/x/' => array( 'time' => 1000, 'tries' => 0 ) ) );

		$this->assertSame( 2000 + 30, lcp_yeast_seo_indexnow_next_run( $state, 2000, 0 ) );
		$this->assertSame( 9000 + 15, lcp_yeast_seo_indexnow_next_run( $state, 2000, 9000 ), 'not before the pause ends' );
		$this->assertSame( 2030, lcp_yeast_seo_indexnow_next_run( $state, 2000, 1500 ), 'a pause that has already ended changes nothing' );
	}
}
