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
define( 'LCP_YEAST_SEO_HEALTH_PAGE_TIMEOUT', 12 );
define( 'LCP_YEAST_SEO_HEALTH_BUDGET', 20 );
define( 'LCP_YEAST_SEO_HEALTH_TITLE_MIN', 15 );
define( 'LCP_YEAST_SEO_HEALTH_TITLE_MAX', 60 );
define( 'LCP_YEAST_SEO_HEALTH_DESCRIPTION_MIN', 70 );
define( 'LCP_YEAST_SEO_HEALTH_DESCRIPTION_MAX', 160 );

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

		if ( lcp_yeast_seo_health_is_site_entity_ref( $ref ) ) {
			continue;
		}

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
 * Whether a reference names the site's own Organization or WebSite, the two
 * entities that belong on the home page alone. Other pages point at them by
 * @id, which is deliberate and not worth a warning.
 *
 * @param string $ref An @id reference.
 * @return bool
 */
function lcp_yeast_seo_health_is_site_entity_ref( $ref ) {
	$parts = wp_parse_url( (string) $ref );

	return in_array( $parts['fragment'] ?? '', array( 'organization', 'website' ), true )
		&& in_array( $parts['path'] ?? '', array( '', '/' ), true )
		&& empty( $parts['query'] );
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
 * Worst level among issues: error, then warning, else ok.
 *
 * @param array[] $issues Issues, each with a level.
 * @return string ok, warning or error.
 */
function lcp_yeast_seo_health_status_of( array $issues ) {
	$status = 'ok';

	foreach ( $issues as $issue ) {
		if ( 'error' === $issue['level'] ) {
			return 'error';
		}

		$status = 'warning';
	}

	return $status;
}

/**
 * Status of one area (schema or meta) in a result.
 *
 * @param array|null $result Result record.
 * @param string     $area   schema or meta.
 * @return string ok, warning, error or none when it hasn't been checked.
 */
function lcp_yeast_seo_health_area_status( $result, $area ) {
	if ( ! $result || ( 'meta' === $area && ! isset( $result['meta'] ) ) ) {
		return 'none';
	}

	$issues = array_filter(
		$result['issues'] ?? array(),
		static function ( $issue ) use ( $area ) {
			return lcp_yeast_seo_health_area_of( $issue['code'] ) === $area;
		}
	);

	return lcp_yeast_seo_health_status_of( $issues );
}

/**
 * Which area an issue belongs to: meta (title, description and the like) or schema.
 *
 * @param string $code Issue code.
 * @return string meta or schema.
 */
function lcp_yeast_seo_health_area_of( $code ) {
	return 0 === strpos( (string) $code, 'meta_' ) ? 'meta' : 'schema';
}

/**
 * Attributes of every tag of a kind in the head, lower-cased by name.
 *
 * @param string $head Head HTML.
 * @param string $tag  Tag name, e.g. meta or link.
 * @return array[] One attribute map per tag.
 */
function lcp_yeast_seo_health_head_tags( $head, $tag ) {
	$found = array();

	if ( ! preg_match_all( '/<' . $tag . '\b[^>]*>/i', (string) $head, $tags ) ) {
		return $found;
	}

	foreach ( $tags[0] as $raw ) {
		$attrs = array();

		if ( preg_match_all( '/([a-zA-Z:_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $raw, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				$attrs[ strtolower( $pair[1] ) ] = html_entity_decode( '' !== $pair[2] ? $pair[2] : ( $pair[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
			}
		}

		$found[] = $attrs;
	}

	return $found;
}

/**
 * Content of the first meta tag whose name or property matches.
 *
 * @param array[] $metas Meta tags from lcp_yeast_seo_health_head_tags().
 * @param string  $key   name or property.
 * @param string  $value Value to match, case-insensitive.
 * @return string|null Content, or null when the tag is absent.
 */
function lcp_yeast_seo_health_meta_content( array $metas, $key, $value ) {
	foreach ( $metas as $attrs ) {
		if ( isset( $attrs[ $key ] ) && 0 === strcasecmp( $attrs[ $key ], $value ) ) {
			return trim( preg_replace( '/\s+/u', ' ', $attrs['content'] ?? '' ) );
		}
	}

	return null;
}

/**
 * Lower-cases the host and drops the fragment, so canonicals compare fairly.
 *
 * @param string $url URL.
 * @return string
 */
function lcp_yeast_seo_health_normalize_url( $url ) {
	$url   = preg_replace( '/#.*$/', '', trim( (string) $url ) );
	$parts = wp_parse_url( $url );

	if ( empty( $parts['host'] ) ) {
		return $url;
	}

	return strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) )
		. ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
}

