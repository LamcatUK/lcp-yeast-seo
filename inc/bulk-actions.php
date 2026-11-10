<?php
/**
 * Bulk "set to index / noindex" on the post list tables.
 *
 * Writes the same per-page value as the Robots field in the Yeast SEO modal,
 * so the sitemaps, the robots meta tag and the Health scan all follow it.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The robots value a bulk action sets.
 *
 * @param string $action Bulk action name.
 * @return string index, noindex, or '' for an action that isn't ours.
 */
function lcp_yeast_seo_bulk_robots_value( $action ) {
	$map = array(
		'lcp_yeast_seo_index'   => 'index',
		'lcp_yeast_seo_noindex' => 'noindex',
	);

	return $map[ $action ] ?? '';
}

/**
 * Adds the two actions to a list table's Bulk actions menu.
 *
 * @param array $actions Existing actions.
 * @return array
 */
function lcp_yeast_seo_add_bulk_actions( $actions ) {
	$actions['lcp_yeast_seo_noindex'] = 'Yeast SEO: set to noindex';
	$actions['lcp_yeast_seo_index']   = 'Yeast SEO: set to index';

	return $actions;
}

/**
 * Applies a bulk action, then redirects back with the counts.
 *
 * WordPress has already checked the bulk-action nonce; each post is still
 * checked against the user's capability to edit it.
 *
 * @param string $redirect Redirect URL.
 * @param string $action   Bulk action name.
 * @param array  $post_ids Selected post IDs.
 * @return string
 */
function lcp_yeast_seo_handle_bulk_action( $redirect, $action, $post_ids ) {
	$value = lcp_yeast_seo_bulk_robots_value( $action );

	if ( '' === $value ) {
		return $redirect;
	}

	$changed = 0;
	$same    = 0;
	$denied  = 0;

	foreach ( array_filter( array_map( 'absint', (array) $post_ids ) ) as $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			++$denied;
			continue;
		}

		$current = 'noindex' === get_post_meta( $post_id, LCP_YEAST_SEO_ROBOTS_META_KEY, true ) ? 'noindex' : 'index';

		if ( $current === $value ) {
			++$same;
			continue;
		}

		update_post_meta( $post_id, LCP_YEAST_SEO_ROBOTS_META_KEY, $value );
		++$changed;
	}

	return add_query_arg(
		array(
			'lcp_yeast_seo_bulk'    => $value,
			'lcp_yeast_seo_changed' => $changed,
			'lcp_yeast_seo_same'    => $same,
			'lcp_yeast_seo_denied'  => $denied,
		),
		$redirect
	);
}

/**
 * The result message for a finished bulk action.
 *
 * @param string $value       index or noindex.
 * @param int    $changed     Posts changed.
 * @param int    $same        Posts that already had that value.
 * @param int    $denied      Posts the user can't edit.
 * @param bool   $site_hidden Whether the whole site is set to discourage search engines.
 * @return array{0:string,1:string} Notice class (success or warning) and text.
 */
function lcp_yeast_seo_bulk_notice( $value, $changed, $same, $denied, $site_hidden = false ) {
	$parts = array();

	if ( $changed ) {
		$parts[] = lcp_yeast_seo_count_noun( $changed, 'item' ) . ' set to ' . $value . '.';
	}

	if ( $same ) {
		$parts[] = lcp_yeast_seo_count_noun( $same, 'item' ) . ( 1 === (int) $same ? ' was' : ' were' ) . ' already ' . $value . '.';
	}

	if ( $denied ) {
		$parts[] = lcp_yeast_seo_count_noun( $denied, 'item' ) . ' skipped because you can not edit ' . ( 1 === (int) $denied ? 'it' : 'them' ) . '.';
	}

	if ( ! $parts ) {
		$parts[] = 'No items were selected.';
	}

	if ( 'index' === $value && $changed && $site_hidden ) {
		$parts[] = 'The site is set to discourage search engines (Settings, Reading), so every page still outputs noindex until that is changed.';
	}

	return array( $denied || ( ! $changed && ! $same ) ? 'warning' : 'success', implode( ' ', $parts ) );
}

/**
 * Shows the result of a bulk action on the list table it came from.
 *
 * @return void
 */
function lcp_yeast_seo_bulk_admin_notice() {
	$value = isset( $_GET['lcp_yeast_seo_bulk'] ) ? sanitize_key( wp_unslash( $_GET['lcp_yeast_seo_bulk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

	if ( ! in_array( $value, array( 'index', 'noindex' ), true ) ) {
		return;
	}

	$count = static function ( $key ) {
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	};

	$notice = lcp_yeast_seo_bulk_notice( $value, $count( 'lcp_yeast_seo_changed' ), $count( 'lcp_yeast_seo_same' ), $count( 'lcp_yeast_seo_denied' ), ! get_option( 'blog_public' ) );

	printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
}
add_action( 'admin_notices', 'lcp_yeast_seo_bulk_admin_notice' );

/**
 * Has WordPress strip our result arguments from the address after showing them.
 *
 * @param string[] $args Argument names.
 * @return string[]
 */
function lcp_yeast_seo_bulk_removable_args( $args ) {
	return array_merge( $args, array( 'lcp_yeast_seo_bulk', 'lcp_yeast_seo_changed', 'lcp_yeast_seo_same', 'lcp_yeast_seo_denied' ) );
}
add_filter( 'removable_query_args', 'lcp_yeast_seo_bulk_removable_args' );

/**
 * Registers the bulk actions on every post type the plugin manages.
 *
 * @return void
 */
function lcp_yeast_seo_register_bulk_actions() {
	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		add_filter( "bulk_actions-edit-{$post_type}", 'lcp_yeast_seo_add_bulk_actions' );
		add_filter( "handle_bulk_actions-edit-{$post_type}", 'lcp_yeast_seo_handle_bulk_action', 10, 3 );
	}
}
add_action( 'admin_init', 'lcp_yeast_seo_register_bulk_actions' );
