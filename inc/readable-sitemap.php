<?php
/**
 * Readable copies of the sitemap, for tools that cannot read XML.
 *
 * /sitemap.txt   Every URL in the sitemap, one per line, as text/plain.
 * /sitemap.json  The same pages as application/json: a summary of the site's content types and
 *                taxonomies, then the pages themselves. Add ?type=song for one content type and
 *                &full=1 for whole records (plain-text content, excerpt, dates, terms, registered
 *                custom fields, featured image). Long lists carry a "next" address.
 *
 * Off until switched on, and separate from the XML sitemap, which is not changed. It lists exactly
 * the pages the XML sitemap lists, and leaves out anything password protected.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_FEED_LIST_PAGE', 500 );
define( 'LCP_YEAST_SEO_FEED_FULL_PAGE', 25 );
define( 'LCP_YEAST_SEO_FEED_CACHE', 300 );
define( 'LCP_YEAST_SEO_FEED_TEXT_CAP', 50000 );

/**
 * Whether the readable copies are switched on.
 *
 * @return bool
 */
function lcp_yeast_seo_readable_sitemaps_enabled() {
	return lcp_yeast_seo_sitemaps_enabled() && lcp_yeast_seo_flag_setting( 'readable_sitemaps', false );
}

/**
 * Which readable file a request path is, if any.
 *
 * @param string $path Request path.
 * @param string $base The site's path, '/' or a sub-folder.
 * @return string "txt", "json" or "".
 */
function lcp_yeast_seo_feed_route( $path, $base ) {
	$base = trailingslashit( $base );

	if ( $base . 'sitemap.txt' === $path ) {
		return 'txt';
	}

	return $base . 'sitemap.json' === $path ? 'json' : '';
}

/**
 * The request's options, cleaned: a content type, a page number, and whether whole records are wanted.
 *
 * @param array $get Query string values.
 * @return array{type:string,page:int,full:bool}
 */
function lcp_yeast_seo_feed_args( array $get ) {
	return array(
		'type' => isset( $get['type'] ) && is_string( $get['type'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $get['type'] ) ) : '',
		'page' => max( 1, isset( $get['page'] ) ? (int) $get['page'] : 1 ),
		'full' => ! empty( $get['full'] ) && '0' !== $get['full'],
	);
}

/**
 * One page of a list, with where it sits in the whole.
 *
 * @param array $items    Everything.
 * @param int   $page     Page number, from 1.
 * @param int   $per_page Items per page.
 * @return array{items:array,page:int,pages:int,total:int,has_next:bool}
 */
function lcp_yeast_seo_feed_paginate( array $items, $page, $per_page ) {
	$total = count( $items );
	$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
	$page  = min( max( 1, (int) $page ), $pages );

	return array(
		'items'    => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
		'page'     => $page,
		'pages'    => $pages,
		'total'    => $total,
		'has_next' => $page < $pages,
	);
}

/**
 * An address on the JSON feed, built from its options. Defaults are left out.
 *
 * @param string $base The feed's address.
 * @param array  $args type, page, full.
 * @return string
 */
function lcp_yeast_seo_feed_link( $base, array $args ) {
	$query = array();

	if ( ! empty( $args['type'] ) ) {
		$query['type'] = $args['type'];
	}

	if ( ! empty( $args['full'] ) ) {
		$query['full'] = '1';
	}

	if ( ! empty( $args['page'] ) && (int) $args['page'] > 1 ) {
		$query['page'] = (int) $args['page'];
	}

	return $query ? $base . '?' . http_build_query( $query ) : $base;
}

/**
 * The text file's body: one address per line.
 *
 * @param string[] $urls Addresses.
 * @return string
 */
function lcp_yeast_seo_feed_text_body( array $urls ) {
	return $urls ? implode( "\n", array_slice( $urls, 0, LCP_YEAST_SEO_FEED_TEXT_CAP ) ) . "\n" : '';
}

/**
 * Readable text from HTML: tags removed, entities decoded, paragraphs and headings kept on their own lines.
 *
 * @param string $html HTML.
 * @return string
 */
function lcp_yeast_seo_feed_plain_text( $html ) {
	$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html );
	$html = preg_replace( '#<\s*br\s*/?>#i', "\n", $html );
	$html = preg_replace( '#</\s*(p|div|h[1-6]|li|tr|blockquote|section|figure|figcaption|ul|ol|table)\s*>#i', "\n\n", $html );
	$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
	$text = preg_replace( "/ *\n */", "\n", $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );

	return trim( $text );
}

