<?php
defined( 'ABSPATH' ) || exit;
function lcp_yeast_seo_admin_column_ids() {
	return array(
		'lcp_yeast_seo_title'       => LCP_YEAST_SEO_TITLE_META_KEY,
		'lcp_yeast_seo_description' => LCP_YEAST_SEO_DESCRIPTION_META_KEY,
	);
}
/**
 * Renders a red "N chars" badge when a value is over its recommended
 * maximum (60 for titles, 160 for descriptions); nothing otherwise.
 *
 * @param string $value Text to measure.
 * @param int    $max   Recommended maximum length.
 * @return string Badge HTML, or ''.
 */
function lcp_yeast_seo_too_long_badge( $value, $max ) {
	$length = mb_strlen( $value );

	if ( $length <= $max ) {
		return '';
	}

	/* translators: 1: character count, 2: recommended maximum. */
	$title = sprintf( __( '%1$d characters - recommended maximum is %2$d. May be cut off in search results.', 'lcp-yeast-seo' ), $length, $max );

	return ' <span class="lcp-yeast-seo-too-long" title="' . esc_attr( $title ) . '">' . esc_html( sprintf( /* translators: %d: character count. */ __( '%d - too long', 'lcp-yeast-seo' ), $length ) ) . '</span>';
}

function lcp_yeast_seo_filter_posts_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['lcp_yeast_seo_title']       = __( 'SEO Title', 'lcp-yeast-seo' );
			$new['lcp_yeast_seo_description'] = __( 'Meta Desc.', 'lcp-yeast-seo' );
			if ( lcp_yeast_seo_schema_enabled() ) {
				$new['lcp_yeast_seo_schema'] = __( 'Schema', 'lcp-yeast-seo' );
			}
		}
	}
	if ( ! isset( $new['lcp_yeast_seo_title'] ) ) {
		$new['lcp_yeast_seo_title']       = __( 'SEO Title', 'lcp-yeast-seo' );
		$new['lcp_yeast_seo_description'] = __( 'Meta Desc.', 'lcp-yeast-seo' );
		if ( lcp_yeast_seo_schema_enabled() ) {
			$new['lcp_yeast_seo_schema'] = __( 'Schema', 'lcp-yeast-seo' );
		}
	}
	return $new;
}
function lcp_yeast_seo_render_posts_custom_column( $column, $post_id ) {
	if ( 'lcp_yeast_seo_title' === $column ) {
		$custom = trim( (string) get_post_meta( $post_id, LCP_YEAST_SEO_TITLE_META_KEY, true ) );
		if ( '' !== $custom ) {
			echo esc_html( $custom ) . wp_kses_post( lcp_yeast_seo_too_long_badge( $custom, 60 ) );
		} else {
			// No custom title: the page falls back to "Post title – Site name"
			// (WordPress's default pattern), so measure that, not the bare title.
			$fallback = get_the_title( $post_id ) . ' – ' . get_bloginfo( 'name' );
			echo '<span style="color:#757575">' . esc_html( get_the_title( $post_id ) ) . '</span>' . wp_kses_post( lcp_yeast_seo_too_long_badge( $fallback, 60 ) );
		}
		$robots = get_post_meta( $post_id, LCP_YEAST_SEO_ROBOTS_META_KEY, true );
		if ( 'noindex' === $robots ) {
			echo ' <span class="lcp-yeast-seo-noindex" style="display:inline-block;margin-left:6px;padding:0 6px;border-radius:10px;background:#d63638;color:#fff;font-size:11px;line-height:1.8;vertical-align:middle;">noindex</span>';
		}
		return;
	}
	if ( 'lcp_yeast_seo_schema' === $column ) {
		echo wp_kses_post( lcp_yeast_seo_schema_column_html( $post_id ) );
		return;
	}
	if ( 'lcp_yeast_seo_description' === $column ) {
		$description = trim( (string) get_post_meta( $post_id, LCP_YEAST_SEO_DESCRIPTION_META_KEY, true ) );
		if ( '' !== $description ) {
			echo esc_html( $description ) . wp_kses_post( lcp_yeast_seo_too_long_badge( $description, 160 ) );
		} else {
			echo '<span aria-hidden="true">&mdash;</span>';
		}
		return;
	}
}
/**
 * Builds the Schema column cell: a green tick when the page outputs schema, an
 * orange "!" when it outputs more than one block (its own JSON-LD plus the
 * settings-driven Organization), and a red cross when it outputs none. The
 * tooltip says which.
 *
 * @param int $post_id Post ID.
 * @return string HTML.
 */
