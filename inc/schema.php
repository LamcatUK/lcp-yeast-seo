<?php
/**
 * Per-post JSON-LD schema markup.
 *
 * Provides a post-meta field on every public post type for hand-authored
 * JSON-LD, checks it for problems after save, and emits it in wp_head after
 * Yoast's own graph.
 *
 * Yoast retains ownership of WebPage, WebSite and BreadcrumbList. Its
 * Organization node is stood down only on pages where this field supplies a
 * replacement, so that its `publisher`/`about` @id references always resolve.
 *
 * Ported from cb-hts2026's inc/cb-schema.php. That version stored the field
 * in ACF and used ACF's `acf/validate_value` filter to block an invalid save
 * outright. There is no equivalent hook for post meta edited through the
 * block editor's REST-backed meta box API — `sanitize_callback` can
 * transform a value but not reject it. So here, ALL validation (both what
 * used to be a hard block, and the source's already-separate non-blocking
 * "you're duplicating a Yoast type" warning) is unified into one
 * non-blocking admin notice shown after save. This is a deliberate
 * behaviour change from the source, not an oversight — flagged here rather
 * than silently narrowed.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Types Yoast still emits, which this field should not duplicate.
 */
define( 'LCP_YEAST_SEO_YOAST_OWNED_TYPES', array( 'WebPage', 'WebSite', 'BreadcrumbList' ) );

/**
 * Types that must carry the site's canonical Organization @id so that
 * Yoast's cross-references resolve. Organization subtypes (LocalBusiness,
 * Corporation) can be added here.
 */
define( 'LCP_YEAST_SEO_ORGANIZATION_TYPES', array( 'Organization', 'Corporation', 'LocalBusiness', 'ProfessionalService' ) );

/**
 * Returns the canonical @id an Organization node must use.
 *
 * Matches Yoast's own Organization node @id convention, so its `publisher`
 * and `about` references resolve to our node instead of its own.
 *
 * @return string
 */
function lcp_yeast_seo_organization_id() {
	return home_url( '/' ) . '#organization';
}

/**
 * Flattens a decoded JSON-LD payload into a list of entity nodes.
 *
 * Handles a single object, a top-level array of objects, and an @graph
 * wrapper.
 *
 * @param mixed $data Decoded JSON.
 * @return array List of nodes that are arrays.
 */
function lcp_yeast_seo_collect_nodes( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
		$candidates = $data['@graph'];
	} elseif ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
		// Sequential keys: a top-level array of entities.
		$candidates = $data;
	} else {
		$candidates = array( $data );
	}

	return array_values( array_filter( $candidates, 'is_array' ) );
}

/**
 * Checks a decoded schema value for the same problems the source's blocking
 * validator checked for. Returns a list of human-readable problem
 * descriptions — empty when the value is fine (or blank).
 *
 * @param string $value Raw field value.
 * @return string[]
 */
function lcp_yeast_seo_validate_schema_value( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';

	if ( '' === $value ) {
		return array();
	}

	$data = json_decode( $value, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return array( 'Invalid JSON: ' . json_last_error_msg() . '.' );
	}

	if ( ! is_array( $data ) ) {
		return array( 'Schema must be a JSON object, or an array of objects — not a single value.' );
	}

	$nodes = lcp_yeast_seo_collect_nodes( $data );

	if ( empty( $nodes ) ) {
		return array( 'No schema entities found. Expected at least one object with an @type.' );
	}

	$problems               = array();
	$has_top_level_context = isset( $data['@context'] );

	foreach ( $nodes as $position => $node ) {
		$label = count( $nodes ) > 1 ? sprintf( 'Entity %d', $position + 1 ) : 'Schema';

		if ( ! $has_top_level_context && ! isset( $node['@context'] ) ) {
			$problems[] = sprintf( '%s is missing @context. Add "@context": "https://schema.org".', $label );
			continue;
		}

		$context = isset( $node['@context'] ) ? $node['@context'] : $data['@context'];

		if ( is_string( $context ) && ! preg_match( '#^https?://schema\.org/?$#', trim( $context ) ) ) {
			$problems[] = sprintf( '%s has an unexpected @context. It should be "https://schema.org".', $label );
		}

		if ( empty( $node['@type'] ) ) {
			$problems[] = sprintf( '%s is missing @type.', $label );
			continue;
		}

		$types    = array_map( 'strval', (array) $node['@type'] );
		$org_type = array_intersect( $types, LCP_YEAST_SEO_ORGANIZATION_TYPES );

		if ( ! empty( $org_type ) ) {
			// Only the fragment is enforced. The host deliberately is not:
			// the same JSON has to save on local, staging and production,
			// where home_url() differs. A host mismatch is raised as its
			// own notice below instead.
			$fragment = empty( $node['@id'] ) ? '' : (string) wp_parse_url( (string) $node['@id'], PHP_URL_FRAGMENT );

			if ( 'organization' !== $fragment ) {
				$problems[] = sprintf(
					lcp_yeast_seo_yoast_active()
						? '%s is an %s, so its @id must end in "#organization" (e.g. "%s") — otherwise Yoast\'s publisher reference will not resolve to it.'
						: '%s is an %s, so its @id should end in "#organization" (e.g. "%s") so other schema on the site can reference it.',
					$label,
					reset( $org_type ),
					lcp_yeast_seo_organization_id()
				);
			}
		}
	}

	return $problems;
}

