<?php
/**
 * Health dashboard: fetches each page the way a visitor (or Google) gets it
 * and checks its structured data, so a theme block's schema is judged along
 * with the plugin's own.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_HEALTH_OPTION', 'lcp_yeast_seo_health' );
define( 'LCP_YEAST_SEO_HEALTH_BATCH', 3 );

/**
 * Splits a page's JSON-LD scripts into parsed blocks.
 *
 * @param string $html Page HTML.
 * @return array[] Each: data (array|null) and error (string).
 */
function lcp_yeast_seo_health_extract_blocks( $html ) {
	$blocks = array();

	if ( ! preg_match_all( '#<script\b[^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $scripts ) ) {
		return $blocks;
	}

	foreach ( $scripts[1] as $json ) {
		$data = json_decode( trim( $json ), true );

		$blocks[] = array(
			'data'  => is_array( $data ) ? $data : null,
			'error' => is_array( $data ) ? '' : json_last_error_msg(),
		);
	}

	return $blocks;
}

/**
 * The top-level entities of one JSON-LD block.
 *
 * @param array $data Decoded block.
 * @return array[]
 */
function lcp_yeast_seo_health_entities( array $data ) {
	if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
		$entities = $data['@graph'];
	} elseif ( isset( $data['@type'] ) || isset( $data['@id'] ) ) {
		$entities = array( $data );
	} else {
		$entities = $data;
	}

	return array_values(
		array_filter(
			$entities,
			static function ( $entity ) {
				return is_array( $entity );
			}
		)
	);
}

/**
 * Walks a decoded structure collecting the @ids it defines and the @ids it
 * only refers to.
 *
 * @param mixed    $node    Any decoded value.
 * @param string[] $defined Defined ids, by reference.
 * @param string[] $refs    Referenced ids, by reference.
 * @return void
 */
function lcp_yeast_seo_health_collect_ids( $node, array &$defined, array &$refs ) {
	if ( ! is_array( $node ) ) {
		return;
	}

	if ( isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
		$others = array_diff( array_keys( $node ), array( '@id', '@context' ) );

		if ( $others ) {
			$defined[ $node['@id'] ] = true;
		} else {
			$refs[ $node['@id'] ] = true;
		}
	}

	foreach ( $node as $value ) {
		lcp_yeast_seo_health_collect_ids( $value, $defined, $refs );
	}
}

/**
 * The @type values of an entity as strings.
 *
 * @param array $entity Entity.
 * @return string[]
 */
function lcp_yeast_seo_health_types_of( array $entity ) {
	return isset( $entity['@type'] ) ? array_map( 'strval', (array) $entity['@type'] ) : array();
}

/**
 * Whether an entity property has a usable value.
 *
 * @param array  $entity   Entity.
 * @param string $property Property name.
 * @return bool
 */
function lcp_yeast_seo_health_has( array $entity, $property ) {
	return isset( $entity[ $property ] ) && ! in_array( $entity[ $property ], array( '', array(), null ), true );
}

/**
 * Checks one page's HTML and returns what is wrong with its structured data.
 *
 * @param string $html      Page HTML.
 * @param string $site_host Host of this site, so only its own @id references are judged.
 * @return array{status:string,issues:array[],types:string[],blocks:int}
 */
