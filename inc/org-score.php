<?php
/**
 * Scores the Organization schema for completeness and says how to improve it.
 *
 * The scoring is pure (it takes a decoded node), so it can be checked without
 * WordPress. lcp_yeast_seo_org_score_for_site() finds the node to score.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Business types that are local businesses, and so should carry local details.
 *
 * @return string[]
 */
function lcp_yeast_seo_local_business_types() {
	return array(
		'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness', 'Electrician', 'Plumber', 'GeneralContractor',
		'RoofingContractor', 'HVACBusiness', 'HousePainter', 'Locksmith', 'MovingCompany', 'LegalService', 'Attorney', 'Notary',
		'AccountingService', 'FinancialService', 'InsuranceAgency', 'RealEstateAgent', 'Dentist', 'Physician', 'MedicalBusiness',
		'MedicalClinic', 'HealthAndBeautyBusiness', 'BeautySalon', 'HairSalon', 'DaySpa', 'HealthClub', 'GymOrFitnessCenter',
		'AutomotiveBusiness', 'AutoRepair', 'AutoDealer', 'Store', 'Restaurant', 'FoodEstablishment', 'CafeOrCoffeeShop', 'Bakery',
		'BarOrPub', 'Hotel', 'LodgingBusiness', 'TravelAgency', 'ChildCare', 'EntertainmentBusiness', 'SportsActivityLocation',
		'EmergencyService', 'Library', 'AnimalShelter', 'VeterinaryCare', 'SelfStorage', 'ShoppingCenter', 'Florist', 'Photographer',
	);
}

/**
 * The @type values of a node, as a list.
 *
 * @param array $node Decoded node.
 * @return string[]
 */
function lcp_yeast_seo_org_types( array $node ) {
	return array_values( array_filter( array_map( 'strval', (array) ( $node['@type'] ?? array() ) ) ) );
}

/**
 * Whether a node is a local business.
 *
 * @param array $node Decoded node.
 * @return bool
 */
function lcp_yeast_seo_org_is_local( array $node ) {
	return (bool) array_intersect( lcp_yeast_seo_org_types( $node ), lcp_yeast_seo_local_business_types() );
}

/**
 * Follows a reference ({"@id": "..."}) to the node it names, if it is in the graph.
 *
 * @param mixed   $value Property value.
 * @param array[] $nodes All nodes the schema defines.
 * @return mixed The referenced node, or the value as it was.
 */
function lcp_yeast_seo_org_resolve( $value, array $nodes ) {
	if ( is_array( $value ) && 1 === count( $value ) && isset( $value['@id'] ) ) {
		foreach ( $nodes as $node ) {
			if ( ( $node['@id'] ?? null ) === $value['@id'] ) {
				return $node;
			}
		}
	}

	return $value;
}

/**
 * A property as a list of resolved values, dropping empties.
 *
 * @param array   $node  Node.
 * @param string  $key   Property name.
 * @param array[] $nodes All nodes (for references).
 * @return array
 */
function lcp_yeast_seo_org_list( array $node, $key, array $nodes ) {
	$value = $node[ $key ] ?? null;

	if ( null === $value || '' === $value || array() === $value ) {
		return array();
	}

	if ( ! is_array( $value ) || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		$value = array( $value );
	}

	$out = array();

	foreach ( $value as $item ) {
		$item = lcp_yeast_seo_org_resolve( $item, $nodes );

		if ( null !== $item && '' !== $item && array() !== $item ) {
			$out[] = $item;
		}
	}

	return $out;
}

/**
 * A string value of a property, '' if it is missing or not text.
 *
 * @param array   $node  Node.
 * @param string  $key   Property.
 * @param array[] $nodes All nodes.
 * @return string
 */
function lcp_yeast_seo_org_text( array $node, $key, array $nodes = array() ) {
	$first = lcp_yeast_seo_org_list( $node, $key, $nodes )[0] ?? '';

	return is_string( $first ) || is_numeric( $first ) ? trim( (string) $first ) : '';
}

/**
 * Scores an Organization node.
 *
 * Every check says what it found, how many of its points were earned, and what
 * to do about the rest. Local-business checks only count for local business types.
 *
 * @param array   $node  The Organization (or LocalBusiness) node.
 * @param array[] $nodes Every node in the schema, to resolve references; defaults to the node alone.
 * @param string  $mode  fields or raw, which changes the advice for properties the form can't set.
 * @return array{score:int,grade:string,local:bool,checks:array[],groups:array[],tips:array[]}
 */