/**
 * Checks for overlap with types Yoast already emits, and for an Organization
 * @id pointing at a different host than this site. Non-blocking in the
 * source too — overriding these is occasionally legitimate.
 *
 * @param array $nodes Decoded, flattened schema nodes.
 * @return string[]
 */
function lcp_yeast_seo_check_overlap( $nodes ) {
	$overlaps = array();
	$messages = array();

	foreach ( $nodes as $node ) {
		if ( empty( $node['@type'] ) ) {
			continue;
		}

		$types = array_map( 'strval', (array) $node['@type'] );

		if ( lcp_yeast_seo_yoast_active() ) {
			$overlaps = array_merge( $overlaps, array_intersect( $types, LCP_YEAST_SEO_YOAST_OWNED_TYPES ) );
		}

		if ( array_intersect( $types, LCP_YEAST_SEO_ORGANIZATION_TYPES ) && ! empty( $node['@id'] ) ) {
			$id_host   = wp_parse_url( (string) $node['@id'], PHP_URL_HOST );
			$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

			if ( $id_host && $site_host && $id_host !== $site_host ) {
				$messages[] = sprintf(
					'the Organization @id points at %1$s, but this site is %2$s. That is expected on local or staging; on production it must be %3$s or references to it will not resolve.',
					$id_host,
					$site_host,
					lcp_yeast_seo_organization_id()
				);
			}
		}
	}

	if ( ! empty( $overlaps ) ) {
		$messages[] = sprintf(
			'it declares %s, which Yoast already outputs. Duplicate entities can conflict unless they share the same @id.',
			implode( ', ', array_unique( $overlaps ) )
		);
	}

	return $messages;
}

/**
 * Runs validation + overlap checks after the schema field is saved, and
 * queues up an admin notice if anything looks wrong. Hooked on both
 * `added_post_meta` and `updated_post_meta` — the field can hit either
 * depending on whether it already had a value.
 *
 * @param int    $meta_id  Meta row ID — unused, required by the hook signature.
 * @param int    $post_id  Post ID the meta belongs to.
 * @param string $meta_key Meta key being written.
 * @param mixed  $value    New meta value.
 * @return void
 */
function lcp_yeast_seo_check_meta_on_save( $meta_id, $post_id, $meta_key, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	if ( LCP_YEAST_SEO_SCHEMA_META_KEY !== $meta_key ) {
		return;
	}

	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return;
	}

	$messages = lcp_yeast_seo_validate_schema_value( $value );
	$data     = json_decode( $value, true );

	if ( JSON_ERROR_NONE === json_last_error() ) {
		$messages = array_merge( $messages, lcp_yeast_seo_check_overlap( lcp_yeast_seo_collect_nodes( $data ) ) );
	}

	if ( empty( $messages ) ) {
		return;
	}

	set_transient( 'lcp_yeast_seo_notice_' . get_current_user_id(), array_values( array_unique( $messages ) ), 60 );
}
add_action( 'added_post_meta', 'lcp_yeast_seo_check_meta_on_save', 10, 4 );
add_action( 'updated_post_meta', 'lcp_yeast_seo_check_meta_on_save', 10, 4 );

/**
 * Renders the notice queued by lcp_yeast_seo_check_meta_on_save().
 *
 * @return void
 */
