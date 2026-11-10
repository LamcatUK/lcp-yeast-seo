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
 * @param string|false $redirect_url Proposed redirect URL.
 * @return string|false
 */
function lcp_yeast_seo_disable_sitemap_canonical_redirect( $redirect_url ) {
	return get_query_var( 'lcp_yeast_seo_sitemap' ) ? false : $redirect_url;
}
add_filter( 'redirect_canonical', 'lcp_yeast_seo_disable_sitemap_canonical_redirect' );

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
 * Whether sitemap entries list images.
 *
 * @return bool
 */
function lcp_yeast_seo_sitemap_images_enabled() {
	return lcp_yeast_seo_flag_setting( 'sitemap_images', true );
}

/**
 * Finds the image URLs in a chunk of HTML, in document order, without
 * rendering anything. Root-relative URLs are resolved against the site; data
 * URIs and anything that isn't http(s) are ignored.
 *
 * @param string $html     Post content.
 * @param string $home_url Site home URL, with trailing slash.
 * @return string[]
 */
function lcp_yeast_seo_extract_content_images( $html, $home_url ) {
	if ( ! preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', (string) $html, $matches ) ) {
		return array();
	}

	$urls = array();

	foreach ( $matches[2] as $src ) {
		$src = trim( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );

		if ( 0 === strpos( $src, '//' ) ) {
			$src = 'https:' . $src;
		} elseif ( 0 === strpos( $src, '/' ) ) {
			$src = rtrim( $home_url, '/' ) . $src;
		}

		if ( preg_match( '#^https?://#i', $src ) ) {
			$urls[] = $src;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Image URLs for a post's sitemap entry: its lead image (featured, else the
 * first block image, the same one used for og:image), then any other images in
 * its content, up to a cap.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function lcp_yeast_seo_sitemap_post_images( $post_id ) {
	$images = array();
	$lead   = lcp_yeast_seo_get_post_image( $post_id );

	if ( '' !== $lead ) {
		$images[] = $lead;
	}

	$images = array_merge( $images, lcp_yeast_seo_extract_content_images( get_post_field( 'post_content', $post_id ), home_url( '/' ) ) );
	$max    = max( 1, (int) apply_filters( 'lcp_yeast_seo_sitemap_max_images', 10 ) );

	return array_slice( array_values( array_unique( $images ) ), 0, $max );
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
 * Taxonomies that get a sitemap: the ones the theme uses, unless their archive is noindex.
 *
 * @return string[] Taxonomy names.
 */
function lcp_yeast_seo_sitemap_taxonomies() {
	$names = array();

	foreach ( get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ) ) as $taxonomy ) {
		if ( 'post_format' === $taxonomy || post_type_exists( $taxonomy ) ) {
			continue;
		}

		if ( '1' === lcp_yeast_seo_archive_setting( 'tax:' . $taxonomy, 'noindex', '0' ) || ! lcp_yeast_seo_archive_is_in_use( 'tax:' . $taxonomy ) ) {
			continue;
		}

		$names[] = $taxonomy;
	}

	return $names;
}

/**
 * Terms of a taxonomy that belong in its sitemap: they have posts, and are not set to noindex.
 *
 * @param string $taxonomy Taxonomy name.
 * @return int[]
 */
function lcp_yeast_seo_sitemap_term_ids( $taxonomy ) {
	$ids = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'fields'     => 'ids',
			'orderby'    => 'id',
		)
	);

	if ( is_wp_error( $ids ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map( 'intval', $ids ),
			static function ( $term_id ) {
				return 'noindex' !== get_term_meta( $term_id, LCP_YEAST_SEO_ROBOTS_META_KEY, true );
			}
		)
	);
}

/**
 * When a taxonomy's pages last changed: its most recently edited post.
 *
 * @param string $taxonomy Taxonomy name.
 * @return string W3C date, or '' when it has no posts.
 */
function lcp_yeast_seo_sitemap_taxonomy_lastmod( $taxonomy ) {
	$posts = get_posts(
		array(
			'post_type'      => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'operator' => 'EXISTS',
				),
			),
		)
	);

	return $posts ? (string) get_post_modified_time( DATE_W3C, true, $posts[0] ) : '';
}

/**
 * Renders one taxonomy sitemap page.
 *
 * @param string $taxonomy Taxonomy name.
 * @param int    $page     Page number.
 * @return void
 */
