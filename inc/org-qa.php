<?php
/**
 * A short question-and-answer for filling in what the Organization schema score found missing.
 *
 * The questions come from the failing checks, and the answers are merged into
 * the Organization node without overwriting anything already there.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The days of the week, as the opening-hours form names them.
 *
 * @return string[] Key => schema.org day name.
 */
function lcp_yeast_seo_org_days() {
	return array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);
}

/**
 * Business types offered in the question about the kind of business.
 *
 * @return string[] Type => label.
 */
function lcp_yeast_seo_org_type_options() {
	return array(
		'LocalBusiness'               => 'Local business (general)',
		'ProfessionalService'         => 'Professional service',
		'HomeAndConstructionBusiness' => 'Home and construction',
		'Electrician'                 => 'Electrician',
		'Plumber'                     => 'Plumber',
		'GeneralContractor'           => 'General contractor',
		'LegalService'                => 'Legal service',
		'AccountingService'           => 'Accountant',
		'Dentist'                     => 'Dentist',
		'HealthAndBeautyBusiness'     => 'Health and beauty',
		'GymOrFitnessCenter'          => 'Gym or fitness',
		'AutomotiveBusiness'          => 'Motor trade',
		'Store'                       => 'Shop',
		'Restaurant'                  => 'Restaurant',
	);
}

/**
 * Which questions to ask, from the checks that did not pass.
 *
 * @param array $result From lcp_yeast_seo_score_organization().
 * @param array $node   The Organization node (to see which address parts exist).
 * @return array[] Each: id, title, ask, missing (for the address, the parts to ask for).
 */
function lcp_yeast_seo_org_questions( array $result, array $node ) {
	$open = array();

	foreach ( $result['checks'] as $check ) {
		if ( 'pass' !== $check['status'] ) {
			$open[ $check['id'] ] = $check;
		}
	}

	$defs = array(
		'name'         => array( 'Business name', 'What do people call your business?' ),
		'type'         => array( 'Kind of business', 'Which of these is closest to what you do?' ),
		'description'  => array( 'Description', 'In two or three sentences, what do you do and where?' ),
		'logo'         => array( 'Logo', 'Choose your logo from the media library. A square or wide image at least 112 pixels each way works best.' ),
		'image'        => array( 'Photo', 'Choose a photo of your premises, van or team.' ),
		'sameas'       => array( 'Profiles', 'Where else are you online? Paste the address of each profile, one per line: Facebook, LinkedIn, Instagram, Google Business Profile.' ),
		'telephone'    => array( 'Telephone', 'What number should customers call?' ),
		'email'        => array( 'Email', 'What public email address do you use?' ),
		'contactpoint' => array( 'Contact point', 'What is that number for?' ),
		'address'      => array( 'Address', 'Where are you based?' ),
		'geo'          => array( 'Map position', 'Where are you on the map? In Google Maps, right-click your location and click the two numbers to copy them.' ),
		'hours'        => array( 'Opening hours', 'When are you open?' ),
		'area'         => array( 'Area served', 'Which towns, counties or regions do you cover? One per line.' ),
		'price'        => array( 'Price range', 'Roughly how expensive are you?' ),
		'legalname'    => array( 'Legal name', 'Is your registered company name different from the name you trade under?' ),
		'founded'      => array( 'Founded', 'What year did you start?' ),
	);
	$out  = array();

	foreach ( $defs as $id => $def ) {
		if ( ! isset( $open[ $id ] ) ) {
			continue;
		}

		$question = array(
			'id'      => $id,
			'title'   => $def[0],
			'ask'     => $def[1],
			'missing' => array(),
		);

		if ( 'address' === $id ) {
			$address = is_array( $node['address'] ?? null ) ? $node['address'] : array();

			foreach ( array( 'streetAddress', 'addressLocality', 'postalCode', 'addressCountry' ) as $part ) {
				if ( '' === trim( (string) ( $address[ $part ] ?? '' ) ) ) {
					$question['missing'][] = $part;
				}
			}
		}

		$out[] = $question;
	}

	return $out;
}