/**
 * Checks a page's head for what search results are built from: title,
 * description, indexability, canonical, plus the H1 and social tags.
 *
 * Pages marked noindex get only a note, since they are not meant to appear.
 * When the whole site is hidden from search engines (Settings, Reading) that
 * noindex is ignored: it says nothing about any one page, and the checks still
 * run so a site in development can be prepared for launch.
 *
 * @param string $html        Page HTML.
 * @param string $page_url    The page's own URL, which its canonical should match.
 * @param bool   $site_hidden Whether the site is set to discourage search engines.
 * @return array{issues:array[],facts:array}
 */
function lcp_yeast_seo_health_analyze_meta( $html, $page_url, $site_hidden = false ) {
	$issues = array();
	$push   = static function ( $level, $code, $message ) use ( &$issues ) {
		$issues[] = array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		);
	};

	$html  = (string) $html;
	$end   = stripos( $html, '</head>' );
	$head  = false === $end ? $html : substr( $html, 0, $end );
	$metas = lcp_yeast_seo_health_head_tags( $head, 'meta' );
	$title = preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $found ) ? trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $found[1] ), ENT_QUOTES, 'UTF-8' ) ) ) : '';
	$desc  = (string) lcp_yeast_seo_health_meta_content( $metas, 'name', 'description' );
	$robot = strtolower( (string) lcp_yeast_seo_health_meta_content( $metas, 'name', 'robots' ) );
	$noidx = ! $site_hidden && false !== strpos( $robot, 'noindex' );

	$facts = array(
		'title'       => $title,
		'description' => $desc,
		'indexable'   => ! $noidx,
	);

	if ( $noidx ) {
		$push( 'warning', 'meta_noindex', 'This page is marked noindex, yet it is in your sitemap.' );

		return array(
			'issues' => $issues,
			'facts'  => $facts,
		);
	}

	$title_len = mb_strlen( $title );
	$desc_len  = mb_strlen( $desc );

	if ( 0 === $title_len ) {
		$push( 'error', 'meta_no_title', 'The page has no title.' );
	} elseif ( $title_len > LCP_YEAST_SEO_HEALTH_TITLE_MAX ) {
		$push( 'warning', 'meta_title_long', 'The title is ' . $title_len . ' characters; search results usually cut it off after about ' . LCP_YEAST_SEO_HEALTH_TITLE_MAX . '.' );
	} elseif ( $title_len < LCP_YEAST_SEO_HEALTH_TITLE_MIN ) {
		$push( 'warning', 'meta_title_short', 'The title is only ' . $title_len . ' characters, which says little about the page.' );
	}

	if ( 0 === $desc_len ) {
		$push( 'error', 'meta_no_description', 'The page has no meta description.' );
	} elseif ( $desc_len > LCP_YEAST_SEO_HEALTH_DESCRIPTION_MAX ) {
		$push( 'warning', 'meta_description_long', 'The description is ' . $desc_len . ' characters; search results usually cut it off after about ' . LCP_YEAST_SEO_HEALTH_DESCRIPTION_MAX . '.' );
	} elseif ( $desc_len < LCP_YEAST_SEO_HEALTH_DESCRIPTION_MIN ) {
		$push( 'warning', 'meta_description_short', 'The description is only ' . $desc_len . ' characters; there is room to say more.' );
	}

	$canonical = '';

	foreach ( lcp_yeast_seo_health_head_tags( $head, 'link' ) as $attrs ) {
		if ( isset( $attrs['rel'], $attrs['href'] ) && in_array( 'canonical', preg_split( '/\s+/', strtolower( $attrs['rel'] ) ), true ) ) {
			$canonical = $attrs['href'];
			break;
		}
	}

	if ( '' === $canonical ) {
		$push( 'warning', 'meta_no_canonical', 'The page has no canonical link.' );
	} elseif ( lcp_yeast_seo_health_normalize_url( $canonical ) !== lcp_yeast_seo_health_normalize_url( $page_url ) ) {
		$push( 'warning', 'meta_canonical_other', 'The canonical link points to ' . $canonical . ', not to this page.' );
	}

	$h1s = preg_match_all( '/<h1\b/i', $html );

	if ( 0 === $h1s ) {
		$push( 'warning', 'meta_no_h1', 'The page has no main heading (H1).' );
	} elseif ( $h1s > 1 ) {
		$push( 'warning', 'meta_multiple_h1', 'The page has ' . $h1s . ' main headings (H1); one is clearer.' );
	}

	if ( '' === (string) lcp_yeast_seo_health_meta_content( $metas, 'property', 'og:title' ) ) {
		$push( 'warning', 'meta_no_og_title', 'The page has no social sharing title (og:title).' );
	}

	if ( '' === (string) lcp_yeast_seo_health_meta_content( $metas, 'property', 'og:image' ) ) {
		$push( 'warning', 'meta_no_og_image', 'The page has no social sharing image (og:image).' );
	}

	return array(
		'issues' => $issues,
		'facts'  => $facts,
	);
}

