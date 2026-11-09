<?php
/**
 * Automatic structured data: WebSite, WebPage, Article and BreadcrumbList.
 *
 * Generated for pages and posts so every page carries a baseline graph. A
 * type is only generated where the page's own schema field doesn't already
 * declare it, so hand-written schema always wins. Off when Yoast SEO is
 * active, since Yoast emits its own graph.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether automatic structured data is switched on.
 *
 * @return bool
 */
function lcp_yeast_seo_auto_schema_enabled() {
	return lcp_yeast_seo_flag_setting( 'auto_schema', true );
}

/**
 * The kinds of node the plugin can generate, with the label shown in settings.
 *
 * @return string[] Kind => label.
 */
function lcp_yeast_seo_auto_schema_kinds() {
	return array(
		'website'    => 'WebSite',
		'webpage'    => 'WebPage (and CollectionPage for the posts page)',
		'article'    => 'Article / BlogPosting for posts',
		'breadcrumb' => 'BreadcrumbList',
		'itemlist'   => 'List of posts on the posts page',
	);
}

/**
 * Kinds switched off in settings. Everything is on until switched off.
 *
 * @return string[]
 */
function lcp_yeast_seo_auto_schema_disabled_kinds() {
	$saved    = lcp_yeast_seo_get_setting( 'auto_schema_kinds', array() );
	$disabled = array();

	foreach ( array_keys( lcp_yeast_seo_auto_schema_kinds() ) as $kind ) {
		if ( is_array( $saved ) && isset( $saved[ $kind ] ) && empty( $saved[ $kind ] ) ) {
			$disabled[] = $kind;
		}
	}

	return $disabled;
}

/**
 * Sanitizes the per-kind switches.
 *
 * @param mixed $input Submitted values, kind => '1' or '0'.
 * @return string[]
 */
function lcp_yeast_seo_sanitize_auto_schema_kinds( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = array();

	foreach ( array_keys( lcp_yeast_seo_auto_schema_kinds() ) as $kind ) {
		$out[ $kind ] = empty( $input[ $kind ] ) ? '0' : '1';
	}

	return $out;
}

/**
 * Schema.org types that stand in for one another: a page that declares any
 * type in a group already has that kind of node. FAQPage is deliberately not
 * a WebPage here: a FAQ block adds to a page's WebPage node, it doesn't
 * replace it.
 *
 * @return array[] Group name => types.
 */
function lcp_yeast_seo_auto_schema_type_groups() {
	return array(
		'website'    => array( 'WebSite' ),
		'webpage'    => array( 'WebPage', 'CollectionPage', 'AboutPage', 'ContactPage', 'ProfilePage', 'ItemPage', 'CheckoutPage', 'SearchResultsPage' ),
		'article'    => array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' ),
		'breadcrumb' => array( 'BreadcrumbList' ),
	);
}

/**
 * Builds the automatic graph from plain data.
 *
 * Context keys: home_url, site_name, tagline, language, org_id, is_front,
 * declared_types (types the page's own schema already declares),
 * disabled_kinds (kinds switched off in settings), and page
 * (null, or url, name, description, kind, article_type, published, modified,
 * author {name, url}, image, breadcrumbs [[name, url], ...], items
 * [[name, url], ...] and items_start, for a listing page).
 *
 * @param array $ctx Context.
 * @return array[] Entity nodes without @context.
 */
