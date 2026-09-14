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
define( 'LCP_YEAST_SEO_ORGANIZATION_TYPES', array( 'Organization' ) );

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
					'%s is an %s, so its @id must end in "#organization" (e.g. "%s") — otherwise Yoast\'s publisher reference will not resolve to it.',
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

		$overlaps = array_merge( $overlaps, array_intersect( $types, LCP_YEAST_SEO_YOAST_OWNED_TYPES ) );

		if ( array_intersect( $types, LCP_YEAST_SEO_ORGANIZATION_TYPES ) && ! empty( $node['@id'] ) ) {
			$id_host   = wp_parse_url( (string) $node['@id'], PHP_URL_HOST );
			$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

			if ( $id_host && $site_host && $id_host !== $site_host ) {
				$messages[] = sprintf(
					'the Organization @id points at %1$s, but this site is %2$s. That is expected on local or staging; on production it must be %3$s or Yoast\'s publisher reference will not resolve to it.',
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
	return lcp_yeast_seo_declares_type( LCP_YEAST_SEO_ORGANIZATION_TYPES ) ? false : $needed;
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