/**
 * Adds the cross-page checks: titles and descriptions used on more than one page.
 *
 * Those can't be judged one page at a time, so they are worked out from the
 * stored results each time the dashboard is drawn, and the status is redone.
 *
 * @param int[] $ids     Page IDs in scope.
 * @param array $results Stored results.
 * @return array Results for those pages, with duplicates added.
 */
function lcp_yeast_seo_health_effective( array $ids, array $results ) {
	$groups = array(
		'title'       => array(),
		'description' => array(),
	);

	foreach ( $ids as $id ) {
		$facts = $results[ $id ]['meta'] ?? null;

		if ( ! $facts || empty( $facts['indexable'] ) ) {
			continue;
		}

		foreach ( array_keys( $groups ) as $field ) {
			$value = mb_strtolower( (string) $facts[ $field ] );

			if ( '' !== $value ) {
				$groups[ $field ][ $value ][] = $id;
			}
		}
	}

	$effective = array();

	foreach ( $ids as $id ) {
		if ( ! isset( $results[ $id ] ) ) {
			continue;
		}

		$result = $results[ $id ];

		foreach ( array_keys( $groups ) as $field ) {
			$value  = mb_strtolower( (string) ( $result['meta'][ $field ] ?? '' ) );
			$others = '' !== $value && ! empty( $result['meta']['indexable'] ) ? array_values( array_diff( $groups[ $field ][ $value ] ?? array(), array( $id ) ) ) : array();

			if ( ! $others ) {
				continue;
			}

			$result['issues'][] = array(
				'level'   => 'error',
				'code'    => 'meta_duplicate_' . $field,
				'message' => 'The ' . $field . ' is also used on ' . lcp_yeast_seo_count_noun( count( $others ), 'other page' ) . ', such as "' . wp_strip_all_tags( get_the_title( $others[0] ) ) . '".',
				'summary' => 'Pages share the same ' . $field . '.',
			);
		}

		$result['status'] = lcp_yeast_seo_health_status_of( $result['issues'] );
		$effective[ $id ] = $result;
	}

	return $effective;
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
		'redirect_offsite'         => 'Mark the page noindex in its Yeast SEO modal to leave it out of the sitemap, or remove the redirect if it is not intended.',
		'fetch_failed'             => 'The server could not fetch its own page, even through its own address. A firewall, bot protection, basic authentication or a very slow page can cause this. Use Check again, or see the lcp_yeast_seo_health_request_args filter to change the timeout or user agent.',
		'meta_noindex'             => 'Either remove it from the sitemap by keeping it noindex, or turn noindex off in the page\'s Yeast SEO settings.',
		'meta_no_title'            => 'Give the page a title in its Yeast SEO modal, or set a title template for its post type under General.',
		'meta_title_long'          => 'Shorten the title in the page\'s Yeast SEO modal, putting the key words first.',
		'meta_title_short'         => 'Write a fuller title in the page\'s Yeast SEO modal.',
		'meta_no_description'      => 'Write a description in the page\'s Yeast SEO modal, or switch on automatic descriptions under General.',
		'meta_description_long'    => 'Shorten the description in the page\'s Yeast SEO modal.',
		'meta_description_short'   => 'Expand the description in the page\'s Yeast SEO modal to sum up the page.',
		'meta_no_canonical'        => 'A canonical link should be printed by the plugin. Check another plugin or the theme is not removing it.',
		'meta_canonical_other'     => 'Check the canonical set in the page\'s Yeast SEO modal, or whether another plugin is changing it.',
		'meta_no_h1'               => 'Add a main heading to the page, usually the title block in the theme.',
		'meta_multiple_h1'         => 'Keep one main heading and turn the others into H2s.',
		'meta_no_og_title'         => 'Social tags come from the Yeast SEO modal. Check nothing else is stopping them printing.',
		'meta_no_og_image'         => 'Set a featured image, or add a social image in the page\'s Yeast SEO modal.',
		'meta_duplicate_title'     => 'Give each page its own title in its Yeast SEO modal.',
		'meta_duplicate_description' => 'Give each page its own description in its Yeast SEO modal.',
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
 * Whether a fetch error means the server couldn't connect to the site at all
 * (as opposed to the page being slow or answering with an error).
 *
 * @param string $message WP_Error message.
 * @return bool
 */
function lcp_yeast_seo_health_is_connect_failure( $message ) {
	return (bool) preg_match( '/failed to connect|could not connect|connection (?:timed out|refused)|cURL error (?:6|7):|could not resolve/i', (string) $message );
}

/**
 * Addresses this server can be reached on from itself, best first.
 *
 * @return string[]
 */
function lcp_yeast_seo_health_local_ips() {
	$ips = array();

	foreach ( array( $_SERVER['SERVER_ADDR'] ?? '', '127.0.0.1' ) as $ip ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by filter_var below.
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ! in_array( $ip, $ips, true ) ) {
			$ips[] = $ip;
		}
	}

	return $ips;
}

