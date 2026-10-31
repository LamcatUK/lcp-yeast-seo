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
 * Builds the Schema column cell: the page's own JSON-LD (valid / invalid) and
 * whether the settings-driven Organization node is output on it.
 *
 * @param int $post_id Post ID.
 * @return string HTML.
 */
function lcp_yeast_seo_schema_column_html( $post_id ) {
	$badges = array();
	$value  = trim( (string) get_post_meta( $post_id, LCP_YEAST_SEO_SCHEMA_META_KEY, true ) );
	$own_org = false;

	if ( '' !== $value ) {
		$data = json_decode( $value, true );

		if ( JSON_ERROR_NONE !== json_last_error() || array() === lcp_yeast_seo_collect_nodes( $data ) ) {
			$badges[] = array( __( 'Invalid', 'lcp-yeast-seo' ), '#b32d2e', __( 'The JSON-LD in this page\'s schema field is not valid, so nothing is output.', 'lcp-yeast-seo' ) );
		} else {
			$types = array();
			foreach ( lcp_yeast_seo_collect_nodes( $data ) as $node ) {
				$types = array_merge( $types, array_map( 'strval', (array) ( $node['@type'] ?? array() ) ) );
			}
			$own_org  = (bool) array_intersect( $types, LCP_YEAST_SEO_ORGANIZATION_TYPES );
			$badges[] = array( __( 'Custom', 'lcp-yeast-seo' ), '#2271b1', implode( ', ', array_unique( $types ) ) );
		}
	}

	if ( ! $own_org && lcp_yeast_seo_org_configured() ) {
		$is_front = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post_id;

		if ( lcp_yeast_seo_org_displays_on( $is_front, 'page' === get_post_type( $post_id ) ? (int) $post_id : 0 ) ) {
			$badges[] = array( __( 'Organization', 'lcp-yeast-seo' ), '#007017', __( 'The Organization schema from the Yeast SEO settings is output on this page.', 'lcp-yeast-seo' ) );
		}
	}

	if ( ! $badges ) {
		return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'No schema', 'lcp-yeast-seo' ) . '</span>';
	}

	$html = '';
	foreach ( $badges as $badge ) {
		$html .= '<span class="lcp-yeast-seo-schema-badge" style="background:' . esc_attr( $badge[1] ) . '" title="' . esc_attr( $badge[2] ) . '">' . esc_html( $badge[0] ) . '</span> ';
	}

	return $html;
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
	echo '<style>.lcp-yeast-seo-too-long{display:inline-block;margin-left:6px;padding:0 6px;border-radius:10px;background:#b32d2e;color:#fff;font-size:11px;line-height:1.8;white-space:nowrap}.column-lcp_yeast_seo_title{width:22%}.column-lcp_yeast_seo_description{width:28%}.lcp-yeast-seo-schema-badge{display:inline-block;margin:0 4px 2px 0;padding:0 8px;border-radius:10px;color:#fff;font-size:11px;line-height:1.8;white-space:nowrap}.column-lcp_yeast_seo_schema{width:9%}.column-lcp_yeast_seo_title,.column-lcp_yeast_seo_description{word-wrap:break-word;overflow-wrap:anywhere}</style>';
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
