<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class OrgWizardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static function ( $v ) {
			return trim( strip_tags( (string) $v ) );
		} );
		Functions\when( 'sanitize_textarea_field' )->alias( static function ( $v ) {
			return trim( strip_tags( (string) $v ) );
		} );
		Functions\when( 'esc_url_raw' )->alias( static function ( $v ) {
			return preg_match( '#^https?://#', (string) $v ) ? $v : '';
		} );
	}

	public function test_only_the_wizards_fields_are_read_and_they_are_cleaned() {
		$out = lcp_yeast_seo_org_wizard_clean(
			array(
				'name'        => ' <b>Tidy</b> Solutions ',
				'description' => "Line one\nLine two",
				'logo'        => 'https://e.test/l.png',
				'image'       => 'javascript:alert(1)',
				'evil'        => 'x',
				'same_as'     => "https://a.test\nhttps://b.test",
			)
		);

		$this->assertSame( 'Tidy Solutions', $out['name'] );
		$this->assertSame( "Line one\nLine two", $out['description'] );
		$this->assertSame( 'https://e.test/l.png', $out['logo'] );
		$this->assertSame( '', $out['image'], 'an address that is not a web address is dropped' );
		$this->assertArrayNotHasKey( 'evil', $out );
		$this->assertSame( "https://a.test\nhttps://b.test", $out['same_as'] );
	}

	public function test_a_field_that_was_not_in_the_form_is_absent_so_replace_mode_leaves_it_alone() {
		$out = lcp_yeast_seo_org_wizard_clean( array( 'name' => 'X' ) );

		$this->assertArrayHasKey( 'name', $out );
		$this->assertArrayNotHasKey( 'telephone', $out );
		$this->assertArrayNotHasKey( 'description', $out );
		$this->assertArrayNotHasKey( 'logo', $out );
	}

	public function test_a_blank_field_that_was_in_the_form_is_present_and_empty() {
		$out = lcp_yeast_seo_org_wizard_clean( array( 'legal_name' => '', 'same_as' => '' ) );

		$this->assertArrayHasKey( 'legal_name', $out );
		$this->assertSame( '', $out['legal_name'] );
		$this->assertSame( '', $out['same_as'] );
	}

	public function test_hours_come_back_for_every_day_with_only_valid_flags() {
		$out = lcp_yeast_seo_org_wizard_clean( array( 'hours' => array( 'mon' => array( 'open' => '1', 'opens' => '09:00', 'closes' => '17:00' ), 'tue' => array( 'opens' => '09:00', 'closes' => '17:00' ), 'xyz' => array( 'open' => '1' ) ) ) );

		$this->assertSame( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), array_keys( $out['hours'] ) );
		$this->assertSame( '1', $out['hours']['mon']['open'] );
		$this->assertSame( '', $out['hours']['tue']['open'], 'an unticked day is not open' );
	}

	public function test_an_unknown_hours_mode_falls_back_to_keeping_the_hours() {
		$this->assertSame( 'keep', lcp_yeast_seo_org_wizard_clean( array( 'hours_mode' => 'delete everything' ) )['hours_mode'] );
		$this->assertSame( 'set', lcp_yeast_seo_org_wizard_clean( array( 'hours_mode' => 'set' ) )['hours_mode'] );
		$this->assertSame( 'remove', lcp_yeast_seo_org_wizard_clean( array( 'hours_mode' => 'remove' ) )['hours_mode'] );
	}

	public function test_a_non_array_request_is_harmless() {
		$this->assertSame( array_keys( lcp_yeast_seo_org_days() ), array_keys( lcp_yeast_seo_org_wizard_clean( 'oops' )['hours'] ) );
		$this->assertArrayNotHasKey( 'name', lcp_yeast_seo_org_wizard_clean( null ) );
	}
}