function lcp_yeast_seo_schema_column_html( $post_id ) {
	$sources = array();
	$notes   = array();
	$value   = trim( (string) get_post_meta( $post_id, LCP_YEAST_SEO_SCHEMA_META_KEY, true ) );
	$own_org = false;

	if ( '' !== $value ) {
		$data  = json_decode( $value, true );
		$nodes = JSON_ERROR_NONE === json_last_error() ? lcp_yeast_seo_collect_nodes( $data ) : array();

		if ( ! $nodes ) {
			$notes[] = __( 'The JSON-LD in the schema field is not valid, so it is not output.', 'lcp-yeast-seo' );
		} else {
			$types = array();
			foreach ( $nodes as $node ) {
				$types = array_merge( $types, array_map( 'strval', (array) ( $node['@type'] ?? array() ) ) );
			}
			$own_org   = (bool) array_intersect( $types, LCP_YEAST_SEO_ORGANIZATION_TYPES );
			$sources[] = sprintf( /* translators: %s: schema types. */ __( 'Custom JSON-LD (%s)', 'lcp-yeast-seo' ), implode( ', ', array_unique( $types ) ) );
		}
	}

	if ( ! $own_org && lcp_yeast_seo_org_configured() ) {
		$is_front = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post_id;

		if ( lcp_yeast_seo_org_displays_on( $is_front, 'page' === get_post_type( $post_id ) ? (int) $post_id : 0 ) ) {
			$sources[] = __( 'Organization from the Yeast SEO settings', 'lcp-yeast-seo' );
		}
	}

	if ( ! $sources ) {
		$symbol = '&#10007;';
		$colour = '#b32d2e';
		$label  = $notes ? $notes[0] : __( 'No schema', 'lcp-yeast-seo' );
	} elseif ( count( $sources ) > 1 ) {
		$symbol = '!';
		$colour = '#dba617';
		$label  = implode( ' + ', $sources );
	} else {
		$symbol = '&#10003;';
		$colour = '#007017';
		$label  = $sources[0];
	}

	return '<span class="lcp-yeast-seo-schema-status" style="color:' . esc_attr( $colour ) . '" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . $symbol . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
}

function lcp_yeast_seo_filter_sortable_columns( $columns ) {
	$columns['lcp_yeast_seo_title']       = 'lcp_yeast_seo_title';
	$columns['lcp_yeast_seo_description'] = 'lcp_yeast_seo_description';
	return $columns;
}
function lcp_yeast_seo_handle_column_orderby( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	$orderby = $query->get( 'orderby' );
	$map     = lcp_yeast_seo_admin_column_ids();
	if ( ! isset( $map[ $orderby ] ) ) {
		return;
	}
	$query->set( 'meta_key', $map[ $orderby ] );
	$query->set( 'orderby', 'meta_value' );
}
function lcp_yeast_seo_admin_column_styles() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, lcp_yeast_seo_post_types(), true ) ) {
		return;
	}
	echo '<style>.lcp-yeast-seo-too-long{display:inline-block;margin-left:6px;padding:0 6px;border-radius:10px;background:#b32d2e;color:#fff;font-size:11px;line-height:1.8;white-space:nowrap}.column-lcp_yeast_seo_title{width:22%}.column-lcp_yeast_seo_description{width:28%}.lcp-yeast-seo-schema-status{font-size:18px;font-weight:700;line-height:1}.column-lcp_yeast_seo_schema{width:6%}.column-lcp_yeast_seo_title,.column-lcp_yeast_seo_description{word-wrap:break-word;overflow-wrap:anywhere}</style>';
}
function lcp_yeast_seo_register_admin_columns() {
	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		add_filter( "manage_{$post_type}_posts_columns", 'lcp_yeast_seo_filter_posts_columns' );
		add_action( 'manage_' . $post_type . '_posts_custom_column', 'lcp_yeast_seo_render_posts_custom_column', 10, 2 );
		add_filter( "manage_edit-{$post_type}_sortable_columns", 'lcp_yeast_seo_filter_sortable_columns' );
	}
	add_action( 'pre_get_posts', 'lcp_yeast_seo_handle_column_orderby' );
	add_action( 'admin_head', 'lcp_yeast_seo_admin_column_styles' );
}
add_action( 'admin_init', 'lcp_yeast_seo_register_admin_columns' );