function lcp_yeast_seo_score_organization( array $node, array $nodes = array(), $mode = 'raw' ) {
	$nodes  = $nodes ?: array( $node );
	$local  = lcp_yeast_seo_org_is_local( $node );
	// A business that serves an area and gives no street address (often run from home) is a
	// service-area business: it is not expected to publish a street address or exact position.
	$street = lcp_yeast_seo_org_list( $node, 'address', $nodes )[0] ?? null;
	$sab    = ! empty( $node['areaServed'] ) && ( ! is_array( $street ) || '' === trim( (string) ( $street['streetAddress'] ?? '' ) ) );
	$raw    = 'raw' === $mode;
	$checks = array();
	$add    = static function ( $id, $group, $label, $weight, $earned, $found, $tip = '', $example = '' ) use ( &$checks ) {
		$earned   = max( 0, min( $weight, $earned ) );
		$checks[] = array(
			'id'      => $id,
			'group'   => $group,
			'label'   => $label,
			'weight'  => $weight,
			'earned'  => $earned,
			'status'  => $earned >= $weight ? 'pass' : ( $earned > 0 ? 'partial' : 'fail' ),
			'found'   => $found,
			'tip'     => $earned >= $weight ? '' : $tip,
			'example' => $earned >= $weight ? '' : $example,
		);
	};
	$form   = static function ( $fields_tip, $raw_tip ) use ( $raw ) {
		return $raw ? $raw_tip : $fields_tip;
	};

	// Identity.
	$name = lcp_yeast_seo_org_text( $node, 'name', $nodes );
	$add( 'name', 'identity', 'Business name', 10, '' !== $name ? 10 : 0, $name, 'Add the name people know you by. It is the one property every search feature needs.' );

	$url = lcp_yeast_seo_org_text( $node, 'url', $nodes );
	$add( 'url', 'identity', 'Website address', 5, preg_match( '#^https?://#i', $url ) ? 5 : ( '' !== $url ? 2 : 0 ), $url, 'Give the full address of your site, starting with https://.' );

	// A general type is fine for an online organization; it only holds back one that has a street
	// address and a phone number, which is how a local business looks.
	$types   = lcp_yeast_seo_org_types( $node );
	$generic = ( ! $types || ! array_diff( $types, array( 'Organization', 'Corporation' ) ) ) && ! empty( $node['address'] ) && ( ! empty( $node['telephone'] ) || ! empty( $node['contactPoint'] ) );
	$add(
		'type',
		'identity',
		'Specific business type',
		5,
		$generic ? 2 : 5,
		implode( ', ', $types ),
		'"Organization" is the most general type. If customers visit you or you serve a local area, a more specific type such as LocalBusiness or ProfessionalService lets search engines treat you as a local business.',
		$form( '', '"@type": "ProfessionalService"' )
	);

	$id = (string) ( $node['@id'] ?? '' );
	$add( 'id', 'identity', 'Stable identifier (@id)', 3, preg_match( '/#organization$/', $id ) ? 3 : ( '' !== $id ? 1 : 0 ), $id, 'Use an @id that ends in #organization (for example https://example.com/#organization), so other schema on the site can point at this one.', $form( '', '"@id": "https://example.com/#organization"' ) );

	$description = lcp_yeast_seo_org_text( $node, 'description', $nodes );
	$length      = mb_strlen( $description );
	$add( 'description', 'identity', 'Description', 7, '' === $description ? 0 : ( $length >= 50 ? 7 : 3 ), $description, '' === $description ? 'Add two or three sentences on what you do and where.' : 'Your description is very short. Aim for at least 50 characters that say what you do and where.' );

	// Brand.
	$logo  = lcp_yeast_seo_org_list( $node, 'logo', $nodes )[0] ?? null;
	$earn  = 0;
	$found = '';
	$tip   = 'Add your logo. Search engines can show it beside your listing, and it is how they recognise you across the web. Google needs it at least 112 x 112 pixels; a square 512 x 512 PNG on a transparent or white background is a safe choice. A wide logo works if it is no more than about 3 times wider than tall (for example 600 x 200). Use JPG, PNG, WebP or SVG, and a logo that is readable when small.';

	if ( is_array( $logo ) ) {
		$logo_url = (string) ( $logo['url'] ?? $logo['contentUrl'] ?? '' );
		$w        = (int) ( $logo['width'] ?? 0 );
		$h        = (int) ( $logo['height'] ?? 0 );
		$found    = $logo_url . ( $w && $h ? ' (' . $w . ' x ' . $h . ')' : '' );

		if ( '' !== $logo_url && ( ( $w && $h && ( $w < 112 || $h < 112 ) ) ) ) {
			$earn = 5;
			$tip  = 'Your logo is smaller than the 112 x 112 pixels Google asks for. Use a larger image: 512 x 512 is a safe size.';
		} elseif ( '' !== $logo_url && $w && $h ) {
			$earn = 10;
		} elseif ( '' !== $logo_url ) {
			$earn = 8;
			$tip  = 'Add the logo\'s width and height so search engines know its size.';
		}
	} elseif ( is_string( $logo ) && preg_match( '#^https?://#i', $logo ) ) {
		$earn  = 6;
		$found = $logo;
		$tip   = 'The logo is just an address. Make it an ImageObject with url, width and height, which is what search engines expect.';
	}

	$add( 'logo', 'brand', 'Logo', 10, $earn, $found, $tip, $form( '', '"logo": { "@type": "ImageObject", "url": "https://example.com/logo.png", "width": 600, "height": 200 }' ) );

	$same   = array_filter( lcp_yeast_seo_org_list( $node, 'sameAs', $nodes ), static function ( $link ) {
		return is_string( $link ) && preg_match( '#^https?://#i', $link );
	} );
	$count  = count( $same );
	$add( 'sameas', 'brand', 'Social and directory profiles (sameAs)', 8, $count >= 2 ? 8 : ( $count > 0 ? 4 : 0 ), $count ? lcp_yeast_seo_count_noun( $count, 'profile' ) : '', 'Link the profiles that really are yours: Facebook, LinkedIn, Instagram, your Google Business Profile, and any business directory listing you have. There is no required number; two real ones are enough, and more adds little.' );

	if ( $local ) {
		$image = lcp_yeast_seo_org_list( $node, 'image', $nodes )[0] ?? null;
		$add( 'image', 'brand', 'Photo of the business', 3, $image ? 3 : 0, is_array( $image ) ? (string) ( $image['url'] ?? '' ) : (string) $image, 'Add a photo of your premises, van or team. Local listings use it.', $form( '', '"image": "https://example.com/premises.jpg"' ) );
	}

	// Contact.
	$points   = lcp_yeast_seo_org_list( $node, 'contactPoint', $nodes );
	$phone    = lcp_yeast_seo_org_text( $node, 'telephone', $nodes );
	$email    = lcp_yeast_seo_org_text( $node, 'email', $nodes );
	$cp_phone = '';
	$cp_email = '';

	foreach ( $points as $point ) {
		if ( is_array( $point ) ) {
			$cp_phone = $cp_phone ?: (string) ( $point['telephone'] ?? '' );
			$cp_email = $cp_email ?: (string) ( $point['email'] ?? '' );
		}
	}

	$phone_value = $phone ?: $cp_phone;
	$email_value = $email ?: $cp_email;

	$add( 'telephone', 'contact', 'Telephone', 6, '' !== $phone_value ? 6 : 0, $phone_value, 'Add the number customers should call, in international format such as +44 1624 000000.' );
	$add( 'email', 'contact', 'Email', 4, '' !== $email_value ? 4 : 0, $email_value, 'Add a public contact email address.' );
	$add( 'contactpoint', 'contact', 'Contact point', 4, $points ? ( isset( $points[0]['contactType'] ) ? 4 : 2 ) : 0, $points ? lcp_yeast_seo_count_noun( count( $points ), 'contact point' ) : '', 'Add a ContactPoint that says what the number is for (for example "customer service"). It is optional but helps search engines show the right contact.', $form( '', '"contactPoint": { "@type": "ContactPoint", "telephone": "+44 1624 000000", "contactType": "customer service" }' ) );

	$address = lcp_yeast_seo_org_list( $node, 'address', $nodes )[0] ?? null;
	$parts   = $sab ? array( 'addressCountry' ) : array( 'streetAddress', 'addressLocality', 'postalCode', 'addressCountry' );
	$have    = is_array( $address ) ? count( array_filter( $parts, static function ( $part ) use ( $address ) {
		return '' !== trim( (string) ( is_array( $address[ $part ] ?? '' ) ? ( $address[ $part ]['name'] ?? '' ) : ( $address[ $part ] ?? '' ) ) );
	} ) ) : ( is_string( $address ) && '' !== trim( $address ) ? 1 : 0 );
	$missing = is_array( $address ) ? array_values( array_diff( $parts, array_filter( $parts, static function ( $part ) use ( $address ) {
		return '' !== trim( (string) ( is_array( $address[ $part ] ?? '' ) ? ( $address[ $part ]['name'] ?? '' ) : ( $address[ $part ] ?? '' ) ) );
	} ) ) ) : $parts;
	$address_tip = $sab
		? 'Add your country (for example IM or GB). As a business that serves an area, you do not need a street address or postcode, and you should not publish a home address.'
		: ( $missing && $have ? 'The address is incomplete. Missing: ' . implode( ', ', $missing ) . '.' : 'Add your full postal address (street, town, postcode, country). It is the basis of local search results. If you work from home and travel to customers, add an area served instead and leave the street address out.' );
	$add( 'address', 'contact', $sab ? 'Address (service-area business)' : 'Postal address', 10, (int) round( 10 * $have / count( $parts ) ), is_array( $address ) ? implode( ', ', array_filter( array( $address['addressLocality'] ?? '', $address['postalCode'] ?? '', $address['addressCountry'] ?? '' ) ) ) : (string) $address, $address_tip );

	// Local details.
	if ( $local ) {
		$geo = lcp_yeast_seo_org_list( $node, 'geo', $nodes )[0] ?? null;
		if ( ! $sab ) {
		$add( 'geo', 'local', 'Map coordinates', 5, is_array( $geo ) && isset( $geo['latitude'], $geo['longitude'] ) ? 5 : 0, is_array( $geo ) ? trim( ( $geo['latitude'] ?? '' ) . ', ' . ( $geo['longitude'] ?? '' ), ', ' ) : '', 'Add your latitude and longitude so search engines place you on the map exactly.', $form( '', '"geo": { "@type": "GeoCoordinates", "latitude": 54.15, "longitude": -4.48 }' ) );
		}

		$hours = $node['openingHoursSpecification'] ?? ( $node['openingHours'] ?? null );
		$add( 'hours', 'local', 'Opening hours', 7, $hours ? 7 : 0, $hours ? 'set' : '', 'Add your opening hours. They appear in local results, and "open now" searches depend on them.', $form( '', '"openingHoursSpecification": [{ "@type": "OpeningHoursSpecification", "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday"], "opens": "09:00", "closes": "17:00" }]' ) );

		$area = lcp_yeast_seo_org_list( $node, 'areaServed', $nodes );
		$add( 'area', 'local', 'Area served', 5, $area ? 5 : 0, $area ? lcp_yeast_seo_count_noun( count( $area ), 'area' ) : '', 'Say where you work: the towns, counties or regions you cover. It matters most for businesses that travel to customers.', $form( '', '"areaServed": [{ "@type": "AdministrativeArea", "name": "Isle of Man" }]' ) );

		$price = lcp_yeast_seo_org_text( $node, 'priceRange', $nodes );
		$add( 'price', 'local', 'Price range', 3, '' !== $price ? 3 : 0, $price, 'Add a price range such as "££" if it suits your business.', $form( '', '"priceRange": "££"' ) );
	}

	// Trust.
	$add(
		'founded',
		'trust',
		'Founding date',
		2,
		'' !== lcp_yeast_seo_org_text( $node, 'foundingDate', $nodes ) ? 2 : 0,
		lcp_yeast_seo_org_text( $node, 'foundingDate', $nodes ),
		$raw ? 'Add the year you started. Put it in your Organization JSON-LD, shown below.' : 'Add the year you started in the "Founded" field under Organization schema above (a year such as 2015 is enough).',
		$form( '', '"foundingDate": "2015"' )
	);

	$possible = array_sum( array_column( $checks, 'weight' ) );
	$earned   = array_sum( array_column( $checks, 'earned' ) );
	$score    = $possible ? (int) round( 100 * $earned / $possible ) : 0;
	$labels   = array(
		'identity' => 'Identity',
		'brand'    => 'Brand',
		'contact'  => 'Contact',
		'local'    => 'Local details',
		'trust'    => 'Trust',
	);
	$groups   = array();

	foreach ( $labels as $group => $label ) {
		$mine = array_filter( $checks, static function ( $check ) use ( $group ) {
			return $check['group'] === $group;
		} );

		if ( $mine ) {
			$max             = array_sum( array_column( $mine, 'weight' ) );
			$got             = array_sum( array_column( $mine, 'earned' ) );
			$groups[ $group ] = array(
				'label' => $label,
				'score' => $max ? (int) round( 100 * $got / $max ) : 0,
			);
		}
	}

	$open = array_filter( $checks, static function ( $check ) {
		return $check['earned'] < $check['weight'];
	} );

	usort(
		$open,
		static function ( $a, $b ) {
			return ( $b['weight'] - $b['earned'] ) <=> ( $a['weight'] - $a['earned'] );
		}
	);

	return array(
		'score'  => $score,
		'grade'  => $score >= 85 ? 'Excellent' : ( $score >= 70 ? 'Good' : ( $score >= 50 ? 'Needs work' : 'Poor' ) ),
		'local'  => $local,
		'checks' => $checks,
		'groups' => $groups,
		'tips'   => array_values( $open ),
	);
}

