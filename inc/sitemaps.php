<?php
/**
 * XML sitemap generation.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_SITEMAP_PAGE_SIZE', 1000 );

/**
 * Adds rewrite rules for sitemap endpoints.
 *
 * @return void
 */
function lcp_yeast_seo_register_sitemap_rewrites() {
	add_rewrite_rule( '^sitemap\.xml$', 'index.php?lcp_yeast_seo_sitemap=index', 'top' );
	add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?lcp_yeast_seo_sitemap=index', 'top' );
	add_rewrite_rule( '^([^/]+)-sitemap([0-9]+)?\.xml$', 'index.php?lcp_yeast_seo_sitemap=post-type&lcp_yeast_seo_post_type=$matches[1]&lcp_yeast_seo_sitemap_page=$matches[2]', 'top' );
}
add_action( 'init', 'lcp_yeast_seo_register_sitemap_rewrites' );

/**
 * Disable WordPress core sitemaps so this plugin owns sitemap URLs.
 *
 * @return bool
 */
function lcp_yeast_seo_disable_core_sitemaps() {
	return false;
}
add_filter( 'wp_sitemaps_enabled', 'lcp_yeast_seo_disable_core_sitemaps' );

/**
 * Registers custom query vars.
 *
 * @param string[] $vars Existing query vars.
 * @return string[]
 */
function lcp_yeast_seo_register_sitemap_query_vars( $vars ) {
	$vars[] = 'lcp_yeast_seo_sitemap';
	$vars[] = 'lcp_yeast_seo_post_type';
	$vars[] = 'lcp_yeast_seo_sitemap_page';

	return $vars;
}
add_filter( 'query_vars', 'lcp_yeast_seo_register_sitemap_query_vars' );

/**
 * Stops WordPress canonical redirects from adding a trailing slash to sitemap files.
 *
 * @param string|false $redirect_url  Proposed redirect URL.
 * @param string       $requested_url Requested URL.
 * @return string|false
 */
function lcp_yeast_seo_disable_sitemap_canonical_redirect( $redirect_url, $requested_url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( get_query_var( 'lcp_yeast_seo_sitemap' ) ) {
		return false;
	}

	return $redirect_url;
}
add_filter( 'redirect_canonical', 'lcp_yeast_seo_disable_sitemap_canonical_redirect', 10, 2 );

/**
 * Flushes rewrites on activation.
 *
 * @return void
 */
function lcp_yeast_seo_activate() {
	lcp_yeast_seo_register_sitemap_rewrites();
	flush_rewrite_rules();
}

/**
 * Flushes rewrites on deactivation.
 *
 * @return void
 */
function lcp_yeast_seo_deactivate() {
	flush_rewrite_rules();
}

/**
 * Returns the enabled post types for sitemap output.
 *
 * @return string[]
 */
function lcp_yeast_seo_sitemap_post_types() {
	$post_types = array();

	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		if ( lcp_yeast_seo_sitemap_post_type_enabled( $post_type ) ) {
			$post_types[] = $post_type;
		}
	}

	return $post_types;
}

/**
 * Returns the sitemap URL for a post type and page.
 *
 * @param string $post_type Post type slug.
 * @param int    $page      Page number.
 * @return string
 */
function lcp_yeast_seo_sitemap_url( $post_type, $page = 1 ) {
	$suffix = $page > 1 ? (string) $page : '';
	return home_url( '/' . $post_type . '-sitemap' . $suffix . '.xml' );
}

/**
 * Whether a given post should be excluded from sitemap output.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function lcp_yeast_seo_post_is_noindex( $post_id ) {
	return 'noindex' === get_post_meta( $post_id, LCP_YEAST_SEO_ROBOTS_META_KEY, true );
}

/**
 * Returns all published IDs for a post type that should appear in sitemaps.
 *
 * @param string $post_type Post type slug.
 * @return int[]
 */
function lcp_yeast_seo_sitemap_post_ids( $post_type ) {
	$query = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( empty( $query->posts ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map( 'intval', $query->posts ),
			function ( $post_id ) {
				return ! lcp_yeast_seo_post_is_noindex( $post_id );
			}
		)
	);
}

/**
 * Emits an XML response body and exits.
 *
 * @param string $xml XML string.
 * @return void
 */