/**
 * Opening hours from the form, as schema.org specifications. Days with the same hours share one.
 *
 * @param array $hours Day key => open (flag), opens, closes.
 * @return array[]
 */
function lcp_yeast_seo_org_hours_spec( array $hours ) {
	$groups = array();

	foreach ( lcp_yeast_seo_org_days() as $key => $day ) {
		$row = $hours[ $key ] ?? array();

		if ( empty( $row['open'] ) || ! preg_match( '/^\d{2}:\d{2}$/', (string) ( $row['opens'] ?? '' ) ) || ! preg_match( '/^\d{2}:\d{2}$/', (string) ( $row['closes'] ?? '' ) ) || $row['closes'] <= $row['opens'] ) {
			continue;
		}

		$groups[ $row['opens'] . '-' . $row['closes'] ]['days'][] = $day;
		$groups[ $row['opens'] . '-' . $row['closes'] ]['opens']  = $row['opens'];
		$groups[ $row['opens'] . '-' . $row['closes'] ]['closes'] = $row['closes'];
	}

	return array_values(
		array_map(
			static function ( $group ) {
				return array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $group['days'],
					'opens'     => $group['opens'],
					'closes'    => $group['closes'],
				);
			},
			$groups
		)
	);
}

/**
 * Merges the answers into an Organization node, without overwriting what is there.
 *
 * Text answers fill blanks; profiles, areas and address parts are added to; the
 * logo, photo, opening hours and business type replace, because they were asked
 * for when missing or wrong. Answers that don't look right (a bad latitude, an
 * address that isn't a web address, times that don't make sense) are left out.
 *
 * @param array $node    The Organization node.
 * @param array $answers Answers keyed by field name.
 * @param array $context home_url, id, and optionally logo_size (width, height).
 * @return array{node:array,added:string[]}
 */
