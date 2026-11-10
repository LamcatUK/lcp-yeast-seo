<?php
namespace Lcp\YeastSeo\Tests;

class OrgQaTest extends TestCase {

	private $context = array( 'home_url' => 'https://example.test/', 'id' => 'https://example.test/#organization' );

	private function apply( array $node, array $answers, array $context = array() ) {
		return lcp_yeast_seo_org_apply_answers( $node, $answers, $context + $this->context );
	}

	public function test_text_answers_fill_blanks_and_never_overwrite() {
		$node = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/', 'name' => 'Existing Name', 'description' => 'Already written.' );

		$out = $this->apply( $node, array( 'name' => 'Changed', 'description' => 'Replacement', 'telephone' => '+44 1624 000000', 'email' => 'a@b.test', 'legal_name' => 'Existing Limited', 'founded' => '2015' ) );

		$this->assertSame( 'Existing Name', $out['node']['name'] );
		$this->assertSame( 'Already written.', $out['node']['description'] );
		$this->assertSame( '+44 1624 000000', $out['node']['telephone'] );
		$this->assertSame( 'Existing Limited', $out['node']['legalName'] );
		$this->assertSame( '2015', $out['node']['foundingDate'] );
		$this->assertEqualsCanonicalizing( array( 'Telephone', 'Email', 'Legal name', 'Founding date' ), $out['added'] );
	}

	public function test_a_missing_identifier_and_address_are_set_automatically() {
		$out = $this->apply( array( '@type' => 'Organization', 'name' => 'X' ), array() );

		$this->assertSame( 'https://example.test/#organization', $out['node']['@id'] );
		$this->assertSame( 'https://example.test/', $out['node']['url'] );
		$this->assertContains( 'Identifier', $out['added'] );
		$this->assertContains( 'Website address', $out['added'] );
	}

	public function test_an_identifier_that_already_ends_in_organization_is_kept() {
		$out = $this->apply( array( '@type' => 'Organization', '@id' => 'https://example.test/about/#organization', 'url' => 'https://example.test/' ), array() );

		$this->assertSame( 'https://example.test/about/#organization', $out['node']['@id'] );
		$this->assertSame( array(), $out['added'] );
	}