function lcp_yeast_seo_admin_notice() {
	$key      = 'lcp_yeast_seo_notice_' . get_current_user_id();
	$messages = get_transient( $key );

	if ( empty( $messages ) || ! is_array( $messages ) ) {
		return;
	}

	delete_transient( $key );

	foreach ( $messages as $message ) {
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>Schema markup:</strong> %s</p></div>',
			esc_html( $message )
		);
	}
}
add_action( 'admin_notices', 'lcp_yeast_seo_admin_notice' );

/**
 * Returns the decoded schema nodes for the current singular view.
 *
 * @return array List of entity nodes, empty when absent, malformed, or the
 *               schema module is disabled.
 */
function lcp_yeast_seo_current_nodes() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();

	if ( ! lcp_yeast_seo_schema_enabled() || ! is_singular() ) {
		return $cache;
	}

	$value = get_post_meta( get_queried_object_id(), LCP_YEAST_SEO_SCHEMA_META_KEY, true );

	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return $cache;
	}

	$data = json_decode( $value, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return $cache;
	}

	$cache = lcp_yeast_seo_collect_nodes( $data );

	return $cache;
}

/**
 * Whether the current page's schema field declares one of the given @types.
 *
 * @param array $types Schema.org type names.
 * @return bool
 */
function lcp_yeast_seo_declares_type( array $types ) {
	foreach ( lcp_yeast_seo_current_nodes() as $node ) {
		if ( empty( $node['@type'] ) ) {
			continue;
		}

		if ( array_intersect( array_map( 'strval', (array) $node['@type'] ), $types ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Stands down Yoast's Organization node only where this field replaces it.
 *
 * Elsewhere Yoast's node remains as the target for its own `publisher` and
 * `about` @id references.
 *
 * @param bool $needed Whether Yoast intends to output the piece.
 * @return bool
 */
function lcp_yeast_seo_maybe_disable_yoast_organization( $needed ) {
	if ( lcp_yeast_seo_declares_type( LCP_YEAST_SEO_ORGANIZATION_TYPES ) || lcp_yeast_seo_org_should_output() ) {
		return false;
	}

	return $needed;
}
add_filter( 'wpseo_schema_needs_organization', 'lcp_yeast_seo_maybe_disable_yoast_organization' );

/**
 * Outputs the page's JSON-LD in wp_head, after Yoast's graph.
 *
 * The stored string is decoded and re-encoded rather than echoed, so the
 * output is always well-formed and JSON_HEX_TAG makes a </script> breakout
 * impossible regardless of what's in the stored string.
 *
 * @return void
 */
function lcp_yeast_seo_render() {
	$nodes = lcp_yeast_seo_current_nodes();

	if ( empty( $nodes ) ) {
		return;
	}

	if ( 1 === count( $nodes ) ) {
		$payload = $nodes[0];

		if ( ! isset( $payload['@context'] ) ) {
			$payload = array( '@context' => 'https://schema.org' ) + $payload;
		}
	} else {
		// Multiple entities: hoist the context and wrap in an @graph, so the
		// result stays a valid JSON-LD document rather than a bare array.
		$graph = array();

		foreach ( $nodes as $node ) {
			unset( $node['@context'] );
			$graph[] = $node;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );

	if ( false === $json ) {
		return;
	}

	echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'lcp_yeast_seo_render', 20 );

/**
 * Whether the settings-driven Organization node has enough to output.
 *
 * @return bool
 */
function lcp_yeast_seo_org_configured() {
	if ( ! lcp_yeast_seo_schema_enabled() ) {
		return false;
	}

	return lcp_yeast_seo_org_raw_mode() ? array() !== lcp_yeast_seo_org_raw_nodes() : '' !== lcp_yeast_seo_get_setting( 'org_name', '' );
}

/**
 * Whether the "Display on" rules put the Organization node on a page.
 *
 * @param bool $is_front Whether it is the front page.
 * @param int  $page_id  Page ID, 0 when not a page.
 * @return bool
 */
function lcp_yeast_seo_org_displays_on( $is_front, $page_id ) {
	if ( '1' === (string) lcp_yeast_seo_get_setting( 'org_show_all', '0' ) ) {
		return true;
	}

	if ( $is_front && lcp_yeast_seo_org_show_front() ) {
		return true;
	}

	$ids   = array_map( 'absint', (array) lcp_yeast_seo_get_setting( 'org_extra_pages', array() ) );
	$ids[] = absint( lcp_yeast_seo_get_setting( 'org_contact_page', 0 ) );

	return $page_id && in_array( $page_id, array_filter( $ids ), true );
}

/**
 * Whether the settings-driven Organization node belongs on the current page.
 *
 * A page whose own schema field declares an Organization keeps that one instead.
 *
 * @return bool
 */
function lcp_yeast_seo_org_should_output() {
	if ( is_admin() || ! lcp_yeast_seo_org_configured() || lcp_yeast_seo_declares_type( LCP_YEAST_SEO_ORGANIZATION_TYPES ) ) {
		return false;
	}

	return lcp_yeast_seo_org_displays_on( is_front_page(), is_singular( 'page' ) ? get_queried_object_id() : 0 );
}

/**
 * Whether the Organization schema comes from the raw JSON-LD textarea.
 *
 * @return bool
 */
function lcp_yeast_seo_org_raw_mode() {
	return 'raw' === lcp_yeast_seo_get_setting( 'org_mode', 'fields' );
}

/**
 * Decoded entity nodes from the raw JSON-LD textarea; empty when blank or invalid.
 *
 * @return array
 */
function lcp_yeast_seo_org_raw_nodes() {
	$raw = trim( (string) lcp_yeast_seo_get_setting( 'org_raw', '' ) );

	if ( '' === $raw ) {
		return array();
	}

	$data = json_decode( $raw, true );

	return JSON_ERROR_NONE === json_last_error() ? lcp_yeast_seo_collect_nodes( $data ) : array();
}

/**
 * Wraps nodes as one JSON-LD document: a single node, or a shared-context @graph.
 *
 * @param array $nodes Entity nodes.
 * @return array
 */
function lcp_yeast_seo_nodes_to_payload( array $nodes ) {
	if ( 1 === count( $nodes ) ) {
		return isset( $nodes[0]['@context'] ) ? $nodes[0] : array( '@context' => 'https://schema.org' ) + $nodes[0];
	}

	foreach ( $nodes as &$node ) {
		unset( $node['@context'] );
	}

	return array(
		'@context' => 'https://schema.org',
		'@graph'   => $nodes,
	);
}

/**
 * Builds the Organization node from the settings, omitting empty properties.
 *
 * @return array
 */
function lcp_yeast_seo_build_organization() {
	$get  = 'lcp_yeast_seo_get_setting';
	$type = $get( 'org_type', 'Organization' );

	$node = array(
		'@context' => 'https://schema.org',
		'@type'    => isset( lcp_yeast_seo_organization_type_choices()[ $type ] ) ? $type : 'Organization',
		'@id'      => lcp_yeast_seo_organization_id(),
		'name'     => $get( 'org_name' ),
		'url'      => $get( 'org_url', home_url( '/' ) ),
	);

	$simple = array(
		'legalName'   => 'org_legal_name',
		'description' => 'org_description',
		'email'       => 'org_email',
		'telephone'   => 'org_phone',
		'foundingDate' => 'org_founded',
	);

	foreach ( $simple as $property => $key ) {
		$value = $get( $key );
		if ( '' !== $value ) {
			$node[ $property ] = $value;
		}
	}

	$logo_id = absint( $get( 'org_logo', 0 ) );
	$logo    = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;

	if ( $logo ) {
		$node['logo'] = array(
			'@type'  => 'ImageObject',
			'url'    => $logo[0],
			'width'  => (int) $logo[1],
			'height' => (int) $logo[2],
		);
	}

	$address = array_filter(
		array(
			'streetAddress'   => $get( 'org_street' ),
			'addressLocality' => $get( 'org_locality' ),
			'addressRegion'   => $get( 'org_region' ),
			'postalCode'      => $get( 'org_postcode' ),
			'addressCountry'  => $get( 'org_country' ),
		),
		'strlen'
	);

	if ( $address ) {
		$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
	}

	$same_as = array_filter( preg_split( '/\R/', (string) $get( 'org_same_as' ) ) );

	if ( $same_as ) {
		$node['sameAs'] = array_values( $same_as );
	}

	return $node;
}

/**
 * Outputs the settings-driven Organization JSON-LD in wp_head.
 *
 * @return void
 */
function lcp_yeast_seo_render_organization() {
	if ( ! lcp_yeast_seo_org_should_output() ) {
		return;
	}

	$payload = lcp_yeast_seo_org_raw_mode() ? lcp_yeast_seo_nodes_to_payload( lcp_yeast_seo_org_raw_nodes() ) : lcp_yeast_seo_build_organization();
	$json    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );

	if ( false === $json ) {
		return;
	}

	echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'lcp_yeast_seo_render_organization', 19 );