/**
 * Finds the Organization node to score and scores it.
 *
 * @return array{state:string,result:?array,message:string,mode:string}
 *         state: ok, none (nothing configured), invalid (the pasted JSON does not parse), or no_org (no Organization in it).
 */
function lcp_yeast_seo_org_score_for_site() {
	$raw_mode = lcp_yeast_seo_org_raw_mode();
	$mode     = $raw_mode ? 'raw' : 'fields';

	if ( $raw_mode ) {
		$text = trim( (string) lcp_yeast_seo_get_setting( 'org_raw', '' ) );

		if ( '' === $text ) {
			return array( 'state' => 'none', 'result' => null, 'message' => 'No Organization schema has been added yet.', 'mode' => $mode );
		}

		json_decode( $text, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array( 'state' => 'invalid', 'result' => null, 'message' => 'The pasted JSON-LD is not valid (' . json_last_error_msg() . '), so search engines ignore it.', 'mode' => $mode );
		}

		$nodes = lcp_yeast_seo_org_raw_nodes();
	} else {
		if ( '' === lcp_yeast_seo_get_setting( 'org_name', '' ) ) {
			return array( 'state' => 'none', 'result' => null, 'message' => 'No Organization schema has been added yet. Start with the business name.', 'mode' => $mode );
		}

		$nodes = array( lcp_yeast_seo_build_organization() );
	}

	$org = null;

	foreach ( $nodes as $node ) {
		if ( array_intersect( lcp_yeast_seo_org_types( $node ), array_merge( array( 'Organization', 'Corporation' ), lcp_yeast_seo_local_business_types() ) ) ) {
			$org = $node;
			break;
		}
	}

	if ( ! $org ) {
		return array( 'state' => 'no_org', 'result' => null, 'message' => 'The JSON-LD does not contain an Organization or LocalBusiness node.', 'mode' => $mode );
	}

	return array( 'state' => 'ok', 'result' => lcp_yeast_seo_score_organization( $org, $nodes, $mode ), 'message' => '', 'mode' => $mode );
}

