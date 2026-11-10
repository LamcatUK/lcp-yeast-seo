<?php
namespace Lcp\YeastSeo\Tests;

class OrgScoreTest extends TestCase {

	private function full_local() {
		return array(
			'@context'                  => 'https://schema.org',
			'@type'                     => 'ProfessionalService',
			'@id'                       => 'https://example.test/#organization',
			'name'                      => 'Example Ltd',
			'legalName'                 => 'Example Limited',
			'url'                       => 'https://example.test/',
			'description'               => 'We clear houses, gardens and offices across the island, with fixed quotes and responsible disposal.',
			'logo'                      => array( '@type' => 'ImageObject', 'url' => 'https://example.test/logo.png', 'width' => 600, 'height' => 200 ),
			'image'                     => 'https://example.test/van.jpg',
			'sameAs'                    => array( 'https://facebook.com/x', 'https://linkedin.com/company/x', 'https://instagram.com/x' ),
			'telephone'                 => '+44 1624 000000',
			'email'                     => 'hello@example.test',
			'contactPoint'              => array( '@type' => 'ContactPoint', 'telephone' => '+44 1624 000000', 'contactType' => 'customer service' ),
			'address'                   => array( '@type' => 'PostalAddress', 'streetAddress' => '1 High St', 'addressLocality' => 'Douglas', 'postalCode' => 'IM1 1AA', 'addressCountry' => 'IM' ),
			'geo'                       => array( '@type' => 'GeoCoordinates', 'latitude' => 54.15, 'longitude' => -4.48 ),
			'openingHoursSpecification' => array( array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array( 'Monday' ), 'opens' => '09:00', 'closes' => '17:00' ) ),
			'areaServed'                => array( array( '@type' => 'AdministrativeArea', 'name' => 'Isle of Man' ) ),
			'priceRange'                => '££',
			'foundingDate'              => '2015',
		);
	}

	private function check( array $result, $id ) {
		return array_column( $result['checks'], null, 'id' )[ $id ];
	}

	public function test_a_complete_local_business_scores_full_marks_with_nothing_to_improve() {
		$result = lcp_yeast_seo_score_organization( $this->full_local() );

		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 'Excellent', $result['grade'] );
		$this->assertTrue( $result['local'] );
		$this->assertSame( array(), $result['tips'] );

		foreach ( $result['checks'] as $check ) {
			$this->assertSame( 'pass', $check['status'], $check['id'] );
			$this->assertSame( '', $check['tip'], $check['id'] );
		}
	}

	public function test_a_bare_organization_scores_low_and_every_gap_has_a_tip() {
		$result = lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'Example' ) );

		$this->assertFalse( $result['local'] );
		$this->assertLessThan( 30, $result['score'] );
		$this->assertSame( 'Poor', $result['grade'] );
		$this->assertSame( 'pass', $this->check( $result, 'name' )['status'] );

		foreach ( $result['checks'] as $check ) {
			if ( 'pass' !== $check['status'] ) {
				$this->assertNotSame( '', $check['tip'], $check['id'] . ' has no tip' );
			}
		}
	}

	public function test_local_checks_only_count_for_local_business_types() {
		$org   = lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ) );
		$local = lcp_yeast_seo_score_organization( array( '@type' => 'LocalBusiness', 'name' => 'X' ) );

		foreach ( array( 'geo', 'hours', 'area', 'price', 'image' ) as $id ) {
			$this->assertNotContains( $id, array_column( $org['checks'], 'id' ), "$id applies only to local businesses" );
			$this->assertContains( $id, array_column( $local['checks'], 'id' ) );
		}

		$this->assertArrayHasKey( 'local', $local['groups'] );
		$this->assertArrayNotHasKey( 'local', $org['groups'] );
	}

	public function test_a_complete_non_local_organization_can_also_score_full_marks() {
		$node = $this->full_local();
		$node['@type'] = 'NGO';
		unset( $node['geo'], $node['openingHoursSpecification'], $node['areaServed'], $node['priceRange'], $node['image'] );

		$this->assertSame( 100, lcp_yeast_seo_score_organization( $node )['score'], 'missing opening hours is not a gap for a non-local organization' );
	}

	public function test_the_generic_organization_type_is_only_nudged_when_the_business_looks_local() {
		$generic = $this->full_local();
		$generic['@type'] = 'Organization';
		unset( $generic['geo'], $generic['openingHoursSpecification'], $generic['areaServed'], $generic['priceRange'], $generic['image'] );

		$this->assertSame( 'partial', $this->check( lcp_yeast_seo_score_organization( $generic ), 'type' )['status'] );
		$this->assertSame( 'pass', $this->check( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ) ), 'type' )['status'], 'with no address or phone it may well be an online organization' );
	}

	public function test_the_logo_is_judged_by_form_and_size() {
		$base = array( '@type' => 'Organization', 'name' => 'X' );

		$this->assertSame( 'fail', $this->check( lcp_yeast_seo_score_organization( $base ), 'logo' )['status'] );
		$this->assertSame( 'partial', $this->check( lcp_yeast_seo_score_organization( $base + array( 'logo' => 'https://e.test/l.png' ) ), 'logo' )['status'], 'a bare address is only partly right' );
		$this->assertSame( 'partial', $this->check( lcp_yeast_seo_score_organization( $base + array( 'logo' => array( 'url' => 'https://e.test/l.png' ) ) ), 'logo' )['status'], 'no size' );

		$small = $this->check( lcp_yeast_seo_score_organization( $base + array( 'logo' => array( 'url' => 'https://e.test/l.png', 'width' => 50, 'height' => 50 ) ) ), 'logo' );
		$this->assertSame( 'partial', $small['status'] );
		$this->assertStringContainsString( '112', $small['tip'] );

		$this->assertSame( 'pass', $this->check( lcp_yeast_seo_score_organization( $base + array( 'logo' => array( 'url' => 'https://e.test/l.png', 'width' => 300, 'height' => 300 ) ) ), 'logo' )['status'] );
	}

	public function test_a_logo_that_is_a_reference_to_another_node_is_followed() {
		$org   = array( '@type' => 'Organization', 'name' => 'X', '@id' => 'https://e.test/#organization', 'logo' => array( '@id' => 'https://e.test/#logo' ) );
		$logo  = array( '@type' => 'ImageObject', '@id' => 'https://e.test/#logo', 'url' => 'https://e.test/l.png', 'width' => 400, 'height' => 400 );

		$this->assertSame( 'pass', $this->check( lcp_yeast_seo_score_organization( $org, array( $org, $logo ) ), 'logo' )['status'] );
		$this->assertSame( 'fail', $this->check( lcp_yeast_seo_score_organization( $org, array( $org ) ), 'logo' )['status'], 'an unresolved reference earns nothing' );
	}

	public function test_an_incomplete_address_says_what_is_missing() {
		$result  = lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'address' => array( 'streetAddress' => '1 High St', 'addressLocality' => 'Douglas' ) ) );
		$address = $this->check( $result, 'address' );

		$this->assertSame( 'partial', $address['status'] );
		$this->assertSame( 5, $address['earned'] );
		$this->assertStringContainsString( 'postalCode', $address['tip'] );
		$this->assertStringContainsString( 'addressCountry', $address['tip'] );
		$this->assertStringNotContainsString( 'streetAddress', $address['tip'] );
	}

	public function test_social_profiles_are_scored_by_how_many_real_links_there_are() {
		$score = static function ( $links ) {
			return array_column( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'sameAs' => $links ) )['checks'], null, 'id' )['sameas']['status'];
		};

		$this->assertSame( 'fail', $score( array() ) );
		$this->assertSame( 'fail', $score( array( 'not a link', '' ) ) );
		$this->assertSame( 'partial', $score( array( 'https://facebook.com/x' ) ) );
		$this->assertSame( 'pass', $score( array( 'https://a.test', 'https://b.test', 'https://c.test' ) ) );
	}

	public function test_a_telephone_or_email_inside_a_contact_point_counts() {
		$result = lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'contactPoint' => array( '@type' => 'ContactPoint', 'telephone' => '+44 1', 'email' => 'a@b.test' ) ) );

		$this->assertSame( 'pass', $this->check( $result, 'telephone' )['status'] );
		$this->assertSame( 'pass', $this->check( $result, 'email' )['status'] );
		$this->assertSame( 'partial', $this->check( $result, 'contactpoint' )['status'], 'a contact point with no contactType' );
	}

	public function test_the_advice_fits_the_mode() {
		$node = array( '@type' => 'LocalBusiness', 'name' => 'X' );

		$raw    = array_column( lcp_yeast_seo_score_organization( $node, array(), 'raw' )['checks'], null, 'id' );
		$fields = array_column( lcp_yeast_seo_score_organization( $node, array(), 'fields' )['checks'], null, 'id' );

		$this->assertStringContainsString( 'openingHoursSpecification', $raw['hours']['example'], 'raw mode gets a snippet to paste' );
		$this->assertSame( '', $fields['hours']['example'], 'the fields form cannot set hours, so no snippet is offered for it' );
		$this->assertStringContainsString( 'ImageObject', $raw['logo']['example'] );
	}

	public function test_a_legal_name_is_not_scored_because_it_is_often_the_same_as_the_trading_name() {
		$ids = array_column( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ) )['checks'], 'id' );

		$this->assertNotContains( 'legalname', $ids );
	}

	public function test_the_founding_date_tip_says_where_to_add_it() {
		$raw    = array_column( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ), array(), 'raw' )['checks'], null, 'id' )['founded'];
		$fields = array_column( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ), array(), 'fields' )['checks'], null, 'id' )['founded'];

		$this->assertSame( '"foundingDate": "2015"', $raw['example'] );
		$this->assertStringContainsString( 'JSON-LD', $raw['tip'] );
		$this->assertStringContainsString( 'Founded', $fields['tip'] );
		$this->assertSame( '', $fields['example'] );
	}

	public function test_the_logo_tip_gives_real_sizes() {
		$logo = array_column( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ) )['checks'], null, 'id' )['logo'];

		$this->assertStringContainsString( '112 x 112', $logo['tip'] );
		$this->assertStringContainsString( '512 x 512', $logo['tip'] );
	}

	public function test_the_id_should_end_in_organization() {
		$score = static function ( $id ) {
			return array_column( lcp_yeast_seo_score_organization( array( '@type' => 'Organization', '@id' => $id ) )['checks'], null, 'id' )['id']['status'];
		};

		$this->assertSame( 'pass', $score( 'https://e.test/#organization' ) );
		$this->assertSame( 'partial', $score( 'https://e.test/#company' ) );
		$this->assertSame( 'fail', $score( '' ) );
	}

	public function test_the_biggest_losses_come_first_in_the_tips() {
		$tips = lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X' ) )['tips'];
		$lost = array_map( static function ( $tip ) {
			return $tip['weight'] - $tip['earned'];
		}, $tips );

		$sorted = $lost;
		rsort( $sorted );

		$this->assertSame( $sorted, $lost );
		$this->assertNotEmpty( $tips );
	}

	public function test_group_scores_are_percentages_of_each_group() {
		$result = lcp_yeast_seo_score_organization( array( '@type' => 'Organization', 'name' => 'X', 'url' => 'https://e.test/' ) );

		$this->assertSame( array( 'identity', 'brand', 'contact', 'trust' ), array_keys( $result['groups'] ) );

		foreach ( $result['groups'] as $group ) {
			$this->assertGreaterThanOrEqual( 0, $group['score'] );
			$this->assertLessThanOrEqual( 100, $group['score'] );
		}
	}
}