/**
 * Whether a stored value is worth keeping when the feed lists fields: everything but nothing at all.
 *
 * @param mixed $value Value.
 * @return mixed A value that encodes cleanly as JSON.
 */
function lcp_yeast_seo_feed_clean_value( $value ) {
	if ( is_object( $value ) ) {
		$value = (array) $value;
	}

	if ( is_array( $value ) ) {
		return array_map( 'lcp_yeast_seo_feed_clean_value', $value );
	}

	return is_scalar( $value ) || null === $value ? $value : (string) wp_json_encode( $value );
}

/**
 * The published pages of one content type that the XML sitemap lists, minus password-protected ones.
 *
 * @param string $post_type Post type.
 * @return int[]
 */
function lcp_yeast_seo_feed_ids( $post_type ) {
	return array_values(
		array_filter(
			lcp_yeast_seo_sitemap_post_ids( $post_type ),
			static function ( $post_id ) {
				return '' === (string) get_post_field( 'post_password', $post_id );
			}
		)
	);
}

/**
 * Every listed page as [type, id], one content type after another.
 *
 * @param string $only Limit to one content type, or ''.
 * @return array[]
 */
function lcp_yeast_seo_feed_pairs( $only = '' ) {
	$pairs = array();

	foreach ( lcp_yeast_seo_sitemap_post_types() as $post_type ) {
		if ( '' !== $only && $post_type !== $only ) {
			continue;
		}

		foreach ( lcp_yeast_seo_feed_ids( $post_type ) as $post_id ) {
			$pairs[] = array( $post_type, $post_id );
		}
	}

	return $pairs;
}

/**
 * The public taxonomy terms a page has, grouped by taxonomy.
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @return array
 */
function lcp_yeast_seo_feed_terms( $post_id, $post_type ) {
	$out = array();

	foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
		if ( empty( $taxonomy->public ) ) {
			continue;
		}

		$terms = get_the_terms( $post_id, $taxonomy->name );

		if ( ! $terms || is_wp_error( $terms ) ) {
			continue;
		}

		$out[ $taxonomy->name ] = array_map(
			static function ( $term ) {
				return array(
					'id'   => (int) $term->term_id,
					'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'slug' => $term->slug,
				);
			},
			array_values( $terms )
		);
	}

	return $out;
}

/**
 * Whether a registered custom field belongs in a record's `meta`: exposed to the REST API (so already public),
 * not protected, and not one of this plugin's own SEO fields, which are summarised under `seo`.
 *
 * @param string $key  Field name.
 * @param array  $args Registration arguments.
 * @return bool
 */
function lcp_yeast_seo_feed_meta_key_is_public( $key, array $args ) {
	return ! empty( $args['show_in_rest'] ) && '_' !== substr( $key, 0, 1 ) && 0 !== strpos( $key, 'lcp_yeast_seo_' );
}

/**
 * The custom fields a content type has registered for the REST API (so already public), by name.
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @return array
 */
function lcp_yeast_seo_feed_meta( $post_id, $post_type ) {
	$out = array();

	foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
		if ( ! lcp_yeast_seo_feed_meta_key_is_public( $key, (array) $args ) ) {
			continue;
		}

		$out[ $key ] = lcp_yeast_seo_feed_clean_value( get_post_meta( $post_id, $key, ! empty( $args['single'] ) ) );
	}

	return $out;
}

/**
 * A page's featured image, with its description and caption.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function lcp_yeast_seo_feed_image( $post_id ) {
	$image_id = (int) get_post_thumbnail_id( $post_id );

	if ( ! $image_id ) {
		return null;
	}

	$meta = wp_get_attachment_metadata( $image_id );

	return array(
		'id'      => $image_id,
		'url'     => (string) wp_get_attachment_url( $image_id ),
		'alt'     => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
		'caption' => wp_strip_all_tags( (string) wp_get_attachment_caption( $image_id ) ),
		'width'   => (int) ( $meta['width'] ?? 0 ),
		'height'  => (int) ( $meta['height'] ?? 0 ),
	);
}

/**
 * A page in short form: where it is and what it is called.
 *
 * @param string $post_type Post type.
 * @param int    $post_id   Post ID.
 * @return array
 */
function lcp_yeast_seo_feed_light_item( $post_type, $post_id ) {
	return array(
		'id'       => (int) $post_id,
		'type'     => $post_type,
		'slug'     => (string) get_post_field( 'post_name', $post_id ),
		'url'      => (string) get_permalink( $post_id ),
		'title'    => html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ),
		'modified' => (string) get_post_modified_time( DATE_W3C, true, $post_id ),
	);
}