function lcp_yeast_seo_build_auto_graph( array $ctx ) {
	$declared = array_map( 'strval', (array) ( $ctx['declared_types'] ?? array() ) );
	$disabled = (array) ( $ctx['disabled_kinds'] ?? array() );
	$has      = static function ( $group ) use ( $declared, $disabled ) {
		return in_array( $group, $disabled, true ) || array_intersect( lcp_yeast_seo_auto_schema_type_groups()[ $group ], $declared );
	};

	$home       = trailingslashit( $ctx['home_url'] );
	$website_id = $home . '#website';
	$org_ref    = ! empty( $ctx['org_id'] ) ? array( '@id' => $ctx['org_id'] ) : null;
	$page       = $ctx['page'] ?? null;
	$nodes      = array();

	if ( ! $has( 'website' ) ) {
		$website = array(
			'@type' => 'WebSite',
			'@id'   => $website_id,
			'url'   => $home,
			'name'  => $ctx['site_name'],
		);

		if ( '' !== (string) $ctx['tagline'] ) {
			$website['description'] = $ctx['tagline'];
		}

		if ( '' !== (string) $ctx['language'] ) {
			$website['inLanguage'] = $ctx['language'];
		}

		if ( $org_ref ) {
			$website['publisher'] = $org_ref;
		}

		$nodes[] = $website;
	}

	if ( ! $page ) {
		return $nodes;
	}

	$url           = $page['url'];
	$webpage_id    = $url . '#webpage';
	$article_id    = $url . '#article';
	$breadcrumb_id = $url . '#breadcrumb';
	$image_id      = $url . '#primaryimage';
	$crumbs        = array_values( (array) ( $page['breadcrumbs'] ?? array() ) );
	$want_crumbs   = count( $crumbs ) >= 2 && ! $has( 'breadcrumb' );
	$emit_webpage  = ! $has( 'webpage' );
	$article_type  = (string) ( $page['article_type'] ?? '' );
	$emit_article  = '' !== $article_type && ! $has( 'article' );

	if ( $emit_webpage ) {
		$webpage = array(
			'@type'    => 'CollectionPage' === ( $page['kind'] ?? '' ) ? 'CollectionPage' : 'WebPage',
			'@id'      => $webpage_id,
			'url'      => $url,
			'name'     => $page['name'],
			'isPartOf' => array( '@id' => $website_id ),
		);

		if ( ! empty( $page['description'] ) ) {
			$webpage['description'] = $page['description'];
		}

		if ( ! empty( $page['published'] ) ) {
			$webpage['datePublished'] = $page['published'];
			$webpage['dateModified']  = $page['modified'] ?: $page['published'];
		}

		if ( ! empty( $page['image'] ) ) {
			$webpage['primaryImageOfPage'] = array( '@id' => $image_id );
		}

		if ( $want_crumbs ) {
			$webpage['breadcrumb'] = array( '@id' => $breadcrumb_id );
		}

		if ( 'CollectionPage' === $webpage['@type'] ) {
			if ( $org_ref ) {
				$webpage['about'] = $org_ref;
			}

			$list = in_array( 'itemlist', $disabled, true ) ? null : lcp_yeast_seo_build_item_list( $url . '#postlist', (array) ( $page['items'] ?? array() ), (int) ( $page['items_start'] ?? 0 ) );

			if ( $list ) {
				$webpage['mainEntity'] = $list;
			}
		}

		if ( '' !== (string) $ctx['language'] ) {
			$webpage['inLanguage'] = $ctx['language'];
		}

		$nodes[] = $webpage;
	}

	if ( $emit_article ) {
		$article = array(
			'@type'            => $article_type,
			'@id'              => $article_id,
			'headline'         => $page['name'],
			'mainEntityOfPage' => $emit_webpage ? array( '@id' => $webpage_id ) : array(
				'@type' => 'WebPage',
				'@id'   => $url,
			),
		);

		if ( $emit_webpage ) {
			$article['isPartOf'] = array( '@id' => $webpage_id );
		}

		if ( ! empty( $page['published'] ) ) {
			$article['datePublished'] = $page['published'];
			$article['dateModified']  = $page['modified'] ?: $page['published'];
		}

		if ( ! empty( $page['author']['name'] ) ) {
			$article['author'] = array(
				'@type' => $page['author']['type'] ?? 'Person',
				'name'  => $page['author']['name'],
			);

			if ( ! empty( $page['author']['url'] ) ) {
				$article['author']['url'] = $page['author']['url'];
			}
		}

		if ( ! empty( $page['description'] ) ) {
			$article['description'] = $page['description'];
		}

		if ( ! empty( $page['image'] ) ) {
			$article['image'] = array( '@id' => $image_id );
		}

		if ( $org_ref ) {
			$article['publisher'] = $org_ref;
		}

		if ( '' !== (string) $ctx['language'] ) {
			$article['inLanguage'] = $ctx['language'];
		}

		$nodes[] = $article;
	}

	if ( ! empty( $page['image'] ) && ( $emit_webpage || $emit_article ) ) {
		$nodes[] = array(
			'@type'      => 'ImageObject',
			'@id'        => $image_id,
			'url'        => $page['image'],
			'contentUrl' => $page['image'],
		);
	}

	if ( $want_crumbs ) {
		$items = array();
		$last  = count( $crumbs ) - 1;

		foreach ( $crumbs as $index => $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $crumb[0],
			);

			if ( $index < $last ) {
				$item['item'] = $crumb[1];
			}

			$items[] = $item;
		}

		$nodes[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $breadcrumb_id,
			'itemListElement' => $items,
		);
	}

	return $nodes;
}

