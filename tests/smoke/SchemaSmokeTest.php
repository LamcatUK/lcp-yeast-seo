<?php
/**
 * Smoke test: no page may carry the same kind of schema entity twice, whatever
 * prints it (this plugin, the theme, another plugin).
 *
 *   LCP_SMOKE_URL=https://example.com composer test:smoke
 */

namespace Lcp\YeastSeo\Tests;

use PHPUnit\Framework\TestCase;

class SchemaSmokeTest extends TestCase {

	private const KINDS = array(
		'website'    => array( 'WebSite' ),
		'webpage'    => array( 'WebPage', 'CollectionPage', 'AboutPage', 'ContactPage', 'ProfilePage', 'ItemPage' ),
		'article'    => array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle' ),
		'breadcrumb' => array( 'BreadcrumbList' ),
	);

	private function get( $url ) {
		$html = @file_get_contents( $url, false, stream_context_create( array( 'http' => array( 'timeout' => 20 ) ) ) );

		$this->assertNotFalse( $html, "Could not fetch $url" );

		return $html;
	}

	private function entity_kinds( $html ) {
		$found = array();

		preg_match_all( '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts );

		foreach ( $scripts[1] as $json ) {
			$data = json_decode( trim( $json ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$entities = isset( $data['@graph'] ) ? $data['@graph'] : ( isset( $data['@type'] ) ? array( $data ) : $data );

			foreach ( $entities as $entity ) {
				if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
					continue;
				}

				foreach ( (array) $entity['@type'] as $type ) {
					foreach ( self::KINDS as $kind => $types ) {
						if ( in_array( $type, $types, true ) ) {
							$found[ $kind ][] = $type;
						}
					}
				}
			}
		}

		return $found;
	}

	public function test_no_sampled_page_has_duplicate_schema_entities() {
		$base = getenv( 'LCP_SMOKE_URL' );

		if ( ! $base ) {
			$this->markTestSkipped( 'Set LCP_SMOKE_URL to a site URL to run the smoke tests.' );
		}

		$base = rtrim( $base, '/' );
		$urls = array( $base . '/' );

		foreach ( array( 'page', 'post' ) as $type ) {
			if ( preg_match_all( '#<loc>(.*?)</loc>#', (string) @file_get_contents( "$base/$type-sitemap.xml" ), $m ) ) {
				$urls = array_merge( $urls, array_slice( $m[1], 0, 8 ) );
			}
		}

		$this->assertGreaterThan( 1, count( $urls ), 'No sitemap URLs to sample' );

		foreach ( array_unique( $urls ) as $url ) {
			foreach ( $this->entity_kinds( $this->get( $url ) ) as $kind => $types ) {
				$this->assertCount( 1, $types, "$url has more than one $kind entity: " . implode( ', ', $types ) );
			}
		}
	}
}