/**
 * wp_remote_get(), optionally sending the request to a chosen IP while keeping
 * the site's hostname for the Host header and TLS name (curl --resolve).
 *
 * @param string $url  URL.
 * @param array  $args Request args.
 * @param string $ip   IP to connect to, or '' for normal DNS.
 * @return array|\WP_Error
 */
function lcp_yeast_seo_health_remote_get( $url, array $args, $ip = '' ) {
	if ( '' === $ip ) {
		return wp_remote_get( $url, $args );
	}

	$parts = wp_parse_url( $url );
	$host  = (string) ( $parts['host'] ?? '' );
	$port  = (int) ( $parts['port'] ?? ( 'https' === ( $parts['scheme'] ?? '' ) ? 443 : 80 ) );
	$hook  = static function ( $handle ) use ( $host, $port, $ip ) {
		curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) );
	};

	add_action( 'http_api_curl', $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	$response = wp_remote_get( $url, $args );
	remove_action( 'http_api_curl', $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

	return $response;
}

/**
 * Fetches a page for the scan. If the server can't connect to the site's public
 * address (a CDN, firewall or hosting set-up that blocks its own traffic),
 * it retries through its own IP and remembers the route that worked.
 *
 * @param string $url  URL.
 * @param array  $args Request args.
 * @return array|\WP_Error
 */
function lcp_yeast_seo_health_fetch( $url, array $args ) {
	$key    = 'lcp_yeast_seo_health_ip_' . md5( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$cached = get_transient( $key );

	if ( is_string( $cached ) && '' !== $cached ) {
		$response = lcp_yeast_seo_health_remote_get( $url, $args, $cached );

		if ( ! is_wp_error( $response ) || ! lcp_yeast_seo_health_is_connect_failure( $response->get_error_message() ) ) {
			return $response;
		}

		delete_transient( $key );
	}

	$response = lcp_yeast_seo_health_remote_get( $url, $args );

	if ( ! is_wp_error( $response ) || ! lcp_yeast_seo_health_is_connect_failure( $response->get_error_message() ) ) {
		return $response;
	}

	$args['timeout'] = min( 8, (int) $args['timeout'] );

	foreach ( lcp_yeast_seo_health_local_ips() as $ip ) {
		$retry = lcp_yeast_seo_health_remote_get( $url, $args, $ip );

		if ( ! is_wp_error( $retry ) ) {
			set_transient( $key, $ip, HOUR_IN_SECONDS );

			return $retry;
		}
	}

	return $response;
}

/**
 * Turns a Location header into an absolute URL.
 *
 * @param string $location Header value, absolute or relative.
 * @param string $base     URL that answered with the redirect.
 * @return string Absolute URL, or '' when there is nothing usable.
 */
function lcp_yeast_seo_health_resolve_location( $location, $base ) {
	$location = trim( (string) $location );

	if ( '' === $location ) {
		return '';
	}

	if ( preg_match( '#^https?://#i', $location ) ) {
		return $location;
	}

	$parts = wp_parse_url( $base );

	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	if ( 0 === strpos( $location, '//' ) ) {
		return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
	}

	if ( '/' === $location[0] ) {
		return $origin . $location;
	}

	return $origin . rtrim( dirname( (string) ( $parts['path'] ?? '/' ) . 'x' ), '/' ) . '/' . $location;
}

/**
 * Drops the scan's own cache-busting argument from a URL shown to people.
 *
 * @param string $url URL.
 * @return string
 */
function lcp_yeast_seo_health_strip_scan_arg( $url ) {
	return rtrim( (string) preg_replace( '/([?&])lcp_health=\d+&?/', '$1', (string) $url ), '?&' );
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
	$args      = (array) apply_filters(
		'lcp_yeast_seo_health_request_args',
		array(
			'timeout'     => LCP_YEAST_SEO_HEALTH_PAGE_TIMEOUT,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			'user-agent'  => 'Yeast SEO health check',
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		),
		$post_id
	);
	$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$fetch_url = add_query_arg( 'lcp_health', time(), $url );
	$hops      = 0;

	// Follow redirects within this site by hand, so a page that sends visitors
	// to another site is reported as that, not fetched from the other site.
	do {
		$response = lcp_yeast_seo_health_fetch( $fetch_url, $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$target   = $status >= 300 && $status < 400 ? lcp_yeast_seo_health_resolve_location( (string) wp_remote_retrieve_header( $response, 'location' ), $fetch_url ) : '';

		if ( '' === $target ) {
			break;
		}

		if ( wp_parse_url( $target, PHP_URL_HOST ) !== $site_host ) {
			$result['issues'][] = array(
				'level'   => 'warning',
				'code'    => 'redirect_offsite',
				'message' => 'This page redirects to ' . lcp_yeast_seo_health_strip_scan_arg( $target ) . ', so it can not be checked. A page that sends visitors elsewhere should not be in your sitemap.',
			);
			$result['status']   = 'warning';

			return $result;
		}

		$fetch_url = $target;
	} while ( ++$hops <= 3 );

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

	$body   = wp_remote_retrieve_body( $response );
	$result = array_merge( $result, lcp_yeast_seo_health_analyze( $body, $site_host ) );
	$meta   = lcp_yeast_seo_health_analyze_meta( $body, $hops ? lcp_yeast_seo_health_strip_scan_arg( $fetch_url ) : $url, ! get_option( 'blog_public' ) );

	$result['issues'] = array_merge( $result['issues'], $meta['issues'] );
	$result['meta']   = $meta['facts'];
	$result['status'] = lcp_yeast_seo_health_status_of( $result['issues'] );

	return $result;
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

	ignore_user_abort( true );
	wp_raise_memory_limit( 'admin' );

	$ids     = isset( $_POST['ids'] ) ? array_slice( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ), 0, LCP_YEAST_SEO_HEALTH_BATCH ) : array();
	$allowed = lcp_yeast_seo_health_page_ids();
	$results = lcp_yeast_seo_health_results();

	$started = microtime( true );
	$done    = array();

	// Stop starting new pages once the budget is spent, so one request stays
	// well inside the host's gateway timeout even when the site is slow to
	// answer itself. The page tells the browser which were left for next time.
	foreach ( array_intersect( $ids, $allowed ) as $post_id ) {
		if ( $done && microtime( true ) - $started > LCP_YEAST_SEO_HEALTH_BUDGET ) {
			break;
		}

		$results[ $post_id ] = lcp_yeast_seo_health_check_post( $post_id );
		$done[]              = $post_id;
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
			'done'    => array_merge( $done, array_diff( $ids, $allowed ) ),
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
					'message' => $issue['summary'] ?? $issue['message'],
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
