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
 * Reads opening hours back out of an Organization node, in the shape the form uses.
 *
 * @param array $node Organization node.
 * @return array Day key => open, opens, closes.
 */
function lcp_yeast_seo_org_hours_from_node( array $node ) {
	$days  = array_flip( lcp_yeast_seo_org_days() );
	$hours = array();
	$specs = $node['openingHoursSpecification'] ?? array();
	$specs = is_array( $specs ) && isset( $specs['@type'] ) ? array( $specs ) : (array) $specs;

	foreach ( $specs as $spec ) {
		if ( ! is_array( $spec ) ) {
			continue;
		}

		foreach ( (array) ( $spec['dayOfWeek'] ?? array() ) as $day ) {
			$name = basename( (string) $day );

			if ( isset( $days[ $name ] ) ) {
				$hours[ $days[ $name ] ] = array(
					'open'   => '1',
					'opens'  => substr( (string) ( $spec['opens'] ?? '' ), 0, 5 ),
					'closes' => substr( (string) ( $spec['closes'] ?? '' ), 0, 5 ),
				);
			}
		}
	}

	return $hours;
}

/**
 * Every wizard answer as the Organization node currently has it, to prefill the form.
 *
 * @param array $node Organization node.
 * @return array Answers keyed as lcp_yeast_seo_org_apply_answers() reads them.
 */
function lcp_yeast_seo_org_prefill( array $node ) {
	$text    = static function ( $key ) use ( $node ) {
		return is_scalar( $node[ $key ] ?? null ) ? trim( (string) $node[ $key ] ) : '';
	};
	$address = is_array( $node['address'] ?? null ) ? $node['address'] : array();
	$geo     = is_array( $node['geo'] ?? null ) ? $node['geo'] : array();
	$logo    = $node['logo'] ?? '';
	$areas   = array();
	$point   = is_array( $node['contactPoint'] ?? null ) ? ( isset( $node['contactPoint']['@type'] ) ? $node['contactPoint'] : (array) reset( $node['contactPoint'] ) ) : array();

	foreach ( (array) ( $node['areaServed'] ?? array() ) as $area ) {
		$areas[] = is_array( $area ) ? (string) ( $area['name'] ?? '' ) : (string) $area;
	}

	$types = lcp_yeast_seo_org_types( $node );

	return array(
		'name'         => $text( 'name' ),
		'type'         => isset( lcp_yeast_seo_org_type_options()[ $types[0] ?? '' ] ) ? $types[0] : '',
		'description'  => $text( 'description' ),
		'legal_name'   => $text( 'legalName' ),
		'founded'      => $text( 'foundingDate' ),
		'logo'         => is_array( $logo ) ? (string) ( $logo['url'] ?? '' ) : (string) $logo,
		'image'        => is_array( $node['image'] ?? null ) ? (string) ( $node['image']['url'] ?? '' ) : $text( 'image' ),
		'same_as'      => implode( "\n", array_filter( (array) ( $node['sameAs'] ?? array() ), 'is_string' ) ),
		'telephone'    => $text( 'telephone' ),
		'email'        => $text( 'email' ),
		'contact_type' => (string) ( $point['contactType'] ?? '' ),
		'street'       => (string) ( $address['streetAddress'] ?? '' ),
		'locality'     => (string) ( $address['addressLocality'] ?? '' ),
		'region'       => (string) ( $address['addressRegion'] ?? '' ),
		'postcode'     => (string) ( $address['postalCode'] ?? '' ),
		'country'      => (string) ( $address['addressCountry'] ?? '' ),
		'latitude'     => isset( $geo['latitude'] ) ? (string) $geo['latitude'] : '',
		'longitude'    => isset( $geo['longitude'] ) ? (string) $geo['longitude'] : '',
		'areas'        => implode( "\n", array_filter( $areas ) ),
		'price_range'  => $text( 'priceRange' ),
		'hours'        => lcp_yeast_seo_org_hours_from_node( $node ),
	);
}