/**
 * A page in full: its text, dates, terms, registered custom fields and featured image.
 *
 * @param string $post_type Post type.
 * @param int    $post_id   Post ID.
 * @return array
 */
function lcp_yeast_seo_feed_full_item( $post_type, $post_id ) {
	$post = get_post( $post_id );

	return lcp_yeast_seo_feed_light_item( $post_type, $post_id ) + array(
		'published'      => (string) get_post_time( DATE_W3C, true, $post_id ),
		'parent'         => (int) $post->post_parent,
		'excerpt'        => lcp_yeast_seo_feed_plain_text( get_the_excerpt( $post ) ),
		'content'        => lcp_yeast_seo_feed_plain_text( apply_filters( 'the_content', $post->post_content ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, to get the text a visitor sees.
		'seo'            => array(
			'title'       => (string) get_post_meta( $post_id, LCP_YEAST_SEO_TITLE_META_KEY, true ),
			'description' => (string) get_post_meta( $post_id, LCP_YEAST_SEO_DESCRIPTION_META_KEY, true ),
		),
		'terms'          => lcp_yeast_seo_feed_terms( $post_id, $post_type ),
		'meta'           => lcp_yeast_seo_feed_meta( $post_id, $post_type ),
		'featured_image' => lcp_yeast_seo_feed_image( $post_id ),
	);
}

/**
 * The site's content types with how many pages each has and where to read them.
 *
 * @param string $base The feed's address.
 * @return array[]
 */
function lcp_yeast_seo_feed_type_summary( $base ) {
	$out = array();

	foreach ( lcp_yeast_seo_sitemap_post_types() as $post_type ) {
		$object = get_post_type_object( $post_type );
		$count  = count( lcp_yeast_seo_feed_ids( $post_type ) );

		if ( ! $object || ! $count ) {
			continue;
		}

		$out[] = array(
			'type'  => $post_type,
			'label' => $object->labels->name,
			'count' => $count,
			'list'  => lcp_yeast_seo_feed_link( $base, array( 'type' => $post_type ) ),
			'full'  => lcp_yeast_seo_feed_link( $base, array( 'type' => $post_type, 'full' => true ) ),
		);
	}

	return $out;
}

/**
 * The public taxonomies used by the listed content types, with their terms.
 *
 * @return array[]
 */
function lcp_yeast_seo_feed_taxonomy_summary() {
	$out = array();

	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
		if ( 'post_format' === $taxonomy->name || ! array_intersect( (array) $taxonomy->object_type, lcp_yeast_seo_sitemap_post_types() ) ) {
			continue;
		}

		$terms = get_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => true, 'number' => 500 ) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			continue;
		}

		$out[] = array(
			'taxonomy'      => $taxonomy->name,
			'label'         => $taxonomy->labels->name,
			'used_by'       => array_values( array_intersect( (array) $taxonomy->object_type, lcp_yeast_seo_sitemap_post_types() ) ),
			'hierarchical'  => (bool) $taxonomy->hierarchical,
			'terms'         => array_map(
				static function ( $term ) {
					return array(
						'id'     => (int) $term->term_id,
						'name'   => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'slug'   => $term->slug,
						'parent' => (int) $term->parent,
						'count'  => (int) $term->count,
					);
				},
				array_values( $terms )
			),
		);
	}

	return $out;
}

/**
 * Builds the JSON document for a request.
 *
 * @param array $args type, page, full.
 * @return array
 */
function lcp_yeast_seo_feed_document( array $args ) {
	$base  = home_url( '/sitemap.json' );
	$pairs = lcp_yeast_seo_feed_pairs( $args['type'] );
	$per   = $args['full'] ? LCP_YEAST_SEO_FEED_FULL_PAGE : LCP_YEAST_SEO_FEED_LIST_PAGE;
	$page  = lcp_yeast_seo_feed_paginate( $pairs, $args['page'], $per );
	$items = array();

	foreach ( $page['items'] as $pair ) {
		$items[] = $args['full'] ? lcp_yeast_seo_feed_full_item( $pair[0], $pair[1] ) : lcp_yeast_seo_feed_light_item( $pair[0], $pair[1] );
	}

	$document = array(
		'site'      => array(
			'name'        => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'url'       => home_url( '/' ),
			'description' => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
			'language'  => get_bloginfo( 'language' ),
		),
		'generated' => gmdate( DATE_W3C ),
		'note'      => 'The same pages as the XML sitemap, as JSON. Add ?type=<type> for one content type and &full=1 for whole records. Follow "next" for more.',
	);

	if ( 1 === $page['page'] && '' === $args['type'] && ! $args['full'] ) {
		$document['types']      = lcp_yeast_seo_feed_type_summary( $base );
		$document['taxonomies'] = lcp_yeast_seo_feed_taxonomy_summary();
	}

	return $document + array(
		'page'  => $page['page'],
		'pages' => $page['pages'],
		'total' => $page['total'],
		'next'  => $page['has_next'] ? lcp_yeast_seo_feed_link( $base, array( 'type' => $args['type'], 'full' => $args['full'], 'page' => $page['page'] + 1 ) ) : null,
		'items' => $items,
	);
}

