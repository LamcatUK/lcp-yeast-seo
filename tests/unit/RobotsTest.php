<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class RobotsTest extends TestCase {

	private function levels( $text ) {
		return array_column( lcp_yeast_seo_analyze_robots( $text ), 'level' );
	}

	private function messages( $text ) {
		return implode( ' | ', array_column( lcp_yeast_seo_analyze_robots( $text ), 'message' ) );
	}

	public function test_the_wordpress_default_is_clean() {
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( lcp_yeast_seo_default_robots_txt() ) );
	}

	public function test_default_follows_a_subdirectory_install() {
		Functions\when( 'site_url' )->justReturn( 'http://example.test/blog' );

		$this->assertStringContainsString( 'Disallow: /blog/wp-admin/', lcp_yeast_seo_default_robots_txt() );
	}

	public function test_blocking_the_whole_site_is_a_serious_error() {
		$this->assertSame( array( 'error' ), $this->levels( "User-agent: *\nDisallow: /\n" ) );
		$this->assertStringContainsString( 'Line 2', $this->messages( "User-agent: *\nDisallow: /\n" ) );
	}

	public function test_blocking_googlebot_or_bingbot_entirely_is_an_error() {
		$this->assertContains( 'error', $this->levels( "User-agent: Googlebot\nDisallow: /\n" ) );
		$this->assertContains( 'error', $this->levels( "User-agent: bingbot\nDisallow: /*\n" ) );
	}

	public function test_blocking_other_bots_entirely_is_fine() {
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n" ) );
	}

	public function test_a_group_of_agents_shares_its_rules() {
		$this->assertContains( 'error', $this->levels( "User-agent: GPTBot\nUser-agent: *\nDisallow: /\n" ) );
	}

	public function test_rules_attach_to_the_group_they_follow() {
		$text = "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nDisallow: /\n";

		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( $text ) );
	}

	public function test_an_empty_disallow_means_allow_everything() {
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( "User-agent: *\nDisallow:\n" ) );
	}

	public function test_blocking_assets_is_a_warning_not_an_error() {
		foreach ( array( '/wp-content/uploads/', '/wp-includes/', '/wp-content/themes/', '/*.css$', '/assets/app.js' ) as $path ) {
			$this->assertSame( array( 'warning' ), $this->levels( "User-agent: *\nDisallow: $path\n" ), $path );
		}
	}

	public function test_ordinary_disallows_are_fine() {
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( "User-agent: *\nDisallow: /wp-admin/\nDisallow: /search/\nAllow: /wp-admin/admin-ajax.php\n" ) );
	}

	public function test_comments_and_blank_lines_are_ignored() {
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( "# keep out\n\nUser-agent: * # everyone\nDisallow: /private/ # secret\n" ) );
	}

	public function test_a_commented_out_block_is_not_a_block() {
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( "User-agent: *\n# Disallow: /\nDisallow: /wp-admin/\n" ) );
	}

	public function test_typos_and_unsupported_directives_are_warned_about() {
		$this->assertStringContainsString( 'probably a typo', $this->messages( "User-agent: *\nDisalow: /x/\n" ) );
		$this->assertStringContainsString( 'noindex', $this->messages( "User-agent: *\nNoindex: /x/\n" ) );
	}

	public function test_rules_before_any_user_agent_are_warned_about() {
		$this->assertStringContainsString( 'before any User-agent', $this->messages( "Disallow: /x/\nUser-agent: *\n" ) );
		$this->assertStringContainsString( 'no User-agent', $this->messages( "Sitemap: https://example.test/s.xml\nBogus: 1\n" ) );
	}

	public function test_a_relative_sitemap_url_is_warned_about() {
		$this->assertSame( array( 'warning' ), $this->levels( "User-agent: *\nSitemap: /sitemap.xml\n" ) );
		$this->assertSame( array(), lcp_yeast_seo_analyze_robots( "User-agent: *\nSitemap: https://example.test/sitemap.xml\n" ) );
	}

	public function test_an_oversized_file_is_an_error() {
		$text = "User-agent: *\n" . str_repeat( "Disallow: /a/\n", 40000 );

		$this->assertContains( 'error', $this->levels( $text ) );
	}

	public function test_cleaning_normalises_line_endings_and_strips_junk() {
		$this->assertSame( "User-agent: *\nDisallow: /a/\n", lcp_yeast_seo_clean_robots_text( "User-agent: *  \r\nDisallow: /a/\r\r\n\x00\n" ) );
		$this->assertSame( '', lcp_yeast_seo_clean_robots_text( "  \n\n " ) );
		$this->assertSame( '', lcp_yeast_seo_clean_robots_text( null ) );
	}

	public function test_cleaning_keeps_percent_encoded_paths() {
		$this->assertStringContainsString( '/caf%C3%A9/', lcp_yeast_seo_clean_robots_text( "User-agent: *\nDisallow: /caf%C3%A9/\n" ) );
	}

	public function test_a_dangerous_save_keeps_the_previous_rules() {
		Functions\when( 'get_option' )->justReturn( array( 'robots_txt' => "User-agent: *\nDisallow: /wp-admin/\n" ) );
		Functions\expect( 'add_settings_error' )->once()->with( \Mockery::any(), \Mockery::any(), \Mockery::pattern( '/not saved.*Save anyway/s' ), 'error' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\expect( 'set_transient' )->once()->with( 'lcp_yeast_seo_robots_draft_7', "User-agent: *\nDisallow: /\n", 300 );

		$saved = lcp_yeast_seo_sanitize_robots_setting( array( 'robots_txt' => "User-agent: *\nDisallow: /\n" ) );

		$this->assertSame( "User-agent: *\nDisallow: /wp-admin/\n", $saved );
	}

	public function test_a_dangerous_save_goes_through_when_overridden() {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'add_settings_error' )->once()->with( \Mockery::any(), \Mockery::any(), \Mockery::pattern( '/saved, but/' ), 'warning' );

		$saved = lcp_yeast_seo_sanitize_robots_setting( array( 'robots_txt' => "User-agent: *\nDisallow: /\n", 'robots_override' => '1' ) );

		$this->assertSame( "User-agent: *\nDisallow: /\n", $saved );
	}

	public function test_a_warning_still_saves() {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'add_settings_error' )->once()->with( \Mockery::any(), \Mockery::any(), \Mockery::type( 'string' ), 'warning' );

		$this->assertSame( "User-agent: *\nDisallow: /wp-includes/\n", lcp_yeast_seo_sanitize_robots_setting( array( 'robots_txt' => "User-agent: *\nDisallow: /wp-includes/\n" ) ) );
	}

	public function test_saving_unchanged_rules_makes_no_noise() {
		$rules = "User-agent: *\nDisallow: /\n";

		Functions\when( 'get_option' )->justReturn( array( 'robots_txt' => $rules ) );
		Functions\expect( 'add_settings_error' )->never();

		$this->assertSame( $rules, lcp_yeast_seo_sanitize_robots_setting( array( 'robots_txt' => $rules ) ) );
	}

	public function test_saving_another_tab_leaves_the_rules_alone() {
		Functions\when( 'get_option' )->justReturn( array( 'robots_txt' => "User-agent: *\nDisallow: /a/\n" ) );

		$this->assertSame( "User-agent: *\nDisallow: /a/\n", lcp_yeast_seo_sanitize_robots_setting( array( 'twitter_site' => '@x' ) ) );
	}

	public function test_custom_rules_replace_the_default_output() {
		Functions\when( 'get_option' )->justReturn( array( 'robots_txt' => "User-agent: *\nDisallow: /private/\n" ) );

		$this->assertSame( "User-agent: *\nDisallow: /private/\n", lcp_yeast_seo_custom_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n" ) );
	}

	public function test_no_saved_rules_keeps_the_default_output() {
		$this->assertSame( 'DEFAULT', lcp_yeast_seo_custom_robots_txt( 'DEFAULT' ) );
	}

	public function test_saved_rules_are_served_even_when_the_site_discourages_search_engines() {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'blog_public' === $name ? 0 : array( 'robots_txt' => "User-agent: *\nAllow: /\n" );
			}
		);

		$this->assertSame( "User-agent: *\nAllow: /\n", lcp_yeast_seo_custom_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n" ) );
	}
}