function lcp_yeast_seo_health_analyze( $html, $site_host ) {
	$issues = array();
	$types  = array();
	$push   = static function ( $level, $code, $message ) use ( &$issues ) {
		$issues[] = array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		);
	};

	$blocks    = lcp_yeast_seo_health_extract_blocks( $html );
	$microdata = preg_match_all( '#\bitemtype\s*=\s*["\']https?://schema\.org/([A-Za-z]+)["\']#i', (string) $html, $micro ) ? array_unique( $micro[1] ) : array();

	if ( ! $blocks && ! $microdata ) {
		$push( 'error', 'no_schema', 'No structured data found on this page.' );

		return array(
			'status' => 'error',
			'issues' => $issues,
			'types'  => array(),
			'blocks' => 0,
		);
	}

	$entities = array();
	$defined  = array();
	$refs     = array();

	foreach ( $blocks as $block ) {
		if ( null === $block['data'] ) {
			$push( 'error', 'invalid_json', 'A JSON-LD block is not valid JSON (' . $block['error'] . '), so search engines ignore it.' );
			continue;
		}

		if ( ! isset( $block['data']['@context'] ) && ! lcp_yeast_seo_health_entities_have_context( $block['data'] ) ) {
			$push( 'error', 'no_context', 'A JSON-LD block has no @context, so it is not read as schema.org data.' );
		}

		foreach ( lcp_yeast_seo_health_entities( $block['data'] ) as $entity ) {
			$entities[] = $entity;
		}

		lcp_yeast_seo_health_collect_ids( $block['data'], $defined, $refs );
	}

	foreach ( $entities as $entity ) {
		$entity_types = lcp_yeast_seo_health_types_of( $entity );

		if ( ! $entity_types ) {
			if ( array_diff( array_keys( $entity ), array( '@id', '@context' ) ) ) {
				$push( 'warning', 'no_type', 'An entity has no @type, so its kind is unknown.' );
			}
			continue;
		}

		$types = array_merge( $types, $entity_types );

		foreach ( lcp_yeast_seo_health_check_entity( $entity, $entity_types ) as $found ) {
			$issues[] = $found;
		}
	}

	$types = array_values( array_unique( array_merge( $types, $microdata ) ) );

	foreach ( lcp_yeast_seo_auto_schema_type_groups() as $group => $group_types ) {
		$count = 0;

		foreach ( $entities as $entity ) {
			if ( array_intersect( lcp_yeast_seo_health_types_of( $entity ), $group_types ) ) {
				++$count;
			}
		}

		if ( $count > 1 ) {
			$push( 'error', 'duplicate_' . $group, 'This page has ' . $count . ' ' . lcp_yeast_seo_health_group_label( $group ) . ' entities; there should be one.' );
		}
	}

	foreach ( array_keys( $refs ) as $ref ) {
		if ( isset( $defined[ $ref ] ) || false === strpos( $ref, '#' ) ) {
			continue;
		}

		$host = wp_parse_url( $ref, PHP_URL_HOST );

		if ( $host && $host === $site_host ) {
			$push( 'warning', 'dangling_ref', 'Something refers to ' . $ref . ', which is not defined on this page.' );
		}
	}

	$status = 'ok';

	foreach ( $issues as $issue ) {
		if ( 'error' === $issue['level'] ) {
			$status = 'error';
			break;
		}

		$status = 'warning';
	}

	return array(
		'status' => $status,
		'issues' => $issues,
		'types'  => $types,
		'blocks' => count( $blocks ),
	);
}

/**
 * Whether every entity of a context-less block carries its own @context.
 *
 * @param array $data Decoded block.
 * @return bool
 */
function lcp_yeast_seo_health_entities_have_context( array $data ) {
	$entities = lcp_yeast_seo_health_entities( $data );

	if ( ! $entities || isset( $data['@graph'] ) ) {
		return false;
	}

	foreach ( $entities as $entity ) {
		if ( ! isset( $entity['@context'] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Plain label for a kind of entity, for messages.
 *
 * @param string $group Group key from lcp_yeast_seo_auto_schema_type_groups().
 * @return string
 */
function lcp_yeast_seo_health_group_label( $group ) {
	$labels = array(
		'website'    => 'WebSite',
		'webpage'    => 'page (WebPage-type)',
		'article'    => 'article',
		'breadcrumb' => 'BreadcrumbList',
	);

	return $labels[ $group ] ?? $group;
}

/**
 * Checks the properties the common entity types should carry.
 *
 * @param array    $entity Entity.
 * @param string[] $types  Its types.
 * @return array[] Issues.
 */
function lcp_yeast_seo_health_check_entity( array $entity, array $types ) {
	$issues = array();
	$push   = static function ( $level, $code, $message ) use ( &$issues ) {
		$issues[] = array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		);
	};
	$groups = lcp_yeast_seo_auto_schema_type_groups();
	$label  = implode( '/', $types );

	if ( array_intersect( $types, $groups['article'] ) ) {
		foreach ( array(
			'headline'      => 'a headline',
			'datePublished' => 'a published date',
			'author'        => 'an author',
			'image'         => 'an image',
		) as $property => $what ) {
			if ( ! lcp_yeast_seo_health_has( $entity, $property ) ) {
				$push( 'warning', 'article_no_' . strtolower( $property ), $label . ' has no ' . substr( $what, strpos( $what, ' ' ) + 1 ) . '.' );
			}
		}
	}

	if ( in_array( 'BreadcrumbList', $types, true ) ) {
		$items = isset( $entity['itemListElement'] ) && is_array( $entity['itemListElement'] ) ? $entity['itemListElement'] : array();

		if ( ! $items ) {
			$push( 'error', 'breadcrumb_empty', 'The BreadcrumbList has no items.' );
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) && ( ! lcp_yeast_seo_health_has( $item, 'name' ) && ! ( isset( $item['item'] ) && is_array( $item['item'] ) && lcp_yeast_seo_health_has( $item['item'], 'name' ) ) ) ) {
				$push( 'warning', 'breadcrumb_no_name', 'A breadcrumb item has no name.' );
				break;
			}
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! lcp_yeast_seo_health_has( $item, 'position' ) ) {
				$push( 'warning', 'breadcrumb_no_position', 'A breadcrumb item has no position.' );
				break;
			}
		}
	}

	if ( in_array( 'FAQPage', $types, true ) ) {
		$questions = isset( $entity['mainEntity'] ) && is_array( $entity['mainEntity'] ) ? $entity['mainEntity'] : array();

		if ( ! $questions ) {
			$push( 'error', 'faq_empty', 'The FAQPage has no questions.' );
		}

		foreach ( isset( $questions['@type'] ) ? array( $questions ) : $questions as $question ) {
			if ( ! is_array( $question ) || ! lcp_yeast_seo_health_has( $question, 'name' ) || empty( $question['acceptedAnswer']['text'] ) ) {
				$push( 'warning', 'faq_incomplete', 'A FAQ question is missing its text or its answer.' );
				break;
			}
		}
	}

	if ( array_intersect( $types, array_merge( array( 'Organization', 'LocalBusiness', 'Corporation', 'WebSite' ), $groups['webpage'] ) ) && ! lcp_yeast_seo_health_has( $entity, 'name' ) ) {
		$push( 'warning', 'no_name', $label . ' has no name.' );
	}

	if ( in_array( 'LocalBusiness', $types, true ) && ! lcp_yeast_seo_health_has( $entity, 'address' ) ) {
		$push( 'warning', 'localbusiness_no_address', 'LocalBusiness has no address.' );
	}

	return $issues;
}

