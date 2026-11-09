<?php
/**
 * Smoke test for a running site's sitemaps. Run it after every deploy:
 *
 *   LCP_SMOKE_URL=https://example.com composer test:smoke
 *
 * Skipped when LCP_SMOKE_URL isn't set, so the unit suite never needs a site.
 */

namespace Lcp\YeastSeo\Tests;

use PHPUnit\Framework\TestCase;

class SitemapSmokeTest extends TestCase {

	private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	private const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';

	private $base;

	protected function setUp(): void {
		$base = getenv( 'LCP_SMOKE_URL' );

		if ( ! $base ) {
			$this->markTestSkipped( 'Set LCP_SMOKE_URL to a site URL to run the smoke tests.' );
		}

		$this->base = rtrim( $base, '/' );
	}

	/**
	 * Fetches a URL without following redirects.
	 *
	 * @return array{status:int,headers:array,body:string}
	 */
	private function fetch( $url ) {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'follow_location' => 0,
					'ignore_errors'   => true,
					'timeout'         => 20,
					'header'          => "User-Agent: lcp-yeast-seo-smoke\r\n",
				),
			)
		);

		$stream = @fopen( $url, 'r', false, $context );

		$this->assertNotFalse( $stream, "No response from $url" );

		$headers = stream_get_meta_data( $stream )['wrapper_data'];
		$body    = (string) stream_get_contents( $stream );
		fclose( $stream );

		$this->assertNotEmpty( $headers, "No response from $url" );

		preg_match( '#^HTTP/\S+ (\d{3})#', $headers[0], $match );

		$parsed = array();
		foreach ( $headers as $line ) {
			if ( false !== strpos( $line, ':' ) ) {
				list( $name, $value )             = explode( ':', $line, 2 );
				$parsed[ strtolower( trim( $name ) ) ] = trim( $value );
			}
		}

		return array(
			'status'  => (int) $match[1],
			'headers' => $parsed,
			'body'    => $body,
		);
	}

	private function load_xml( $body, $url ) {
		$dom = new \DOMDocument();

		$this->assertTrue( @$dom->loadXML( $body ), "$url is not well-formed XML" );

		return $dom;
	}

	private function child_elements( \DOMElement $parent ) {
		$children = array();

		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	private function index() {
		$response = $this->fetch( $this->base . '/sitemap_index.xml' );

		$this->assertSame( 200, $response['status'], 'sitemap_index.xml must answer directly, not redirect' );

		return $response;
	}

	public function test_index_is_served_directly_as_xml() {
		$response = $this->index();

		$this->assertStringContainsString( 'xml', $response['headers']['content-type'] ?? '' );
	}

	public function test_sitemap_xml_alias_works() {
		$response = $this->fetch( $this->base . '/sitemap.xml' );

		if ( 200 === $response['status'] ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		// A server or host rule may send the alias to the index; that is fine.
		$this->assertContains( $response['status'], array( 301, 302, 308 ) );
		$this->assertStringEndsWith( '/sitemap_index.xml', $response['headers']['location'] ?? '' );
	}

	public function test_trailing_slash_redirects_to_the_slashless_url() {
		$response = $this->fetch( $this->base . '/sitemap_index.xml/' );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/sitemap_index.xml', $response['headers']['location'] ?? '' );
	}

	public function test_index_only_contains_tags_search_engines_accept() {
		$dom  = $this->load_xml( $this->index()['body'], 'sitemap_index.xml' );
		$root = $dom->documentElement;

		$this->assertSame( 'sitemapindex', $root->localName );
		$this->assertNotSame( 0, $root->childNodes->length, 'The index lists no sitemaps' );

		foreach ( $this->child_elements( $root ) as $sitemap ) {
			$this->assertSame( 'sitemap', $sitemap->localName );

			foreach ( $this->child_elements( $sitemap ) as $child ) {
				$this->assertSame( self::NS_SITEMAP, $child->namespaceURI, "<{$child->nodeName}> is not a standard sitemap tag" );
				$this->assertContains( $child->localName, array( 'loc', 'lastmod' ), "<{$child->nodeName}> is not allowed in a sitemapindex entry" );
			}
		}
	}

	public function test_every_listed_sitemap_is_served_directly_and_valid() {
		$dom  = $this->load_xml( $this->index()['body'], 'sitemap_index.xml' );
		$locs = $dom->getElementsByTagNameNS( self::NS_SITEMAP, 'loc' );

		$this->assertGreaterThan( 0, $locs->length );

		foreach ( $locs as $loc ) {
			$url      = trim( $loc->textContent );
			$response = $this->fetch( $url );

			$this->assertSame( 200, $response['status'], "$url must answer directly, not redirect" );
			$this->assertStringNotContainsString( '.xml/', $url, 'Sitemap URLs must not have a trailing slash' );

			$urlset = $this->load_xml( $response['body'], $url )->documentElement;

			$this->assertSame( 'urlset', $urlset->localName, $url );

			foreach ( $this->child_elements( $urlset ) as $entry ) {
				$this->assertSame( 'url', $entry->localName, $url );

				$names = array();

				foreach ( $this->child_elements( $entry ) as $child ) {
					$ok = self::NS_IMAGE === $child->namespaceURI || ( self::NS_SITEMAP === $child->namespaceURI && in_array( $child->localName, array( 'loc', 'lastmod', 'changefreq', 'priority' ), true ) );

					$this->assertTrue( $ok, "<{$child->nodeName}> is not allowed in a url entry ($url)" );
					$names[] = $child->localName;
				}

				$this->assertContains( 'loc', $names, "A <url> in $url has no <loc>" );
			}
		}
	}

	public function test_robots_txt_points_at_the_sitemap_index() {
		$robots = $this->fetch( $this->base . '/robots.txt' );

		$this->assertSame( 200, $robots['status'] );

		if ( false === stripos( $robots['body'], 'sitemap:' ) && preg_match( '/^Disallow:\s*\/\s*$/mi', $robots['body'] ) ) {
			$this->markTestSkipped( 'The site discourages search engines, so no Sitemap line is expected.' );
		}

		$this->assertStringContainsString( $this->base . '/sitemap_index.xml', $robots['body'] );
	}
}
