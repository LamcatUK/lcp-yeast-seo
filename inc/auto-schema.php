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
 * Schema.org types that stand in for one another: a page that declares any
 * type in a group already has that kind of node.
 *
 * @return array[] Group name => types.
 */
function lcp_yeast_seo_auto_schema_type_groups() {
	return array(
		'website'    => array( 'WebSite' ),
		'webpage'    => array( 'WebPage', 'CollectionPage', 'AboutPage', 'ContactPage', 'FAQPage', 'ProfilePage', 'ItemPage', 'QAPage', 'CheckoutPage', 'SearchResultsPage' ),
		'article'    => array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' ),
		'breadcrumb' => array( 'BreadcrumbList' ),
	);
}

/**
 * Builds the automatic graph from plain data.
 *
 * Context keys: home_url, site_name, tagline, language, org_id, is_front,
 * declared_types (types the page's own schema already declares), and page
 * (null, or url, name, description, kind, article_type, published, modified,
 * author {name, url}, image, breadcrumbs [[name, url], ...]).
 *
 * @param array $ctx Context.
 * @return array[] Entity nodes without @context.
 */
function lcp_yeast_seo_build_auto_graph( array $ctx ) {
	$declared = array_map( 'strval', (array) ( $ctx['declared_types'] ?? array() ) );
	$has      = static function ( $group ) use ( $declared ) {
		return (bool) array_intersect( lcp_yeast_seo_auto_schema_type_groups()[ $group ], $declared );
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
			$article['author'] = array( '@type' => 'Person', 'name' => $page['author']['name'] );

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
		'page'           => null,
	);

	if ( $post_id ) {
		$post          = get_post( $post_id );
		$article_types = (array) apply_filters( 'lcp_yeast_seo_auto_schema_article_types', array( 'post' => 'Article' ) );
		$is_index      = is_home();
		$author_id     = $post ? (int) $post->post_author : 0;

		$ctx['page'] = array(
			'url'          => $is_front ? home_url( '/' ) : get_permalink( $post_id ),
			'name'         => lcp_yeast_seo_plain_title( $post_id ),
			'description'  => lcp_yeast_seo_effective_description(),
			'kind'         => $is_index ? 'CollectionPage' : 'WebPage',
			'article_type' => $is_index || $is_front ? '' : (string) ( $article_types[ get_post_type( $post_id ) ] ?? '' ),
			'published'    => $is_front ? '' : get_post_time( 'c', true, $post_id ),
			'modified'     => $is_front ? '' : get_post_modified_time( 'c', true, $post_id ),
			'author'       => $author_id ? array(
				'name' => get_the_author_meta( 'display_name', $author_id ),
				'url'  => get_author_posts_url( $author_id ),
			) : null,
			'image'        => lcp_yeast_seo_get_post_image( $post_id ),
			'breadcrumbs'  => $is_front ? array() : lcp_yeast_seo_breadcrumb_trail( $post_id ),
		);
	} elseif ( $is_front ) {
		$ctx['page'] = array(
			'url'          => home_url( '/' ),
			'name'         => $ctx['site_name'],
			'description'  => $ctx['tagline'],
			'kind'         => 'CollectionPage',
			'article_type' => '',
		);
	}

	return $ctx;
}

/**
 * Outputs the automatic graph in wp_head, before the Organization and the
 * page's own schema field.
 *
 * @return void
 */
function lcp_yeast_seo_render_auto_schema() {
	if ( is_admin() || lcp_yeast_seo_yoast_active() || ! lcp_yeast_seo_schema_enabled() || ! lcp_yeast_seo_auto_schema_enabled() ) {
		return;
	}

	$ctx = lcp_yeast_seo_auto_schema_context();

	if ( ! $ctx ) {
		return;
	}

	/**
	 * Filters the automatic schema nodes before they are output.
	 *
	 * @param array[] $nodes Entity nodes without @context.
	 * @param array   $ctx   The context they were built from.
	 */
	$nodes = (array) apply_filters( 'lcp_yeast_seo_auto_schema_nodes', lcp_yeast_seo_build_auto_graph( $ctx ), $ctx );

	if ( empty( $nodes ) ) {
		return;
	}

	$json = wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
	);

	if ( false === $json ) {
		return;
	}

	echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'lcp_yeast_seo_render_auto_schema', 18 );