/**
 * A short plain-language hint on how to fix a kind of problem.
 *
 * @param string $code Issue code.
 * @return string
 */
function lcp_yeast_seo_health_hint( $code ) {
	$hints = array(
		'no_schema'                => 'Turn on automatic structured data under Schema, or add schema to this page.',
		'invalid_json'             => 'Fix the JSON in the page\'s schema field, or in the theme block that prints it.',
		'no_context'               => 'Add "@context": "https://schema.org" to that JSON-LD block.',
		'no_type'                  => 'Give the entity an "@type", such as WebPage or Article.',
		'dangling_ref'             => 'Define that entity on the page (an Organization, for example), or remove the reference.',
		'article_no_image'         => 'Set a featured image for the post, or add an image to its content.',
		'article_no_author'        => 'Set the Article author under Schema, or give the post an author.',
		'article_no_headline'      => 'Check that the post has a title and that nothing overrides it in the schema.',
		'article_no_datepublished' => 'Check the post is published, and that the theme\'s schema includes its date.',
		'breadcrumb_empty'         => 'Check the breadcrumbs block on this page has its items.',
		'breadcrumb_no_name'       => 'Give every breadcrumb a label.',
		'breadcrumb_no_position'   => 'Number each breadcrumb item in order, starting at 1.',
		'faq_empty'                => 'Add questions to the FAQ block, or remove the block.',
		'faq_incomplete'           => 'Fill in every question and its answer in the FAQ block.',
		'no_name'                  => 'Add a name to that entity in your Organization JSON-LD under Schema.',
		'localbusiness_no_address' => 'Add a postal address to the LocalBusiness in your Organization JSON-LD under Schema.',
		'fetch_failed'             => 'The server could not reach its own page. A firewall, basic authentication or a local address can cause this.',
	);

	if ( 0 === strpos( $code, 'duplicate_' ) ) {
		return 'Two sources print the same kind of schema, usually the plugin and the theme. Switch one off under Schema, Generate.';
	}

	if ( 0 === strpos( $code, 'http_' ) ) {
		return 'Open the page in a browser. It may need publishing, or the host may be blocking the scan.';
	}

	return $hints[ $code ] ?? '';
}

/**
 * Post IDs the health scan covers: the indexable content in the sitemaps.
 *
 * @return int[]
 */
function lcp_yeast_seo_health_page_ids() {
	$ids = array();

	foreach ( lcp_yeast_seo_sitemap_post_types() as $post_type ) {
		$ids = array_merge( $ids, lcp_yeast_seo_sitemap_post_ids( $post_type ) );
	}

	$max = max( 1, (int) apply_filters( 'lcp_yeast_seo_health_max_pages', 500 ) );

	return array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, $max );
}