/**
 * Builds an ItemList node from [name, url] pairs.
 *
 * @param string  $id     The list's @id.
 * @param array[] $items  List of [name, url].
 * @param int     $offset Items listed on earlier pages, so positions continue.
 * @return array|null Null when there are no items.
 */
function lcp_yeast_seo_build_item_list( $id, array $items, $offset = 0 ) {
	if ( ! $items ) {
		return null;
	}

	$elements = array();

	foreach ( array_values( $items ) as $index => $item ) {
		$elements[] = array(
			'@type'    => 'ListItem',
			'position' => $offset + $index + 1,
			'name'     => $item[0],
			'url'      => $item[1],
		);
	}

	return array(
		'@type'           => 'ItemList',
		'@id'             => $id,
		'numberOfItems'   => count( $elements ),
		'itemListElement' => $elements,
	);
}

/**
 * The posts the main query is showing, as [name, url] pairs, skipping any set
 * to noindex. Reads the query as it stands, so it matches what the page lists.
 *
 * @return array[]
 */
function lcp_yeast_seo_listing_items() {
	global $wp_query;

	$items = array();

	foreach ( (array) $wp_query->posts as $post ) {
		if ( lcp_yeast_seo_post_is_noindex( $post->ID ) ) {
			continue;
		}

		$items[] = array( lcp_yeast_seo_plain_title( $post->ID ), get_permalink( $post->ID ) );
	}

	return $items;
}

/**
 * Every @type the current page's own schema field declares.
 *
 * @return string[]
 */
function lcp_yeast_seo_declared_types() {
	$types = array();

	foreach ( lcp_yeast_seo_current_nodes() as $node ) {
		if ( ! empty( $node['@type'] ) ) {
			$types = array_merge( $types, array_map( 'strval', (array) $node['@type'] ) );
		}
	}

	return array_values( array_unique( $types ) );
}

/**
 * Plain-text title of a post, ready for JSON.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function lcp_yeast_seo_plain_title( $post_id ) {
	return html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * The breadcrumb trail for a post: Home, its ancestors or listing page, then itself.
 *
 * @param int $post_id Post ID.
 * @return array[] List of [name, url].
 */
function lcp_yeast_seo_breadcrumb_trail( $post_id ) {
	$trail     = array( array( 'Home', home_url( '/' ) ) );
	$post_type = get_post_type( $post_id );

	if ( 'post' === $post_type ) {
		$posts_page = (int) get_option( 'page_for_posts' );

		if ( $posts_page ) {
			$trail[] = array( lcp_yeast_seo_plain_title( $posts_page ), get_permalink( $posts_page ) );
		}
	} elseif ( is_post_type_hierarchical( $post_type ) ) {
		foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $ancestor_id ) {
			$trail[] = array( lcp_yeast_seo_plain_title( $ancestor_id ), get_permalink( $ancestor_id ) );
		}
	} else {
		$archive = get_post_type_archive_link( $post_type );
		$object  = get_post_type_object( $post_type );

		if ( $archive && $object ) {
			$trail[] = array( $object->labels->name, $archive );
		}
	}

	$trail[] = array( lcp_yeast_seo_plain_title( $post_id ), get_permalink( $post_id ) );

	return $trail;
}

/**
 * Whether articles name the organisation, rather than the WordPress user, as
 * their author.
 *
 * @return bool
 */
function lcp_yeast_seo_article_author_is_organization() {
	return 'organization' === lcp_yeast_seo_get_setting( 'article_author', 'person' );
}

/**
 * The author to credit on an article: the post's WordPress user, or the
 * organisation (the site name and URL) for sites that publish under one name.
 *
 * @param int   $author_id Post author user ID.
 * @param array $ctx       Context so far (site_name, home_url).
 * @return array|null name, url and type, or null for no author.
 */
function lcp_yeast_seo_article_author( $author_id, array $ctx ) {
	if ( lcp_yeast_seo_article_author_is_organization() ) {
		return array(
			'type' => 'Organization',
			'name' => $ctx['site_name'],
			'url'  => $ctx['home_url'],
		);
	}

	return $author_id ? array(
		'type' => 'Person',
		'name' => get_the_author_meta( 'display_name', $author_id ),
		'url'  => get_author_posts_url( $author_id ),
	) : null;
}

