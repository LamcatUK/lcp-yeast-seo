<?php
/**
 * Smoke test: archive canonicals must not echo tracking parameters.
 *
 *   LCP_SMOKE_URL=https://example.com LCP_SMOKE_ARCHIVE=/guides/ composer test:smoke
 *
 * LCP_SMOKE_ARCHIVE is the path of an archive or posts page. Skipped without it.
 */

namespace Lcp\YeastSeo\Tests;

use PHPUnit\Framework\TestCase;

class CanonicalSmokeTest extends TestCase {

	private function canonical( $url ) {
		$html = @file_get_contents( $url, false, stream_context_create( array( 'http' => array( 'timeout' => 20 ) ) ) );

		$this->assertNotFalse( $html, "Could not fetch $url" );
		$this->assertSame( 1, preg_match( '#<link rel="canonical" href="([^"]*)"#', $html, $match ), "$url has no canonical tag" );

		return html_entity_decode( $match[1] );
	}

	public function test_archive_canonical_drops_tracking_parameters() {
		$base    = getenv( 'LCP_SMOKE_URL' );
		$archive = getenv( 'LCP_SMOKE_ARCHIVE' );

		if ( ! $base || ! $archive ) {
			$this->markTestSkipped( 'Set LCP_SMOKE_URL and LCP_SMOKE_ARCHIVE (e.g. /guides/) to run this test.' );
		}

		$url       = rtrim( $base, '/' ) . '/' . ltrim( $archive, '/' );
		$canonical = $this->canonical( $url . '?utm_source=smoke&fbclid=smoke&v=' . time() );

		$this->assertStringNotContainsString( '?', $canonical, 'The canonical must not carry a query string' );
		$this->assertSame( $canonical, $this->canonical( $url ), 'Tracked and clean URLs must share one canonical' );
	}
}
