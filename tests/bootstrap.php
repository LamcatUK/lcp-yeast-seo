<?php
/**
 * Test bootstrap. Unit tests run without WordPress: Brain Monkey stubs the few
 * WordPress functions the code under test calls.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'LCP_YEAST_SEO_PATH', dirname( __DIR__ ) . '/' );
define( 'LCP_YEAST_SEO_URL', 'http://example.test/wp-content/plugins/lcp-yeast-seo/' );
define( 'LCP_YEAST_SEO_VERSION', 'test' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		public $code;
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { // phpcs:ignore
		return $thing instanceof WP_Error;
	}
}

require_once __DIR__ . "/unit/TestCase.php";