function lcp_yeast_seo_output_xml( $xml ) {
	status_header( 200 );
	header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
	header( 'Vary: Sec-Fetch-Dest' );
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * Whether the request is a person looking at the sitemap in a browser, who
 * gets a clickable HTML view instead of raw XML.
 *
 * Decided by Sec-Fetch-Dest: document, which browsers send on navigation and
 * which crawlers, scripts and fetch() calls do not. The Accept header is not
 * used: some crawlers include text/html in it, and handing one HTML in place
 * of the sitemap would break it. When the header is absent (crawlers, plain
 * HTTP, older browsers) the answer is no, so XML is always the fallback.
 * `?xml=1` forces XML in a browser.
 *
 * @return bool
 */
function lcp_yeast_seo_request_wants_html() {
	if ( isset( $_GET['xml'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display switch.
		return false;
	}

	$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';

	return 'document' === $dest;
}

/**
 * Emits the HTML view of a sitemap and exits.
 *
 * @param string  $kind  'index' (list of sitemap files) or 'urlset' (list of URLs).
 * @param array[] $rows  Index: loc, lastmod, count. URL set: loc, lastmod.
 * @param string  $title Heading, e.g. the post type being listed.
 * @return void
 */
function lcp_yeast_seo_output_sitemap_html( $kind, $rows, $title = '' ) {
	$is_index  = 'index' === $kind;
	$index_url = home_url( '/sitemap_index.xml' );
	$raw_url   = '?xml=1'; // Relative: keeps whichever sitemap is being viewed.
	$heading   = $is_index ? __( 'XML Sitemap Index', 'lcp-yeast-seo' ) : sprintf( /* translators: %s: post type. */ __( 'XML Sitemap: %s', 'lcp-yeast-seo' ), $title );

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	header( 'X-Robots-Tag: noindex, follow' );
	header( 'Vary: Sec-Fetch-Dest' );
	?>
<!doctype html>
<html lang="en">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title><?php echo esc_html( $heading ); ?></title>
<style>
	body { margin: 0; padding: 2rem; font: 16px/1.5 system-ui, sans-serif; color: #1f2933; background: #eef2f7; }
	.wrap { max-width: 1400px; margin: 0 auto; background: #fff; border: 1px solid #d9e2ec; border-radius: 12px; overflow: hidden; }
	header { padding: 1.5rem 2rem; background: #0f172a; color: #fff; }
	header h1 { margin: 0 0 .25rem; font-size: 1.6rem; line-height: 1.2; }
	header p { margin: 0; opacity: .8; }
	header a { color: #93c5fd; }
	.main { padding: 1.25rem 2rem 2rem; }
	.meta { margin: 0 0 1rem; color: #52606d; }
	table { width: 100%; border-collapse: collapse; }
	th, td { padding: .7rem .75rem; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
	th { font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; color: #52606d; }
	tr:hover td { background: #f8fafc; }
	td a { color: #0f62fe; text-decoration: none; word-break: break-all; }
	td a:hover { text-decoration: underline; }
	.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
	@media (max-width: 600px) { body { padding: .75rem; } header, .main { padding-inline: 1rem; } }
</style>
</head>
<body>
<div class="wrap">
	<header>
		<h1><?php echo esc_html( $heading ); ?></h1>
		<p>
			<?php if ( ! $is_index ) { ?>
				<a href="<?php echo esc_url( $index_url ); ?>">&larr; <?php esc_html_e( 'Sitemap index', 'lcp-yeast-seo' ); ?></a> &middot;
			<?php } ?>
			<a href="<?php echo esc_url( $raw_url ); ?>"><?php esc_html_e( 'View raw XML', 'lcp-yeast-seo' ); ?></a>
		</p>
	</header>
	<div class="main">
		<p class="meta">
			<?php
			echo esc_html(
				$is_index
					/* translators: %s: number of sitemap files. */
					? sprintf( _n( '%s sitemap file', '%s sitemap files', count( $rows ), 'lcp-yeast-seo' ), number_format_i18n( count( $rows ) ) )
					/* translators: %s: number of URLs. */
					: sprintf( _n( '%s URL', '%s URLs', count( $rows ), 'lcp-yeast-seo' ), number_format_i18n( count( $rows ) ) )
			);
			?>
		</p>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Location', 'lcp-yeast-seo' ); ?></th>
					<?php if ( $is_index ) { ?>
					<th><?php esc_html_e( 'URLs', 'lcp-yeast-seo' ); ?></th>
					<?php } ?>
					<th><?php esc_html_e( 'Last modified', 'lcp-yeast-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) { ?>
				<tr>
					<td><a href="<?php echo esc_url( $row['loc'] ); ?>"><?php echo esc_html( $row['loc'] ); ?></a></td>
					<?php if ( $is_index ) { ?>
					<td class="num"><?php echo esc_html( number_format_i18n( absint( $row['count'] ) ) ); ?></td>
					<?php } ?>
					<td class="num"><?php echo esc_html( $row['lastmod'] ); ?></td>
				</tr>
				<?php } ?>
			</tbody>
		</table>
	</div>
</div>
</body>
</html>
	<?php
	exit;
}

/**
 * Emits a sitemap 404 and exits.
 *
 * @return void
 */
function lcp_yeast_seo_output_sitemap_404() {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	exit;
}

/**
 * Renders the sitemap index.
 *
 * @return void
 */
function lcp_yeast_seo_render_sitemap_index() {
	$items = array();

	foreach ( lcp_yeast_seo_sitemap_post_types() as $post_type ) {
		$post_ids = lcp_yeast_seo_sitemap_post_ids( $post_type );
		$total    = count( $post_ids );

		if ( 0 === $total ) {
			continue;
		}

		$chunks = (int) ceil( $total / LCP_YEAST_SEO_SITEMAP_PAGE_SIZE );

		for ( $page = 1; $page <= $chunks; $page++ ) {
			$chunk    = array_slice( $post_ids, ( $page - 1 ) * LCP_YEAST_SEO_SITEMAP_PAGE_SIZE, LCP_YEAST_SEO_SITEMAP_PAGE_SIZE );
			$lastmod  = get_post_modified_time( DATE_W3C, true, $chunk[0] );
			$items[]  = array(
				'loc'     => lcp_yeast_seo_sitemap_url( $post_type, $page ),
				'lastmod' => $lastmod,
				'count'   => count( $chunk ),
			);
		}
	}

	if ( lcp_yeast_seo_request_wants_html() ) {
		lcp_yeast_seo_output_sitemap_html( 'index', $items );
	}

	$xml  = '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>';
	$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:lcp="https://lamcat.co.uk/ns/yeast-seo">';

	foreach ( $items as $item ) {
		$xml .= '<sitemap>';
		$xml .= '<loc>' . esc_url( $item['loc'] ) . '</loc>';
		$xml .= '<lastmod>' . esc_html( $item['lastmod'] ) . '</lastmod>';
		$xml .= '<lcp:count>' . absint( $item['count'] ) . '</lcp:count>';
		$xml .= '</sitemap>';
	}

	$xml .= '</sitemapindex>';

	lcp_yeast_seo_output_xml( $xml );
}

/**
 * Renders one post-type sitemap page.
 *
 * @param string $post_type Post type slug.
 * @param int    $page      Page number.
 * @return void
 */
function lcp_yeast_seo_render_post_type_sitemap( $post_type, $page ) {
	if ( ! in_array( $post_type, lcp_yeast_seo_sitemap_post_types(), true ) ) {
		lcp_yeast_seo_output_sitemap_404();
	}

	$page     = max( 1, absint( $page ) );
	$post_ids = lcp_yeast_seo_sitemap_post_ids( $post_type );
	$chunks   = array_chunk( $post_ids, LCP_YEAST_SEO_SITEMAP_PAGE_SIZE );

	if ( empty( $chunks ) || empty( $chunks[ $page - 1 ] ) ) {
		lcp_yeast_seo_output_sitemap_404();
	}

	if ( lcp_yeast_seo_request_wants_html() ) {
		$rows = array();

		foreach ( $chunks[ $page - 1 ] as $post_id ) {
			$rows[] = array(
				'loc'     => get_permalink( $post_id ),
				'lastmod' => get_post_modified_time( DATE_W3C, true, $post_id ),
			);
		}

		$post_type_object = get_post_type_object( $post_type );

		lcp_yeast_seo_output_sitemap_html( 'urlset', $rows, $post_type_object ? $post_type_object->labels->name : $post_type );
	}

	$xml  = '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>';
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

	foreach ( $chunks[ $page - 1 ] as $post_id ) {
		$xml .= '<url>';
		$xml .= '<loc>' . esc_url( get_permalink( $post_id ) ) . '</loc>';
		$xml .= '<lastmod>' . esc_html( get_post_modified_time( DATE_W3C, true, $post_id ) ) . '</lastmod>';
		$xml .= '</url>';
	}

	$xml .= '</urlset>';

	lcp_yeast_seo_output_xml( $xml );
}

/**
 * Handles sitemap requests.
 *
 * @return void
 */
function lcp_yeast_seo_maybe_render_sitemap() {
	$type = get_query_var( 'lcp_yeast_seo_sitemap' );

	if ( ! $type ) {
		return;
	}

	if ( ! lcp_yeast_seo_sitemaps_enabled() ) {
		lcp_yeast_seo_output_sitemap_404();
	}

	if ( 'index' === $type ) {
		lcp_yeast_seo_render_sitemap_index();
	}

	if ( 'post-type' === $type ) {
		lcp_yeast_seo_render_post_type_sitemap( sanitize_key( get_query_var( 'lcp_yeast_seo_post_type' ) ), get_query_var( 'lcp_yeast_seo_sitemap_page' ) );
	}

	lcp_yeast_seo_output_sitemap_404();
}
add_action( 'template_redirect', 'lcp_yeast_seo_maybe_render_sitemap' );