/**
 * Merges the answers into an Organization node.
 *
 * By default (the "fill the gaps" mode) nothing already there is overwritten:
 * text answers fill blanks; profiles, areas and address parts are added to; the
 * logo, photo, opening hours and business type replace. In replace mode (the
 * wizard, whose form is prefilled from the node) every answer that is present
 * replaces what is there, and a blank one removes it. Answers that don't look
 * right (a bad latitude, a profile that isn't a web address, times that don't
 * make sense) are left out in both.
 *
 * @param array $node    The Organization node.
 * @param array $answers Answers keyed by field name.
 * @param array $context home_url, id, optionally logo_size (width, height), and replace (bool).
 * @return array{node:array,added:string[],removed:string[]}
 */
function lcp_yeast_seo_org_apply_answers( array $node, array $answers, array $context ) {
	$replace = ! empty( $context['replace'] );
	$orig    = $node;
	$added   = array();
	$removed = array();
	$has     = static function ( $key ) use ( $answers ) {
		return array_key_exists( $key, $answers );
	};
	$text    = static function ( $key ) use ( $answers ) {
		return trim( (string) ( $answers[ $key ] ?? '' ) );
	};
	$url     = static function ( $value ) {
		return preg_match( '#^https?://[^\s]+$#i', $value ) ? $value : '';
	};
	$current = static function ( $property ) use ( &$node ) {
		return is_array( $node[ $property ] ?? '' ) ? '' : trim( (string) ( $node[ $property ] ?? '' ) );
	};
	// Sets (or, in replace mode, clears) one plain property from an answer.
	$put     = static function ( $property, $answer, $value, $label ) use ( &$node, &$added, &$removed, $replace, $has, $text, $current ) {
		if ( $replace ) {
			if ( ! $has( $answer ) ) {
				return;
			}

			if ( '' === $value && '' !== $text( $answer ) ) {
				return; // Present but invalid: keep what is there.
			}
		}

		if ( '' === $value ) {
			if ( $replace && isset( $node[ $property ] ) ) {
				unset( $node[ $property ] );
				$removed[] = $label;
			}

			return;
		}

		if ( $replace ? $current( $property ) !== $value : '' === $current( $property ) ) {
			$node[ $property ] = $value;
			$added[]           = $label;
		}
	};
	$valid   = static function ( $answer, $pattern ) use ( $text ) {
		return preg_match( $pattern, $text( $answer ) ) ? $text( $answer ) : '';
	};

	if ( '' === trim( (string) ( $node['@id'] ?? '' ) ) || ! preg_match( '/#organization$/', (string) $node['@id'] ) ) {
		$node['@id'] = $context['id'];
		$added[]     = 'Identifier';
	}

	if ( '' === trim( (string) ( $node['url'] ?? '' ) ) ) {
		$node['url'] = $context['home_url'];
		$added[]     = 'Website address';
	}

	// Plain properties. A blank answer clears only in replace mode, and an invalid one never does.
	$simple = array(
		array( 'name', 'name', 'Business name', null ),
		array( 'description', 'description', 'Description', null ),
		array( 'telephone', 'telephone', 'Telephone', null ),
		array( 'email', 'email', 'Email', '/^[^@\s]+@[^@\s]+\.[^@\s]+$/' ),
		array( 'legalName', 'legal_name', 'Legal name', null ),
		array( 'priceRange', 'price_range', 'Price range', null ),
		array( 'foundingDate', 'founded', 'Founding date', '/^\d{4}(-\d{2}(-\d{2})?)?$/' ),
	);

	foreach ( $simple as $row ) {
		list( $property, $answer, $label, $pattern ) = $row;

		$put( $property, $answer, $pattern ? $valid( $answer, $pattern ) : $text( $answer ), $label );
	}

	if ( isset( lcp_yeast_seo_org_type_options()[ $text( 'type' ) ] ) ) {
		if ( ( lcp_yeast_seo_org_types( $node )[0] ?? '' ) !== $text( 'type' ) ) {
			$node['@type'] = $text( 'type' );
			$added[]       = 'Business type';
		}
	}

	$logo_now = is_array( $node['logo'] ?? null ) ? (string) ( $node['logo']['url'] ?? '' ) : (string) ( $node['logo'] ?? '' );

	if ( $replace && '' !== $text( 'logo' ) && $text( 'logo' ) === $logo_now ) {
		// The same logo: leave it exactly as it is, with whatever detail it already carries.
		$logo_now = $logo_now;
	} elseif ( '' !== $url( $text( 'logo' ) ) ) {
		$logo = array(
			'@type' => 'ImageObject',
			'url'   => $text( 'logo' ),
		);

		if ( ! empty( $context['logo_size'][0] ) && ! empty( $context['logo_size'][1] ) ) {
			$logo['width']  = (int) $context['logo_size'][0];
			$logo['height'] = (int) $context['logo_size'][1];
		}

		if ( ( $node['logo'] ?? null ) !== $logo ) {
			$node['logo'] = $logo;
			$added[]      = 'Logo';
		}
	} elseif ( $replace && $has( 'logo' ) && '' === $text( 'logo' ) && isset( $node['logo'] ) ) {
		unset( $node['logo'] );
		$removed[] = 'Logo';
	}

	if ( '' !== $url( $text( 'image' ) ) ) {
		if ( ( $node['image'] ?? null ) !== $text( 'image' ) ) {
			$node['image'] = $text( 'image' );
			$added[]       = 'Photo';
		}
	} elseif ( $replace && $has( 'image' ) && '' === $text( 'image' ) && isset( $node['image'] ) ) {
		unset( $node['image'] );
		$removed[] = 'Photo';
	}

	$links = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/\R/', (string) ( $answers['same_as'] ?? '' ) ) ), static function ( $link ) use ( $url ) {
		return '' !== $url( $link );
	} ) ) );

	if ( $replace && $has( 'same_as' ) ) {
		$existing = array_values( array_filter( (array) ( $node['sameAs'] ?? array() ), 'is_string' ) );

		if ( $links !== $existing ) {
			if ( $links ) {
				$node['sameAs'] = $links;
				$added[]        = 'Profiles';
			} elseif ( isset( $node['sameAs'] ) ) {
				unset( $node['sameAs'] );
				$removed[] = 'Profiles';
			}
		}
	} elseif ( $links ) {
		$existing       = array_values( array_filter( (array) ( $node['sameAs'] ?? array() ), 'is_string' ) );
		$merged         = array_values( array_unique( array_merge( $existing, $links ) ) );
		$node['sameAs'] = $merged;

		if ( count( $merged ) > count( $existing ) ) {
			$added[] = 'Profiles';
		}
	}

	$address = is_array( $node['address'] ?? null ) ? $node['address'] : array();
	$before  = $address;
	$parts   = array(
		'streetAddress'   => 'street',
		'addressLocality' => 'locality',
		'addressRegion'   => 'region',
		'postalCode'      => 'postcode',
		'addressCountry'  => 'country',
	);

	foreach ( $parts as $part => $answer ) {
		$value = $text( $answer );

		if ( $replace && $has( $answer ) ) {
			if ( '' === $value ) {
				unset( $address[ $part ] );
			} else {
				$address[ $part ] = $value;
			}
		} elseif ( ! $replace && '' !== $value && '' === trim( (string) ( is_array( $address[ $part ] ?? '' ) ? '' : ( $address[ $part ] ?? '' ) ) ) ) {
			$address[ $part ] = $value;
		}
	}

	unset( $address['@type'], $before['@type'] );

	if ( $address !== $before ) {
		if ( $address ) {
			$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
			$added[]         = 'Address';
		} else {
			unset( $node['address'] );
			$removed[] = 'Address';
		}
	}

	$latitude  = $text( 'latitude' );
	$longitude = $text( 'longitude' );

	if ( is_numeric( $latitude ) && is_numeric( $longitude ) && abs( (float) $latitude ) <= 90 && abs( (float) $longitude ) <= 180 ) {
		$geo = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $latitude,
			'longitude' => (float) $longitude,
		);

		if ( ( $node['geo'] ?? null ) !== $geo ) {
			$node['geo'] = $geo;
			$added[]     = 'Map position';
		}
	} elseif ( $replace && $has( 'latitude' ) && $has( 'longitude' ) && '' === $latitude && '' === $longitude && isset( $node['geo'] ) ) {
		unset( $node['geo'] );
		$removed[] = 'Map position';
	}

	$hours_mode = $answers['hours_mode'] ?? ( ! empty( $answers['add_hours'] ) ? 'set' : 'keep' );

	if ( 'set' === $hours_mode ) {
		$spec = lcp_yeast_seo_org_hours_spec( (array) ( $answers['hours'] ?? array() ) );

		if ( $spec && ( $node['openingHoursSpecification'] ?? null ) !== $spec ) {
			$node['openingHoursSpecification'] = $spec;
			$added[]                           = 'Opening hours';
		}
	} elseif ( 'remove' === $hours_mode && isset( $node['openingHoursSpecification'] ) ) {
		unset( $node['openingHoursSpecification'] );
		$removed[] = 'Opening hours';
	}

	$areas = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/\R|,/', (string) ( $answers['areas'] ?? '' ) ) ) ) ) );
	$known = array();

	foreach ( (array) ( $node['areaServed'] ?? array() ) as $existing_area ) {
		$known[] = is_array( $existing_area ) ? (string) ( $existing_area['name'] ?? '' ) : (string) $existing_area;
	}

	if ( $replace && $has( 'areas' ) ) {
		if ( $areas !== $known ) {
			if ( $areas ) {
				$node['areaServed'] = array_map(
					static function ( $area ) {
						return array(
							'@type' => 'AdministrativeArea',
							'name'  => $area,
						);
					},
					$areas
				);
				$added[]            = 'Area served';
			} elseif ( isset( $node['areaServed'] ) ) {
				unset( $node['areaServed'] );
				$removed[] = 'Area served';
			}
		}
	} elseif ( $areas ) {
		$list = isset( $node['areaServed'] ) && is_array( $node['areaServed'] ) && array_keys( $node['areaServed'] ) === range( 0, count( $node['areaServed'] ) - 1 ) ? $node['areaServed'] : ( isset( $node['areaServed'] ) ? array( $node['areaServed'] ) : array() );
		$new  = 0;

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

	$point_now = is_array( $node['contactPoint'] ?? null ) ? ( isset( $node['contactPoint']['@type'] ) ? $node['contactPoint'] : (array) reset( $node['contactPoint'] ) ) : array();

	if ( $replace && '' !== $contact_type && $contact_type === (string) ( $point_now['contactType'] ?? '' ) ) {
		// The same kind of contact point: keep its own details, but follow a changed phone or email
		// when it was only repeating the business's.
		if ( isset( $node['contactPoint']['@type'] ) ) {
			$updated = $node['contactPoint'];

			foreach ( array( 'telephone', 'email' ) as $field ) {
				if ( isset( $updated[ $field ] ) && ( $orig[ $field ] ?? '' ) === $updated[ $field ] && ( $node[ $field ] ?? '' ) !== $updated[ $field ] ) {
					if ( '' === ( $node[ $field ] ?? '' ) ) {
						unset( $updated[ $field ] );
					} else {
						$updated[ $field ] = $node[ $field ];
					}
				}
			}

			if ( $updated !== $node['contactPoint'] ) {
				$node['contactPoint'] = $updated;
				$added[]              = 'Contact point';
			}
		}
	} elseif ( '' !== $contact_type && ( $replace || empty( $node['contactPoint'] ) ) ) {
		$point = array_filter(
			array(
				'@type'       => 'ContactPoint',
				'contactType' => $contact_type,
				'telephone'   => (string) ( $node['telephone'] ?? '' ),
				'email'       => (string) ( $node['email'] ?? '' ),
			),
			'strlen'
		);

		if ( ( isset( $point['telephone'] ) || isset( $point['email'] ) ) && ( $node['contactPoint'] ?? null ) !== $point ) {
			$node['contactPoint'] = $point;
			$added[]              = 'Contact point';
		}
	} elseif ( $replace && $has( 'contact_type' ) && '' === $contact_type && isset( $node['contactPoint'] ) ) {
		unset( $node['contactPoint'] );
		$removed[] = 'Contact point';
	}

	return array(
		'node'    => $node,
		'added'   => array_values( array_unique( $added ) ),
		'removed' => array_values( array_unique( $removed ) ),
	);
}