/**
 * Every listed address, for the text file.
 *
 * @return string[]
 */
function lcp_yeast_seo_feed_all_urls() {
	$urls = array();

	foreach ( lcp_yeast_seo_feed_pairs() as $pair ) {
		$urls[] = (string) get_permalink( $pair[1] );
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/**
 * Sends a readable file and stops.
 *
 * @param string $body         Body.
 * @param string $content_type Content type.
 * @return void
 */
function lcp_yeast_seo_feed_send( $body, $content_type ) {
	status_header( 200 );
	header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
	header( 'Cache-Control: public, max-age=' . LCP_YEAST_SEO_FEED_CACHE );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Access-Control-Allow-Origin: *' );

	// Not sent by default: some tools will not read a response marked noindex, and these files are only lists of public pages.
	if ( apply_filters( 'lcp_yeast_seo_feed_noindex', false ) ) {
		header( 'X-Robots-Tag: noindex' );
	}

	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text or JSON with a fixed content type and nosniff.
	exit;
}

/**
 * Serves /sitemap.txt and /sitemap.json. Anything else, or the feature off, is left to WordPress.
 *
 * @return void
 */
function lcp_yeast_seo_feed_serve() {
	if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) || ! lcp_yeast_seo_readable_sitemaps_enabled() ) {
		return;
	}

	$path  = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with fixed paths.
	$route = lcp_yeast_seo_feed_route( $path, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

	if ( '' === $route ) {
		return;
	}

	$args  = lcp_yeast_seo_feed_args( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only list; the options are cleaned above.
	$key   = 'lcp_yeast_seo_feed_' . md5( $route . wp_json_encode( $args ) . (string) get_lastpostmodified( 'gmt', 'any' ) );
	$cache = get_transient( $key );

	if ( is_string( $cache ) ) {
		lcp_yeast_seo_feed_send( $cache, 'txt' === $route ? 'text/plain' : 'application/json' );
	}

	if ( 'txt' === $route ) {
		$body = lcp_yeast_seo_feed_text_body( lcp_yeast_seo_feed_all_urls() );
	} else {
		$body = (string) wp_json_encode( lcp_yeast_seo_feed_document( $args ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	set_transient( $key, $body, LCP_YEAST_SEO_FEED_CACHE );

	lcp_yeast_seo_feed_send( $body, 'txt' === $route ? 'text/plain' : 'application/json' );
}
// After init has registered the content types and taxonomies, and before WordPress runs its query.
add_action( 'wp_loaded', 'lcp_yeast_seo_feed_serve' );

/**
 * The settings field: the tick, and where the files are once it is on.
 *
 * @return void
 */
function lcp_yeast_seo_render_readable_sitemaps_field() {
	$option = esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION );
	?>
	<label>
		<input type="hidden" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[readable_sitemaps]" value="0">
		<input type="checkbox" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[readable_sitemaps]" value="1" <?php checked( lcp_yeast_seo_flag_setting( 'readable_sitemaps', false ) ); ?>>
		Also publish the sitemap as plain text and JSON, for tools that cannot read XML
	</label>
	<p class="description">The XML sitemap is not changed. These list the same pages and leave out anything password protected.</p>
	<?php if ( lcp_yeast_seo_readable_sitemaps_enabled() ) : ?>
		<p class="description">
			<a href="<?php echo esc_url( home_url( '/sitemap.txt' ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( preg_replace( '#^https?://#', '', home_url( '/sitemap.txt' ) ) ); ?></code></a>:
			every address, one per line.
		</p>
		<p class="description">
			<a href="<?php echo esc_url( home_url( '/sitemap.json' ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( preg_replace( '#^https?://#', '', home_url( '/sitemap.json' ) ) ); ?></code></a>:
			the content types, taxonomies and pages. Add <code>?type=post&amp;full=1</code> (use any content type) for whole records with their text, dates, terms and custom fields.
		</p>
	<?php endif; ?>
	<?php
}