/**
 * Intro text for the score card.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_score_intro() {
	echo '<p>How complete your Organization schema is, and what would make it better. It is worked out from what is saved, so save your changes to see a new score.</p>';
}

/**
 * Chip modifier for a check's status.
 *
 * @param string $status pass, partial or fail.
 * @return string[] Label and chip style.
 */
function lcp_yeast_seo_org_status_chip( $status ) {
	$map = array(
		'pass'    => array( 'Good', 'ok' ),
		'partial' => array( 'Partly', 'warn' ),
		'fail'    => array( 'Missing', 'error' ),
	);

	return $map[ $status ] ?? array( $status, 'neutral' );
}

/**
 * One check: its status, what it found and what to do.
 *
 * @param array $check A check from lcp_yeast_seo_score_organization().
 * @return void
 */
function lcp_yeast_seo_render_org_check( array $check ) {
	$chip = lcp_yeast_seo_org_status_chip( $check['status'] );
	?>
	<li class="lcp-ys-audit__item">
		<span class="lcp-ys-chip lcp-ys-chip--<?php echo esc_attr( $chip[1] ); ?>"><?php echo esc_html( $chip[0] ); ?></span>
		<div class="lcp-ys-audit__body">
			<strong><?php echo esc_html( $check['label'] ); ?></strong>
			<?php if ( 'pass' !== $check['status'] ) : ?>
				<span class="lcp-ys-audit__points"><?php echo esc_html( '+' . ( $check['weight'] - $check['earned'] ) . ' points' ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $check['found'] ) : ?>
				<span class="lcp-ys-audit__found"><?php echo esc_html( mb_strimwidth( $check['found'], 0, 110, '...' ) ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $check['tip'] ) : ?>
				<p class="lcp-ys-audit__tip"><?php echo esc_html( $check['tip'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $check['example'] ) : ?>
				<details class="lcp-ys-audit__example"><summary>Show what to add</summary><code><?php echo esc_html( $check['example'] ); ?></code></details>
			<?php endif; ?>
		</div>
	</li>
	<?php
}

/**
 * The score card's body.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_score_field() {
	$scored = lcp_yeast_seo_org_score_for_site();

	if ( 'ok' !== $scored['state'] ) {
		?>
		<p class="lcp-ys-audit__none"><?php echo esc_html( $scored['message'] ); ?></p>
		<?php
		return;
	}

	$result = $scored['result'];
	$chip   = $result['score'] >= 85 ? 'ok' : ( $result['score'] >= 50 ? 'warn' : 'error' );
	$open   = array_filter( $result['checks'], static function ( $check ) {
		return 'pass' !== $check['status'];
	} );
	$done   = array_filter( $result['checks'], static function ( $check ) {
		return 'pass' === $check['status'];
	} );
	?>
	<div class="lcp-ys-score">
		<div class="lcp-ys-score__num" aria-label="<?php echo esc_attr( count( $done ) . ' of ' . count( $result['checks'] ) . ' details filled in. Score ' . $result['score'] . ' out of 100.' ); ?>"><strong><?php echo (int) count( $done ); ?></strong><span>of <?php echo (int) count( $result['checks'] ); ?> filled in</span></div>
		<div class="lcp-ys-score__detail">
			<p><span class="lcp-ys-chip lcp-ys-chip--<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $result['grade'] ); ?></span>
				<span class="lcp-ys-audit__found"><?php echo esc_html( 'Score ' . $result['score'] . ' out of 100. ' . implode( ', ', lcp_yeast_seo_org_types( lcp_yeast_seo_org_score_for_site_type() ) ) ); ?><?php echo $result['local'] ? ', scored as a local business' : ''; ?></span></p>
			<ul class="lcp-ys-bars">
				<?php foreach ( $result['groups'] as $group ) : ?>
					<li><span><?php echo esc_html( $group['label'] ); ?></span><i class="lcp-ys-bar"><b style="width:<?php echo (int) $group['score']; ?>%"></b></i><em><?php echo (int) $group['score']; ?></em></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php if ( $open ) : ?>
		<h3 class="lcp-ys-audit__title">Still missing</h3>
		<ul class="lcp-ys-audit">
			<?php
			foreach ( $result['tips'] as $check ) {
				lcp_yeast_seo_render_org_check( $check );
			}
			?>
		</ul>
	<?php else : ?>
		<p class="lcp-ys-audit__none">Nothing to improve: every check passes.</p>
	<?php endif; ?>
	<?php if ( $done ) : ?>
		<details class="lcp-ys-audit__done">
			<summary><?php echo esc_html( 'Filled in (' . count( $done ) . ')' ); ?></summary>
			<ul class="lcp-ys-audit">
				<?php
				foreach ( $done as $check ) {
					lcp_yeast_seo_render_org_check( $check );
				}
				?>
			</ul>
		</details>
	<?php endif; ?>
	<?php
}

/**
 * The Organization node that was scored, for display.
 *
 * @return array
 */
function lcp_yeast_seo_org_score_for_site_type() {
	foreach ( lcp_yeast_seo_org_raw_mode() ? lcp_yeast_seo_org_raw_nodes() : array( lcp_yeast_seo_build_organization() ) as $node ) {
		if ( array_intersect( lcp_yeast_seo_org_types( $node ), array_merge( array( 'Organization', 'Corporation' ), lcp_yeast_seo_local_business_types() ) ) ) {
			return $node;
		}
	}

	return array();
}