/**
 * Gathers the context for the current request, or null when it isn't a page
 * this module describes.
 *
 * @return array|null
 */
function lcp_yeast_seo_auto_schema_context() {
	$is_front = is_front_page();

	if ( ! $is_front && ! lcp_yeast_seo_is_managed_view() ) {
		return null;
	}

	$post_id = lcp_yeast_seo_current_post_id();

	if ( $post_id && ! in_array( get_post_type( $post_id ), lcp_yeast_seo_post_types(), true ) ) {
		return null;
	}

	$org_id = lcp_yeast_seo_org_should_output() || lcp_yeast_seo_declares_type( LCP_YEAST_SEO_ORGANIZATION_TYPES ) ? lcp_yeast_seo_organization_id() : '';

	$ctx = array(
		'home_url'       => home_url( '/' ),
		'site_name'      => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
		'tagline'        => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
		'language'       => get_bloginfo( 'language' ),
		'org_id'         => $org_id,
		'is_front'       => $is_front,
		'declared_types' => lcp_yeast_seo_declared_types(),
		'disabled_kinds' => lcp_yeast_seo_auto_schema_disabled_kinds(),
		'page'           => null,
	);

	if ( $post_id ) {
		$post          = get_post( $post_id );
		$article_types = (array) apply_filters( 'lcp_yeast_seo_auto_schema_article_types', array( 'post' => 'BlogPosting' ) );
		$is_index      = is_home();
		$author_id     = $post ? (int) $post->post_author : 0;

		$paged = max( (int) get_query_var( 'paged' ), 1 );

		$ctx['page'] = array(
			'url'          => $is_front ? home_url( '/' ) : ( $is_index ? lcp_yeast_seo_current_archive_url() : get_permalink( $post_id ) ),
			'name'         => lcp_yeast_seo_plain_title( $post_id ),
			'description'  => lcp_yeast_seo_effective_description(),
			'kind'         => $is_index ? 'CollectionPage' : 'WebPage',
			'article_type' => $is_index || $is_front ? '' : (string) ( $article_types[ get_post_type( $post_id ) ] ?? '' ),
			'published'    => $is_front ? '' : get_post_time( 'c', true, $post_id ),
			'modified'     => $is_front ? '' : get_post_modified_time( 'c', true, $post_id ),
			'author'       => lcp_yeast_seo_article_author( $author_id, $ctx ),
			'image'        => lcp_yeast_seo_get_post_image( $post_id ),
			'breadcrumbs'  => $is_front ? array() : lcp_yeast_seo_breadcrumb_trail( $post_id ),
			'items'        => $is_index ? lcp_yeast_seo_listing_items() : array(),
			'items_start'  => ( $paged - 1 ) * (int) get_option( 'posts_per_page' ),
		);
	} elseif ( $is_front ) {
		$ctx['page'] = array(
			'url'          => home_url( '/' ),
			'name'         => $ctx['site_name'],
			'description'  => $ctx['tagline'],
			'kind'         => 'CollectionPage',
			'article_type' => '',
			'items'        => is_home() ? lcp_yeast_seo_listing_items() : array(),
			'items_start'  => ( max( (int) get_query_var( 'paged' ), 1 ) - 1 ) * (int) get_option( 'posts_per_page' ),
		);
	}

	return $ctx;
}

/**
 * Schema.org types present in a finished page: JSON-LD entities (top level or
 * in an @graph) and microdata itemtypes, from any source.
 *
 * @param string $html Page HTML.
 * @return string[]
 */
