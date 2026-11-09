<?php
/**
 * Editor checks: finds other content that uses the same SEO title or
 * description, for the modal to mention quietly under the field.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Other posts whose custom value for a meta key matches, ignoring case.
 *
 * @param string $meta_key Meta key.
 * @param string $value    Value to look for.
 * @param int    $exclude  Post ID to leave out (the one being edited).
 * @param int    $limit    Maximum matches to return.
 * @return array[] List of id, title, link.
 */
function lcp_yeast_seo_find_duplicates( $meta_key, $value, $exclude = 0, $limit = 5 ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return array();
	}

	$posts = get_posts(
		array(
			'post_type'    => lcp_yeast_seo_post_types(),
			'post_status'  => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'numberposts'  => $limit,
			'post__not_in' => $exclude ? array( (int) $exclude ) : array(),
			'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'   => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'      => 'ID',
			'order'        => 'ASC',
		)
	);

	return array_map(
		static function ( $post ) {
			return array(
				'id'    => $post->ID,
				'title' => wp_strip_all_tags( get_the_title( $post ) ),
				'link'  => get_edit_post_link( $post->ID, 'raw' ),
			);
		},
		$posts
	);
}

/**
 * REST callback: duplicates of a title and description.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function lcp_yeast_seo_rest_duplicates( $request ) {
	$post_id = (int) $request['post_id'];

	return array(
		'title'       => lcp_yeast_seo_find_duplicates( LCP_YEAST_SEO_TITLE_META_KEY, $request['title'], $post_id ),
		'description' => lcp_yeast_seo_find_duplicates( LCP_YEAST_SEO_DESCRIPTION_META_KEY, $request['description'], $post_id ),
	);
}

/**
 * Registers the duplicate-check route.
 *
 * @return void
 */
function lcp_yeast_seo_register_editor_routes() {
	register_rest_route(
		'lcp-yeast-seo/v1',
		'/duplicates',
		array(
			'methods'             => 'GET',
			'callback'            => 'lcp_yeast_seo_rest_duplicates',
			'permission_callback' => static function ( $request ) {
				$post_id = (int) $request['post_id'];

				return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'post_id'     => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'title'       => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'description' => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'lcp_yeast_seo_register_editor_routes' );
