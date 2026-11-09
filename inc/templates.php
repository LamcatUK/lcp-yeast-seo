<?php
/**
 * Title templates and automatic meta descriptions.
 *
 * These are defaults for pages that have no title or description of their
 * own; a value typed into a page's Yeast SEO modal is always used as is.
 * Both stand down when Yoast SEO is active, since Yoast builds its own.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_DEFAULT_TITLE_TEMPLATE', '%title% %sep% %sitename%' );
define( 'LCP_YEAST_SEO_DESCRIPTION_LENGTH', 160 );

/**
 * The configured title separator.
 *
 * @return string
 */
function lcp_yeast_seo_title_sep() {
	return (string) lcp_yeast_seo_get_setting( 'title_sep', '|' );
}

/**
 * Whether the automatic description fallback is switched on.
 *
 * @return bool
 */
function lcp_yeast_seo_auto_description_enabled() {
	return lcp_yeast_seo_flag_setting( 'auto_description', true );
}

/**
 * Fills a title template's tags, then tidies separators left dangling by an
 * empty tag (e.g. a site with no tagline).
 *
 * @param string   $template Template, e.g. "%title% %sep% %sitename%".
 * @param string[] $vars     Tag => value, without the percent signs.
 * @return string
 */
function lcp_yeast_seo_render_title_template( $template, array $vars ) {
	$map = array();

	foreach ( $vars as $tag => $value ) {
		$map[ '%' . $tag . '%' ] = (string) $value;
	}

	$title = trim( preg_replace( '/\s+/u', ' ', strtr( (string) $template, $map ) ) );
	$sep   = (string) ( $vars['sep'] ?? '' );

	if ( '' === $sep ) {
		return $title;
	}

	$quoted = preg_quote( $sep, '/' );
	$title  = preg_replace( '/\s' . $quoted . '(?:\s' . $quoted . ')+\s/u', ' ' . $sep . ' ', $title );
	$title  = preg_replace( '/^' . $quoted . '\s+|\s+' . $quoted . '$/u', '', $title );

	return trim( $title );
}

/**
 * The templated title for a post, or '' when its post type has no template.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function lcp_yeast_seo_template_title( $post_id ) {
	$templates = lcp_yeast_seo_get_setting( 'title_templates', array() );
	$template  = is_array( $templates ) ? ( $templates[ get_post_type( $post_id ) ] ?? '' ) : '';

	if ( '' === $template ) {
		return '';
	}

	return lcp_yeast_seo_render_title_template(
		$template,
		array(
			'title'    => wp_strip_all_tags( get_the_title( $post_id ) ),
			'sitename' => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'tagline'  => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'sep'      => lcp_yeast_seo_title_sep(),
		)
	);
}

/**
 * Cuts text to a length at a word boundary, adding an ellipsis when it cut.
 *
 * @param string $text Plain text.
 * @param int    $max  Maximum length including the ellipsis.
 * @return string
 */
function lcp_yeast_seo_truncate_text( $text, $max ) {
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}

	$cut   = mb_substr( $text, 0, $max - 1 );
	$space = mb_strrpos( $cut, ' ' );

	if ( false !== $space && $space > $max * 0.6 ) {
		$cut = mb_substr( $cut, 0, $space );
	}

	return rtrim( $cut, " \t,.;:-" ) . "\u{2026}";
}

/**
 * Builds a meta description from a post's excerpt, or failing that its content.
 *
 * @param string $excerpt Manual excerpt.
 * @param string $content Post content with blocks and shortcodes already stripped.
 * @param int    $max     Maximum length.
 * @return string
 */
function lcp_yeast_seo_description_from_text( $excerpt, $content, $max = LCP_YEAST_SEO_DESCRIPTION_LENGTH ) {
	foreach ( array( $excerpt, $content ) as $source ) {
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $source ), ENT_QUOTES, 'UTF-8' ) ) );

		if ( '' !== $text ) {
			return lcp_yeast_seo_truncate_text( $text, $max );
		}
	}

	return '';
}

/**
 * The automatic description for a post: its excerpt or the start of its
 * content. Content is parsed, not rendered, so no block or shortcode runs
 * from the head. Password-protected posts yield nothing rather than leak.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function lcp_yeast_seo_auto_description( $post_id ) {
	static $cache = array();

	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$cache[ $post_id ] = '';
	$post              = get_post( $post_id );

	if ( ! $post || post_password_required( $post ) ) {
		return '';
	}

	$content = strip_shortcodes( excerpt_remove_blocks( $post->post_content ) );

	$cache[ $post_id ] = lcp_yeast_seo_description_from_text( $post->post_excerpt, $content );

	return $cache[ $post_id ];
}

/**
 * The description to output for the current view: the page's own, or the
 * automatic one where that is switched on and Yoast isn't providing its own.
 *
 * @return string
 */
function lcp_yeast_seo_effective_description() {
	$description = lcp_yeast_seo_current_description();

	if ( '' !== $description || lcp_yeast_seo_yoast_active() || ! lcp_yeast_seo_auto_description_enabled() ) {
		return $description;
	}

	$post_id = lcp_yeast_seo_current_post_id();

	return $post_id ? lcp_yeast_seo_auto_description( $post_id ) : '';
}

/**
 * Applies the title template where a page has no title of its own.
 *
 * Runs after the per-post override (priority 10) and only on single content
 * that isn't the front page, whose WordPress default is already right.
 *
 * @param string $title Title so far.
 * @return string
 */
function lcp_yeast_seo_filter_template_title( $title ) {
	if ( '' !== $title || lcp_yeast_seo_yoast_active() || ! is_singular() || is_front_page() ) {
		return $title;
	}

	return lcp_yeast_seo_template_title( get_queried_object_id() );
}
add_filter( 'pre_get_document_title', 'lcp_yeast_seo_filter_template_title', 11 );