/**
 * Fetches a post's public page and checks it.
 *
 * @param int $post_id Post ID.
 * @return array Result record.
 */
function lcp_yeast_seo_health_check_post( $post_id ) {
	$url      = get_permalink( $post_id );
	$result   = array(
		'status'   => 'error',
		'issues'   => array(),
		'types'    => array(),
		'blocks'   => 0,
		'time'     => time(),
		'modified' => (string) get_post_field( 'post_modified_gmt', $post_id ),
	);
	$response = wp_remote_get(
		add_query_arg( 'lcp_health', time(), $url ),
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			'user-agent'  => 'Yeast SEO health check',
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		$result['issues'][] = array(
			'level'   => 'error',
			'code'    => 'fetch_failed',
			'message' => 'The page could not be fetched: ' . $response->get_error_message(),
		);

		return $result;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		$result['issues'][] = array(
			'level'   => 'error',
			'code'    => 'http_' . $code,
			'message' => 'The page answered with HTTP ' . $code . ' instead of 200.',
		);

		return $result;
	}

	return array_merge( $result, lcp_yeast_seo_health_analyze( wp_remote_retrieve_body( $response ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
}

/**
 * Stored results, keyed by post ID.
 *
 * @return array
 */
function lcp_yeast_seo_health_results() {
	$stored = get_option( LCP_YEAST_SEO_HEALTH_OPTION, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * AJAX: checks a few pages and returns the refreshed dashboard.
 *
 * @return void
 */
function lcp_yeast_seo_health_ajax_check() {
	check_ajax_referer( 'lcp_yeast_seo_health' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
	}

	$ids     = isset( $_POST['ids'] ) ? array_slice( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ), 0, LCP_YEAST_SEO_HEALTH_BATCH ) : array();
	$allowed = lcp_yeast_seo_health_page_ids();
	$results = lcp_yeast_seo_health_results();

	foreach ( array_intersect( $ids, $allowed ) as $post_id ) {
		$results[ $post_id ] = lcp_yeast_seo_health_check_post( $post_id );
	}

	update_option( LCP_YEAST_SEO_HEALTH_OPTION, $results, false );

	ob_start();
	lcp_yeast_seo_health_render_summary();
	$summary = ob_get_clean();

	ob_start();
	lcp_yeast_seo_health_render_list();
	$list = ob_get_clean();

	wp_send_json_success(
		array(
			'summary' => $summary,
			'list'    => $list,
		)
	);
}
add_action( 'wp_ajax_lcp_yeast_seo_health_check', 'lcp_yeast_seo_health_ajax_check' );

/**
 * Totals across the stored results for the pages currently in scope.
 *
 * @param int[] $ids     Page IDs in scope.
 * @param array $results Stored results.
 * @return array{total:int,checked:int,ok:int,warning:int,error:int}
 */
function lcp_yeast_seo_health_totals( array $ids, array $results ) {
	$totals = array(
		'total'   => count( $ids ),
		'checked' => 0,
		'ok'      => 0,
		'warning' => 0,
		'error'   => 0,
	);

	foreach ( $ids as $id ) {
		if ( ! isset( $results[ $id ] ) ) {
			continue;
		}

		++$totals['checked'];
		++$totals[ $results[ $id ]['status'] ];
	}

	return $totals;
}

/**
 * The most common problems across the checked pages, most frequent first.
 *
 * @param int[] $ids     Page IDs in scope.
 * @param array $results Stored results.
 * @return array[] Each: level, message, pages.
 */
function lcp_yeast_seo_health_common_problems( array $ids, array $results ) {
	$problems = array();

	foreach ( $ids as $id ) {
		foreach ( $results[ $id ]['issues'] ?? array() as $issue ) {
			$key = $issue['code'];

			if ( ! isset( $problems[ $key ] ) ) {
				$problems[ $key ] = array(
					'code'    => $key,
					'level'   => $issue['level'],
					'message' => $issue['message'],
					'pages'   => 0,
				);
			}

			++$problems[ $key ]['pages'];

			if ( 'error' === $issue['level'] ) {
				$problems[ $key ]['level'] = 'error';
			}
		}
	}

	uasort(
		$problems,
		static function ( $a, $b ) {
			return array( 'error' === $b['level'], $b['pages'] ) <=> array( 'error' === $a['level'], $a['pages'] );
		}
	);

	return array_values( $problems );
}