	public function test_a_bad_email_year_or_web_address_is_left_out() {
		$out = $this->apply( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' ), array( 'email' => 'not an email', 'founded' => 'last year', 'logo' => 'not a url', 'image' => 'javascript:alert(1)', 'same_as' => "ftp://x.test\nnot a link\nhttps://ok.test/me" ) );

		$this->assertArrayNotHasKey( 'email', $out['node'] );
		$this->assertArrayNotHasKey( 'foundingDate', $out['node'] );
		$this->assertArrayNotHasKey( 'logo', $out['node'] );
		$this->assertArrayNotHasKey( 'image', $out['node'] );
		$this->assertSame( array( 'https://ok.test/me' ), $out['node']['sameAs'] );
	}

	public function test_profiles_are_added_to_the_existing_ones_without_duplicates() {
		$node = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/', 'sameAs' => array( 'https://facebook.com/x' ) );

		$out = $this->apply( $node, array( 'same_as' => "https://facebook.com/x\nhttps://linkedin.com/company/x" ) );

		$this->assertSame( array( 'https://facebook.com/x', 'https://linkedin.com/company/x' ), $out['node']['sameAs'] );
		$this->assertContains( 'Profiles', $out['added'] );

		$again = $this->apply( $out['node'], array( 'same_as' => 'https://facebook.com/x' ) );
		$this->assertNotContains( 'Profiles', $again['added'], 'nothing new was added' );
	}

	public function test_address_parts_fill_only_the_gaps() {
		$node = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/', 'address' => array( '@type' => 'PostalAddress', 'streetAddress' => '1 High St', 'addressLocality' => 'Douglas' ) );

		$out = $this->apply( $node, array( 'street' => 'CHANGED', 'locality' => 'CHANGED', 'postcode' => 'IM1 1AA', 'country' => 'IM' ) );

		$this->assertSame( '1 High St', $out['node']['address']['streetAddress'] );
		$this->assertSame( 'Douglas', $out['node']['address']['addressLocality'] );
		$this->assertSame( 'IM1 1AA', $out['node']['address']['postalCode'] );
		$this->assertSame( 'IM', $out['node']['address']['addressCountry'] );
		$this->assertSame( 'PostalAddress', $out['node']['address']['@type'] );
	}

	public function test_map_coordinates_must_both_be_valid_numbers() {
		$base = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' );

		$good = $this->apply( $base, array( 'latitude' => '54.15', 'longitude' => '-4.48' ) );
		$this->assertSame( array( '@type' => 'GeoCoordinates', 'latitude' => 54.15, 'longitude' => -4.48 ), $good['node']['geo'] );

		foreach ( array( array( '95', '10' ), array( '54', '200' ), array( 'north', 'west' ), array( '54', '' ) ) as $pair ) {
			$this->assertArrayNotHasKey( 'geo', $this->apply( $base, array( 'latitude' => $pair[0], 'longitude' => $pair[1] ) )['node'], implode( ',', $pair ) );
		}
	}

	public function test_opening_hours_need_the_box_ticked_and_days_with_the_same_hours_are_grouped() {
		$hours = array(
			'mon' => array( 'open' => '1', 'opens' => '09:00', 'closes' => '17:00' ),
			'tue' => array( 'open' => '1', 'opens' => '09:00', 'closes' => '17:00' ),
			'wed' => array( 'open' => '1', 'opens' => '10:00', 'closes' => '14:00' ),
			'thu' => array( 'opens' => '09:00', 'closes' => '17:00' ),
			'sat' => array( 'open' => '1', 'opens' => '17:00', 'closes' => '09:00' ),
			'sun' => array( 'open' => '1', 'opens' => 'late', 'closes' => '17:00' ),
		);
		$base  = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' );

		$this->assertArrayNotHasKey( 'openingHoursSpecification', $this->apply( $base, array( 'hours' => $hours ) )['node'], 'hours are only added when the box is ticked' );

		$out = $this->apply( $base, array( 'add_hours' => '1', 'hours' => $hours ) );

		$this->assertSame(
			array(
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array( 'Monday', 'Tuesday' ), 'opens' => '09:00', 'closes' => '17:00' ),
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array( 'Wednesday' ), 'opens' => '10:00', 'closes' => '14:00' ),
			),
			$out['node']['openingHoursSpecification'],
			'unticked, closing-before-opening and malformed days are dropped'
		);
		$this->assertContains( 'Opening hours', $out['added'] );
	}

	public function test_ticking_hours_but_giving_no_usable_day_adds_nothing() {
		$out = $this->apply( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' ), array( 'add_hours' => '1', 'hours' => array() ) );

		$this->assertArrayNotHasKey( 'openingHoursSpecification', $out['node'] );
		$this->assertNotContains( 'Opening hours', $out['added'] );
	}

	public function test_areas_become_administrative_areas_and_are_not_duplicated() {
		$node = array( '@type' => 'LocalBusiness', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/', 'areaServed' => array( array( '@type' => 'AdministrativeArea', 'name' => 'Douglas' ) ) );

		$out = $this->apply( $node, array( 'areas' => "Douglas\nRamsey, Peel" ) );

		$this->assertSame( array( 'Douglas', 'Ramsey', 'Peel' ), array_column( $out['node']['areaServed'], 'name' ) );
		$this->assertSame( 'AdministrativeArea', $out['node']['areaServed'][1]['@type'] );
	}

	public function test_the_logo_gets_its_size_when_it_is_known() {
		$base = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' );

		$sized = $this->apply( $base, array( 'logo' => 'https://example.test/l.png' ), array( 'logo_size' => array( 600, 200 ) ) );
		$this->assertSame( array( '@type' => 'ImageObject', 'url' => 'https://example.test/l.png', 'width' => 600, 'height' => 200 ), $sized['node']['logo'] );

		$unsized = $this->apply( $base, array( 'logo' => 'https://example.test/l.png' ) );
		$this->assertSame( array( '@type' => 'ImageObject', 'url' => 'https://example.test/l.png' ), $unsized['node']['logo'] );
	}

	public function test_a_contact_point_needs_a_number_or_email_to_describe() {
		$base = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' );

		$none = $this->apply( $base, array( 'contact_type' => 'customer service' ) );
		$this->assertArrayNotHasKey( 'contactPoint', $none['node'] );

		$with = $this->apply( $base + array( 'telephone' => '+44 1' ), array( 'contact_type' => 'customer service' ) );
		$this->assertSame( array( '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+44 1' ), $with['node']['contactPoint'] );

		$together = $this->apply( $base, array( 'telephone' => '+44 2', 'contact_type' => 'sales' ) );
		$this->assertSame( '+44 2', $together['node']['contactPoint']['telephone'], 'a number given in the same answers is used' );
	}

	public function test_only_a_listed_business_type_is_accepted() {
		$base = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/' );

		$this->assertSame( 'Electrician', $this->apply( $base, array( 'type' => 'Electrician' ) )['node']['@type'] );
		$this->assertSame( 'Organization', $this->apply( $base, array( 'type' => 'NotAType' ) )['node']['@type'] );
		$this->assertSame( 'Organization', $this->apply( $base, array( 'type' => '' ) )['node']['@type'] );
	}

	public function test_answering_the_questions_raises_the_score() {
		$node = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'url' => 'https://example.test/', 'name' => 'Example' );
		$before = lcp_yeast_seo_score_organization( $node );

		$out = $this->apply( $node, array( 'type' => 'ProfessionalService', 'description' => 'We clear houses and gardens across the whole island, with fixed quotes.', 'telephone' => '+44 1624 000000', 'street' => '1 High St', 'locality' => 'Douglas', 'postcode' => 'IM1 1AA', 'country' => 'IM', 'same_as' => "https://a.test\nhttps://b.test\nhttps://c.test", 'latitude' => '54.15', 'longitude' => '-4.48', 'add_hours' => '1', 'hours' => array( 'mon' => array( 'open' => '1', 'opens' => '09:00', 'closes' => '17:00' ) ), 'areas' => 'Isle of Man' ) );
		$after = lcp_yeast_seo_score_organization( $out['node'] );

		$this->assertGreaterThan( $before['score'] + 25, $after['score'] );
	}

	public function test_the_questions_asked_are_the_checks_that_did_not_pass() {
		$node   = array( '@type' => 'LocalBusiness', 'name' => 'X', 'address' => array( 'streetAddress' => '1 High St', 'addressLocality' => 'Douglas' ), 'telephone' => '+44 1' );
		$result = lcp_yeast_seo_score_organization( $node );
		$asked  = lcp_yeast_seo_org_questions( $result, $node );
		$ids    = array_column( $asked, 'id' );

		$this->assertContains( 'description', $ids );
		$this->assertContains( 'hours', $ids );
		$this->assertContains( 'logo', $ids );
		$this->assertNotContains( 'name', $ids, 'a business name is already there' );
		$this->assertNotContains( 'telephone', $ids );

		$address = array_column( $asked, null, 'id' )['address'];
		$this->assertSame( array( 'postalCode', 'addressCountry' ), $address['missing'], 'only the missing address parts are asked for' );
	}

	public function test_a_complete_organization_has_no_questions() {
		$node = array( '@type' => 'Organization', 'name' => 'X' );

		$this->assertSame( array(), lcp_yeast_seo_org_questions( array( 'checks' => array( array( 'id' => 'name', 'status' => 'pass' ) ) ), $node ) );
	}

	private function full_node() {
		return array(
			'@context'                  => 'https://schema.org',
			'@type'                     => 'ProfessionalService',
			'@id'                       => 'https://example.test/#organization',
			'name'                      => 'Example Ltd',
			'legalName'                 => 'Example Limited',
			'url'                       => 'https://example.test/',
			'description'               => 'We clear houses, gardens and offices across the island.',
			'logo'                      => array( '@type' => 'ImageObject', 'url' => 'https://example.test/logo.png', 'width' => 600, 'height' => 200 ),
			'image'                     => 'https://example.test/van.jpg',
			'sameAs'                    => array( 'https://facebook.com/x', 'https://linkedin.com/company/x' ),
			'telephone'                 => '+44 1624 000000',
			'email'                     => 'hello@example.test',
			'contactPoint'              => array( '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+44 1624 000000', 'email' => 'hello@example.test' ),
			'address'                   => array( '@type' => 'PostalAddress', 'streetAddress' => '1 High St', 'addressLocality' => 'Douglas', 'postalCode' => 'IM1 1AA', 'addressCountry' => 'IM' ),
			'geo'                       => array( '@type' => 'GeoCoordinates', 'latitude' => 54.15, 'longitude' => -4.48 ),
			'openingHoursSpecification' => array(
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array( 'Monday', 'Tuesday' ), 'opens' => '09:00', 'closes' => '17:00' ),
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Saturday', 'opens' => '10:00:00', 'closes' => '14:00:00' ),
			),
			'areaServed'                => array( array( '@type' => 'AdministrativeArea', 'name' => 'Isle of Man' ), array( '@type' => 'City', 'name' => 'Douglas' ) ),
			'priceRange'                => '££',
			'foundingDate'              => '2015',
		);
	}

	public function test_opening_hours_are_read_back_into_the_form_shape() {
		$hours = lcp_yeast_seo_org_hours_from_node( $this->full_node() );

		$this->assertSame( array( 'mon', 'tue', 'sat' ), array_keys( $hours ) );
		$this->assertSame( array( 'open' => '1', 'opens' => '09:00', 'closes' => '17:00' ), $hours['tue'] );
		$this->assertSame( array( 'open' => '1', 'opens' => '10:00', 'closes' => '14:00' ), $hours['sat'], 'a schema.org day address and seconds are handled' );
		$this->assertSame( array(), lcp_yeast_seo_org_hours_from_node( array( 'name' => 'X' ) ) );
	}

	public function test_prefill_reads_every_answer_from_the_node() {
		$a = lcp_yeast_seo_org_prefill( $this->full_node() );

		$this->assertSame( 'Example Ltd', $a['name'] );
		$this->assertSame( 'ProfessionalService', $a['type'] );
		$this->assertSame( 'https://example.test/logo.png', $a['logo'] );
		$this->assertSame( "https://facebook.com/x\nhttps://linkedin.com/company/x", $a['same_as'] );
		$this->assertSame( 'customer service', $a['contact_type'] );
		$this->assertSame( 'IM1 1AA', $a['postcode'] );
		$this->assertSame( '54.15', $a['latitude'] );
		$this->assertSame( "Isle of Man\nDouglas", $a['areas'] );
		$this->assertSame( 'Example Limited', $a['legal_name'] );
		$this->assertArrayHasKey( 'mon', $a['hours'] );
	}

	public function test_a_type_outside_the_offered_list_is_not_prefilled() {
		$this->assertSame( '', lcp_yeast_seo_org_prefill( array( '@type' => 'Organization' ) )['type'] );
	}

	public function test_submitting_the_prefilled_form_unchanged_changes_nothing() {
		$node    = $this->full_node();
		$answers = lcp_yeast_seo_org_prefill( $node ) + array( 'hours_mode' => 'keep' );

		$out = $this->apply( $node, $answers, array( 'replace' => true, 'logo_size' => array( 600, 200 ) ) );

		$this->assertSame( array(), $out['added'], implode( ',', $out['added'] ) );
		$this->assertSame( array(), $out['removed'] );
		$this->assertSame( $node, $out['node'] );
	}

	public function test_replace_mode_changes_what_the_user_edited() {
		$node    = $this->full_node();
		$answers = lcp_yeast_seo_org_prefill( $node );
		$answers['name']        = 'Renamed Ltd';
		$answers['telephone']   = '+44 7000 000000';
		$answers['postcode']    = 'IM2 2BB';
		$answers['same_as']     = "https://facebook.com/x\nhttps://manx.directory/business/x/";
		$answers['areas']       = 'Isle of Man';

		$out = $this->apply( $node, $answers, array( 'replace' => true, 'logo_size' => array( 600, 200 ) ) );

		$this->assertSame( 'Renamed Ltd', $out['node']['name'] );
		$this->assertSame( '+44 7000 000000', $out['node']['telephone'] );
		$this->assertSame( 'IM2 2BB', $out['node']['address']['postalCode'] );
		$this->assertSame( 'Douglas', $out['node']['address']['addressLocality'], 'untouched parts stay' );
		$this->assertSame( array( 'https://facebook.com/x', 'https://manx.directory/business/x/' ), $out['node']['sameAs'], 'the list is replaced, so LinkedIn is gone' );
		$this->assertSame( array( 'Isle of Man' ), array_column( $out['node']['areaServed'], 'name' ) );
		$this->assertEqualsCanonicalizing( array( 'Business name', 'Telephone', 'Address', 'Profiles', 'Area served', 'Contact point' ), $out['added'] );
	}

	public function test_replace_mode_removes_what_the_user_cleared() {
		$node    = $this->full_node();
		$answers = lcp_yeast_seo_org_prefill( $node );
		$answers['legal_name'] = '';
		$answers['price_range'] = '';
		$answers['street'] = '';
		$answers['postcode'] = '';
		$answers['latitude'] = '';
		$answers['longitude'] = '';
		$answers['same_as'] = '';
		$answers['logo'] = '';

		$out = $this->apply( $node, $answers, array( 'replace' => true ) );

		foreach ( array( 'legalName', 'priceRange', 'geo', 'sameAs', 'logo' ) as $property ) {
			$this->assertArrayNotHasKey( $property, $out['node'], $property );
		}

		$this->assertArrayNotHasKey( 'streetAddress', $out['node']['address'] );
		$this->assertArrayNotHasKey( 'postalCode', $out['node']['address'] );
		$this->assertSame( 'Douglas', $out['node']['address']['addressLocality'] );
		$this->assertEqualsCanonicalizing( array( 'Legal name', 'Price range', 'Map position', 'Profiles', 'Logo' ), $out['removed'] );
		$this->assertContains( 'Address', $out['added'], 'some parts cleared, so the address changed but was not removed' );
	}

	public function test_clearing_every_address_part_removes_the_address() {
		$node    = $this->full_node();
		$answers = array( 'street' => '', 'locality' => '', 'region' => '', 'postcode' => '', 'country' => '' );

		$out = $this->apply( $node, $answers, array( 'replace' => true ) );

		$this->assertArrayNotHasKey( 'address', $out['node'] );
		$this->assertContains( 'Address', $out['removed'] );
	}

	public function test_replace_mode_only_touches_the_questions_that_were_asked() {
		$node = $this->full_node();

		$out = $this->apply( $node, array( 'name' => 'Only This' ), array( 'replace' => true ) );

		$this->assertSame( 'Only This', $out['node']['name'] );
		$this->assertSame( $node['description'], $out['node']['description'], 'a question that was not in the form leaves its property alone' );
		$this->assertSame( $node['sameAs'], $out['node']['sameAs'] );
		$this->assertSame( $node['address'], $out['node']['address'] );
	}

	public function test_an_invalid_answer_never_clears_a_good_value() {
		$node = $this->full_node();

		$out = $this->apply( $node, array( 'email' => 'not an email', 'founded' => 'long ago', 'latitude' => '95', 'longitude' => '10' ), array( 'replace' => true ) );

		$this->assertSame( 'hello@example.test', $out['node']['email'] );
		$this->assertSame( '2015', $out['node']['foundingDate'] );
		$this->assertSame( 54.15, $out['node']['geo']['latitude'] );
		$this->assertSame( array(), $out['removed'] );
	}

	public function test_opening_hours_can_be_kept_changed_or_removed() {
		$node = $this->full_node();
		$new  = array( 'mon' => array( 'open' => '1', 'opens' => '08:00', 'closes' => '12:00' ) );

		$keep = $this->apply( $node, array( 'hours_mode' => 'keep', 'hours' => $new ), array( 'replace' => true ) );
		$this->assertSame( $node['openingHoursSpecification'], $keep['node']['openingHoursSpecification'] );

		$set = $this->apply( $node, array( 'hours_mode' => 'set', 'hours' => $new ), array( 'replace' => true ) );
		$this->assertSame( array( 'Monday' ), $set['node']['openingHoursSpecification'][0]['dayOfWeek'] );
		$this->assertCount( 1, $set['node']['openingHoursSpecification'] );

		$gone = $this->apply( $node, array( 'hours_mode' => 'remove' ), array( 'replace' => true ) );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', $gone['node'] );
		$this->assertContains( 'Opening hours', $gone['removed'] );
	}

	public function test_a_logo_that_has_not_changed_keeps_all_its_existing_detail() {
		$node = $this->full_node();
		$node['logo'] = array( '@type' => 'ImageObject', '@id' => 'https://example.test/#logo', 'url' => 'https://example.test/logo.png', 'width' => 600, 'height' => 200, 'caption' => 'Our logo' );

		$out = $this->apply( $node, array( 'logo' => 'https://example.test/logo.png' ), array( 'replace' => true ) );

		$this->assertSame( $node['logo'], $out['node']['logo'], 'the size, id and caption are not thrown away' );
		$this->assertNotContains( 'Logo', $out['added'] );

		$changed = $this->apply( $node, array( 'logo' => 'https://example.test/new.png' ), array( 'replace' => true, 'logo_size' => array( 300, 300 ) ) );
		$this->assertSame( 'https://example.test/new.png', $changed['node']['logo']['url'] );
		$this->assertContains( 'Logo', $changed['added'] );
	}

	public function test_a_contact_point_of_the_same_kind_keeps_all_its_existing_detail() {
		$node = $this->full_node();
		$node['contactPoint'] = array( '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+44 1', 'areaServed' => 'IM', 'availableLanguage' => 'English' );

		$same = $this->apply( $node, array( 'contact_type' => 'customer service' ), array( 'replace' => true ) );
		$this->assertSame( $node['contactPoint'], $same['node']['contactPoint'] );
		$this->assertNotContains( 'Contact point', $same['added'] );

		$other = $this->apply( $node, array( 'contact_type' => 'sales' ), array( 'replace' => true ) );
		$this->assertSame( 'sales', $other['node']['contactPoint']['contactType'] );
		$this->assertContains( 'Contact point', $other['added'] );
	}

	public function test_a_changed_phone_is_carried_into_a_contact_point_that_repeated_it() {
		$node = $this->full_node();
		$node['contactPoint'] = array( '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+44 1624 000000', 'areaServed' => 'IM' );

		$out = $this->apply( $node, array( 'telephone' => '+44 7000 000000', 'contact_type' => 'customer service' ), array( 'replace' => true ) );

		$this->assertSame( '+44 7000 000000', $out['node']['contactPoint']['telephone'] );
		$this->assertSame( 'IM', $out['node']['contactPoint']['areaServed'], 'its own detail stays' );
		$this->assertContains( 'Contact point', $out['added'] );
	}

	public function test_a_contact_point_with_its_own_different_number_is_not_overwritten() {
		$node = $this->full_node();
		$node['contactPoint'] = array( '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+44 9999 999999' );

		$out = $this->apply( $node, array( 'telephone' => '+44 7000 000000', 'contact_type' => 'customer service' ), array( 'replace' => true ) );

		$this->assertSame( '+44 9999 999999', $out['node']['contactPoint']['telephone'], 'it had a different number of its own' );
	}

	public function test_several_contact_points_are_left_alone_when_the_first_type_is_unchanged() {
		$node = $this->full_node();
		$node['contactPoint'] = array(
			array( '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+44 1' ),
			array( '@type' => 'ContactPoint', 'contactType' => 'sales', 'telephone' => '+44 2' ),
		);

		$out = $this->apply( $node, array( 'contact_type' => 'customer service' ), array( 'replace' => true ) );

		$this->assertSame( $node['contactPoint'], $out['node']['contactPoint'] );
	}

	public function test_the_contact_point_is_removed_when_its_type_is_cleared() {
		$out = $this->apply( $this->full_node(), array( 'contact_type' => '' ), array( 'replace' => true ) );

		$this->assertArrayNotHasKey( 'contactPoint', $out['node'] );
		$this->assertContains( 'Contact point', $out['removed'] );
	}

	public function test_the_default_mode_still_never_removes_anything() {
		$out = $this->apply( $this->full_node(), array( 'name' => '', 'same_as' => '', 'street' => '', 'areas' => '' ) );

		$this->assertSame( $this->full_node(), $out['node'] );
		$this->assertSame( array(), $out['removed'] );
	}
}