function lcp_yeast_seo_render_taxonomy_sitemap( $taxonomy, $page ) {
	$page   = max( 1, absint( $page ) );
	$chunks = array_chunk( lcp_yeast_seo_sitemap_term_ids( $taxonomy ), LCP_YEAST_SEO_SITEMAP_PAGE_SIZE );

	if ( empty( $chunks[ $page - 1 ] ) ) {
		lcp_yeast_seo_output_sitemap_404();
	}

	$urls = array();

	foreach ( $chunks[ $page - 1 ] as $term_id ) {
		$link = get_term_link( $term_id, $taxonomy );

		if ( ! is_wp_error( $link ) ) {
			$urls[] = $link;
		}
	}

	if ( lcp_yeast_seo_request_wants_html() ) {
		$object = get_taxonomy( $taxonomy );

		lcp_yeast_seo_output_sitemap_html(
			'urlset',
			array_map(
				static function ( $url ) {
					return array(
						'loc'     => $url,
						'lastmod' => '',
					);
				},
				$urls
			),
			$object ? $object->labels->name : $taxonomy
		);
	}

	$xml  = '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>';
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

	foreach ( $urls as $url ) {
		$xml .= '<url><loc>' . esc_url( $url ) . '</loc></url>';
	}

	$xml .= '</urlset>';

	lcp_yeast_seo_output_xml( $xml );
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

	foreach ( lcp_yeast_seo_sitemap_taxonomies() as $taxonomy ) {
		$total = count( lcp_yeast_seo_sitemap_term_ids( $taxonomy ) );

		for ( $page = 1; $page <= (int) ceil( $total / LCP_YEAST_SEO_SITEMAP_PAGE_SIZE ); $page++ ) {
			$items[] = array(
				'loc'     => lcp_yeast_seo_sitemap_url( $taxonomy, $page ),
				'lastmod' => lcp_yeast_seo_sitemap_taxonomy_lastmod( $taxonomy ),
				'count'   => min( LCP_YEAST_SEO_SITEMAP_PAGE_SIZE, $total - ( $page - 1 ) * LCP_YEAST_SEO_SITEMAP_PAGE_SIZE ),
			);
		}
	}

	if ( lcp_yeast_seo_request_wants_html() ) {
		lcp_yeast_seo_output_sitemap_html( 'index', $items );
	}

	$xml  = '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>';
	$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

	foreach ( $items as $item ) {
		$xml .= '<sitemap>';
		$xml .= '<loc>' . esc_url( $item['loc'] ) . '</loc>';
		$xml .= '' !== (string) $item['lastmod'] ? '<lastmod>' . esc_html( $item['lastmod'] ) . '</lastmod>' : '';
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
		if ( in_array( $post_type, lcp_yeast_seo_sitemap_taxonomies(), true ) ) {
			lcp_yeast_seo_render_taxonomy_sitemap( $post_type, $page );
		}

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

	$with_images = lcp_yeast_seo_sitemap_images_enabled();

	$xml  = '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>';
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . ( $with_images ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '' ) . '>';

	foreach ( $chunks[ $page - 1 ] as $post_id ) {
		$xml .= '<url>';
		$xml .= '<loc>' . esc_url( get_permalink( $post_id ) ) . '</loc>';
		$xml .= '<lastmod>' . esc_html( get_post_modified_time( DATE_W3C, true, $post_id ) ) . '</lastmod>';

		if ( $with_images ) {
			foreach ( lcp_yeast_seo_sitemap_post_images( $post_id ) as $image_url ) {
				$xml .= '<image:image><image:loc>' . esc_url( $image_url ) . '</image:loc></image:image>';
			}
		}

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

	$request_path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( '/' !== $request_path && '/' === substr( $request_path, -1 ) ) {
		wp_safe_redirect( home_url( '/' . ltrim( untrailingslashit( substr( $request_path, strlen( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ) ) ), '/' ) ), 301 );
		exit;
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

/**
 * Adds the sitemap index to the virtual robots.txt.
 *
 * Has no effect when a physical robots.txt exists (the web server serves that
 * instead), and when Yoast is active, which adds its own line.
 *
 * @param string     $output Robots.txt content so far.
 * @param bool|string|int $public Whether the site is public (a bool from current WordPress, '0'/'1' from older versions).
 * @return string
 */
function lcp_yeast_seo_robots_txt( $output, $public ) {
	if ( ! $public || lcp_yeast_seo_yoast_active() || ! lcp_yeast_seo_sitemaps_enabled() ) {
		return $output;
	}

	$line = 'Sitemap: ' . home_url( '/sitemap_index.xml' );

	if ( false !== stripos( $output, $line ) ) {
		return $output;
	}

	return rtrim( $output ) . "\n\n" . $line . "\n";
}
add_filter( 'robots_txt', 'lcp_yeast_seo_robots_txt', 10, 2 );