function lcp_yeast_seo_org_apply_answers( array $node, array $answers, array $context ) {
	$added = array();
	$text  = static function ( $key ) use ( $answers ) {
		return trim( (string) ( $answers[ $key ] ?? '' ) );
	};
	$fill  = static function ( $property, $value, $label ) use ( &$node, &$added ) {
		if ( '' !== $value && '' === trim( (string) ( is_array( $node[ $property ] ?? '' ) ? '' : ( $node[ $property ] ?? '' ) ) ) ) {
			$node[ $property ] = $value;
			$added[]           = $label;
		}
	};
	$url   = static function ( $value ) {
		return preg_match( '#^https?://[^\s]+$#i', $value ) ? $value : '';
	};

	if ( '' === trim( (string) ( $node['@id'] ?? '' ) ) || ! preg_match( '/#organization$/', (string) $node['@id'] ) ) {
		$node['@id'] = $context['id'];
		$added[]     = 'Identifier';
	}

	if ( '' === trim( (string) ( $node['url'] ?? '' ) ) ) {
		$node['url'] = $context['home_url'];
		$added[]     = 'Website address';
	}

	$fill( 'name', $text( 'name' ), 'Business name' );
	$fill( 'description', $text( 'description' ), 'Description' );
	$fill( 'telephone', $text( 'telephone' ), 'Telephone' );
	$fill( 'email', preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $text( 'email' ) ) ? $text( 'email' ) : '', 'Email' );
	$fill( 'legalName', $text( 'legal_name' ), 'Legal name' );
	$fill( 'priceRange', $text( 'price_range' ), 'Price range' );
	$fill( 'foundingDate', preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $text( 'founded' ) ) ? $text( 'founded' ) : '', 'Founding date' );

	if ( isset( lcp_yeast_seo_org_type_options()[ $text( 'type' ) ] ) ) {
		$node['@type'] = $text( 'type' );
		$added[]       = 'Business type';
	}

	if ( '' !== $url( $text( 'logo' ) ) ) {
		$logo = array(
			'@type' => 'ImageObject',
			'url'   => $text( 'logo' ),
		);

		if ( ! empty( $context['logo_size'][0] ) && ! empty( $context['logo_size'][1] ) ) {
			$logo['width']  = (int) $context['logo_size'][0];
			$logo['height'] = (int) $context['logo_size'][1];
		}

		$node['logo'] = $logo;
		$added[]      = 'Logo';
	}

	if ( '' !== $url( $text( 'image' ) ) ) {
		$node['image'] = $text( 'image' );
		$added[]       = 'Photo';
	}

	$links = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) ( $answers['same_as'] ?? '' ) ) ), static function ( $link ) use ( $url ) {
		return '' !== $url( $link );
	} ) );

	if ( $links ) {
		$existing       = array_values( array_filter( (array) ( $node['sameAs'] ?? array() ), 'is_string' ) );
		$merged         = array_values( array_unique( array_merge( $existing, $links ) ) );
		$node['sameAs'] = $merged;

		if ( count( $merged ) > count( $existing ) ) {
			$added[] = 'Profiles';
		}
	}

	$address = is_array( $node['address'] ?? null ) ? $node['address'] : array();
	$parts   = array(
		'streetAddress'   => $text( 'street' ),
		'addressLocality' => $text( 'locality' ),
		'addressRegion'   => $text( 'region' ),
		'postalCode'      => $text( 'postcode' ),
		'addressCountry'  => $text( 'country' ),
	);
	$before  = $address;

	foreach ( $parts as $part => $value ) {
		if ( '' !== $value && '' === trim( (string) ( is_array( $address[ $part ] ?? '' ) ? '' : ( $address[ $part ] ?? '' ) ) ) ) {
			$address[ $part ] = $value;
		}
	}

	if ( $address !== $before ) {
		$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
		$added[]         = 'Address';
	}

	$latitude  = $text( 'latitude' );
	$longitude = $text( 'longitude' );

	if ( is_numeric( $latitude ) && is_numeric( $longitude ) && abs( (float) $latitude ) <= 90 && abs( (float) $longitude ) <= 180 ) {
		$node['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $latitude,
			'longitude' => (float) $longitude,
		);
		$added[]     = 'Map position';
	}

	if ( ! empty( $answers['add_hours'] ) ) {
		$spec = lcp_yeast_seo_org_hours_spec( (array) ( $answers['hours'] ?? array() ) );

		if ( $spec ) {
			$node['openingHoursSpecification'] = $spec;
			$added[]                           = 'Opening hours';
		}
	}

	$areas = array_values( array_filter( array_map( 'trim', preg_split( '/\R|,/', (string) ( $answers['areas'] ?? '' ) ) ) ) );

	if ( $areas ) {
		$known  = array();

		foreach ( (array) ( $node['areaServed'] ?? array() ) as $existing_area ) {
			$known[] = is_array( $existing_area ) ? (string) ( $existing_area['name'] ?? '' ) : (string) $existing_area;
		}

		$list = isset( $node['areaServed'] ) && is_array( $node['areaServed'] ) && array_keys( $node['areaServed'] ) === range( 0, count( $node['areaServed'] ) - 1 ) ? $node['areaServed'] : ( isset( $node['areaServed'] ) ? array( $node['areaServed'] ) : array() );
		$new    = 0;

		foreach ( $areas as $area ) {
			if ( ! in_array( $area, $known, true ) ) {
				$list[]  = array(
					'@type' => 'AdministrativeArea',
					'name'  => $area,
				);
				$known[] = $area;
				++$new;
			}
		}

		if ( $new ) {
			$node['areaServed'] = $list;
			$added[]            = 'Area served';
		}
	}

	$contact_type = $text( 'contact_type' );

	if ( '' !== $contact_type && empty( $node['contactPoint'] ) ) {
		$point = array_filter(
			array(
				'@type'       => 'ContactPoint',
				'contactType' => $contact_type,
				'telephone'   => (string) ( $node['telephone'] ?? '' ),
				'email'       => (string) ( $node['email'] ?? '' ),
			),
			'strlen'
		);

		if ( isset( $point['telephone'] ) || isset( $point['email'] ) ) {
			$node['contactPoint'] = $point;
			$added[]              = 'Contact point';
		}
	}

	return array(
		'node'  => $node,
		'added' => array_values( array_unique( $added ) ),
	);
}
