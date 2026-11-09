<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'wp_json_encode'     => static function ( $data, $flags = 0 ) {
					return json_encode( $data, $flags );
				},
				'wp_strip_all_tags'  => static function ( $text ) {
					return trim( strip_tags( (string) $text ) );
				},
				'trailingslashit'    => static function ( $value ) {
					return rtrim( (string) $value, '/\\' ) . '/';
				},
				'untrailingslashit'  => static function ( $value ) {
					return rtrim( (string) $value, '/\\' );
				},
				'wp_parse_url'       => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				},
				'wp_unslash'         => static function ( $value ) {
					return $value;
				},
				'site_url'           => static function ( $path = '' ) {
					return 'http://example.test' . $path;
				},
				'home_url'           => static function ( $path = '' ) {
					return 'http://example.test' . $path;
				},
				'get_option'         => static function ( $name, $default = false ) {
					return $default;
				},
			)
		);

		foreach ( array( 'settings', 'post-meta', 'templates', 'sitemaps', 'schema', 'auto-schema', 'import-export', 'editor-checks', 'robots' ) as $file ) {
			require_once LCP_YEAST_SEO_PATH . 'inc/' . $file . '.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