function lcp_yeast_seo_detect_schema_types( $html ) {
	$types = array();

	if ( preg_match_all( '#<script\b[^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $scripts ) ) {
		foreach ( $scripts[1] as $json ) {
			$data = json_decode( trim( $json ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$entities = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data['@type'] ) ? array( $data ) : $data );

			foreach ( $entities as $entity ) {
				if ( is_array( $entity ) && ! empty( $entity['@type'] ) ) {
					$types = array_merge( $types, array_map( 'strval', (array) $entity['@type'] ) );
				}
			}
		}
	}

	if ( preg_match_all( '#\bitemtype\s*=\s*["\']https?://schema\.org/([A-Za-z]+)["\']#i', (string) $html, $microdata ) ) {
		$types = array_merge( $types, $microdata[1] );
	}

	return array_values( array_unique( $types ) );
}

/**
 * Builds the automatic graph's <script> tag for a context, or '' when there is
 * nothing to output.
 *
 * @param array $ctx Context from lcp_yeast_seo_auto_schema_context(), with declared_types.
 * @return string
 */
function lcp_yeast_seo_auto_schema_script( array $ctx ) {
	/**
	 * Filters the automatic schema nodes before they are output.
	 *
	 * @param array[] $nodes Entity nodes without @context.
	 * @param array   $ctx   The context they were built from.
	 */
	$nodes = (array) apply_filters( 'lcp_yeast_seo_auto_schema_nodes', lcp_yeast_seo_build_auto_graph( $ctx ), $ctx );

	if ( empty( $nodes ) ) {
		return '';
	}

	$json = wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
	);

	return false === $json ? '' : '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

/**
 * Inserts the automatic graph into a finished page, just before </head>,
 * leaving out every type the page already carries from any source (the page's
 * own schema field, a theme block, another plugin). Returns the page untouched
 * on any problem, so a failure here can never break a page.
 *
 * @param string $html Page HTML.
 * @param array  $ctx  Context from lcp_yeast_seo_auto_schema_context().
 * @return string
 */
function lcp_yeast_seo_inject_auto_schema( $html, array $ctx ) {
	try {
		$position = stripos( $html, '</head>' );

		if ( false === $position ) {
			return $html;
		}

		$ctx['declared_types'] = array_values( array_unique( array_merge( (array) $ctx['declared_types'], lcp_yeast_seo_detect_schema_types( $html ) ) ) );
		$script                = lcp_yeast_seo_auto_schema_script( $ctx );

		return '' === $script ? $html : substr_replace( $html, $script, $position, 0 );
	} catch ( \Throwable $e ) {
		return $html;
	}
}

/**
 * Whether the page output is being scanned (set once the buffer starts).
 *
 * @param bool|null $set Pass true when the buffer starts; omit to read.
 * @return bool
 */
function lcp_yeast_seo_auto_schema_buffering( $set = null ) {
	static $buffering = false;

	if ( null !== $set ) {
		$buffering = (bool) $set;
	}

	return $buffering;
}

/**
 * Whether automatic schema applies to this request at all.
 *
 * @return bool
 */
function lcp_yeast_seo_auto_schema_active() {
	return ! is_admin()
		&& ! lcp_yeast_seo_yoast_active()
		&& lcp_yeast_seo_schema_enabled()
		&& lcp_yeast_seo_auto_schema_enabled();
}

/**
 * Starts buffering the page so existing schema can be detected before the
 * automatic graph is added. Theme blocks print their schema while the body
 * renders, after wp_head has run, so the head alone can't tell.
 *
 * Return false from `lcp_yeast_seo_auto_schema_scan_output` to skip the scan
 * and print the graph in wp_head regardless of what else is on the page.
 *
 * @return void
 */
function lcp_yeast_seo_start_auto_schema_buffer() {
	if ( ! lcp_yeast_seo_auto_schema_active() || is_feed() || is_robots() || is_embed() || get_query_var( 'lcp_yeast_seo_sitemap' ) ) {
		return;
	}

	if ( ! apply_filters( 'lcp_yeast_seo_auto_schema_scan_output', true ) ) {
		return;
	}

	$ctx = lcp_yeast_seo_auto_schema_context();

	if ( ! $ctx ) {
		return;
	}

	lcp_yeast_seo_auto_schema_buffering( true );

	ob_start(
		static function ( $html ) use ( $ctx ) {
			return lcp_yeast_seo_inject_auto_schema( $html, $ctx );
		}
	);
}
add_action( 'template_redirect', 'lcp_yeast_seo_start_auto_schema_buffer', 20 );

/**
 * Prints the automatic graph in wp_head when the page isn't being scanned
 * (the scan filter is off). Types the page's own schema field declares are
 * still skipped; types a theme prints later are not detectable here.
 *
 * @return void
 */
function lcp_yeast_seo_render_auto_schema() {
	if ( lcp_yeast_seo_auto_schema_buffering() || ! lcp_yeast_seo_auto_schema_active() ) {
		return;
	}

	$ctx = lcp_yeast_seo_auto_schema_context();

	if ( $ctx ) {
		echo lcp_yeast_seo_auto_schema_script( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_head', 'lcp_yeast_seo_render_auto_schema', 18 );
