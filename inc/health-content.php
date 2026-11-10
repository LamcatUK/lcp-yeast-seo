<?php
/**
 * Health scan: missing alt text, broken links and orphan pages.
 *
 * The page's HTML is read once during the scan. Images are judged straight
 * away. Links are collected, then checked in a separate step (so a slow site
 * can't stall the scan), and which pages nothing links to is worked out from
 * the same links.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_HEALTH_LINKS_OPTION', 'lcp_yeast_seo_health_links' );
define( 'LCP_YEAST_SEO_HEALTH_LINK_BUDGET', 15 );
define( 'LCP_YEAST_SEO_HEALTH_MAX_LINKS_PER_PAGE', 300 );

/**
 * A comparable key for a URL: host and path, ignoring scheme, trailing slash and fragment.
 *
 * @param string $url URL.
 * @return string
 */
function lcp_yeast_seo_health_link_key( $url ) {
	$parts = wp_parse_url( preg_replace( '/#.*$/', '', trim( (string) $url ) ) );

	if ( empty( $parts['host'] ) ) {
		return '';
	}

	return strtolower( $parts['host'] ) . untrailingslashit( (string) ( $parts['path'] ?? '' ) ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
}

/**
 * Looks at the images on a page.
 *
 * Decorative images (an empty alt, aria-hidden, role presentation) and tracking
 * pixels are fine. An image with no alt attribute at all, or an alt that is
 * only a file name, is not.
 *
 * @param string $html Page HTML.
 * @return array{total:int,missing:string[],filename:string[]} File names of the offending images.
 */
function lcp_yeast_seo_health_image_findings( $html ) {
	$found = array(
		'total'    => 0,
		'missing'  => array(),
		'filename' => array(),
	);

	foreach ( lcp_yeast_seo_health_head_tags( $html, 'img' ) as $img ) {
		$src = (string) ( $img['src'] ?? '' );

		if ( 0 === strpos( $src, 'data:' ) && ! empty( $img['data-src'] ) ) {
			$src = (string) $img['data-src'];
		}

		$role = strtolower( (string) ( $img['role'] ?? '' ) );

		if (
			'' === $src
			|| 0 === strpos( $src, 'data:' )
			|| 'true' === strtolower( (string) ( $img['aria-hidden'] ?? '' ) )
			|| in_array( $role, array( 'presentation', 'none' ), true )
			|| ( isset( $img['width'], $img['height'] ) && (int) $img['width'] <= 2 && (int) $img['height'] <= 2 )
		) {
			continue;
		}

		++$found['total'];

		$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );

		if ( ! isset( $img['alt'] ) ) {
			$found['missing'][] = $name;
		} elseif ( preg_match( '/\.(?:jpe?g|png|gif|webp|svg|avif)$/i', trim( $img['alt'] ) ) || preg_match( '/^(?:img|dsc|image|photo|pic|screenshot)[-_ ]?\d+$/i', trim( $img['alt'] ) ) ) {
			$found['filename'][] = $name;
		}
	}

	return $found;
}

/**
 * The issues for a page's images.
 *
 * @param array $findings From lcp_yeast_seo_health_image_findings().
 * @return array[]
 */
function lcp_yeast_seo_health_image_issues( array $findings ) {
	$issues = array();
	$list   = static function ( array $names ) {
		$names = array_values( array_unique( $names ) );

		return implode( ', ', array_slice( $names, 0, 4 ) ) . ( count( $names ) > 4 ? ' and ' . ( count( $names ) - 4 ) . ' more' : '' );
	};

	if ( $findings['missing'] ) {
		$issues[] = array(
			'level'   => 'warning',
			'code'    => 'content_img_no_alt',
			'message' => lcp_yeast_seo_count_noun( count( $findings['missing'] ), 'image has', 'images have' ) . ' no alt text: ' . $list( $findings['missing'] ) . '.',
		);
	}

	if ( $findings['filename'] ) {
		$issues[] = array(
			'level'   => 'warning',
			'code'    => 'content_img_bad_alt',
			'message' => lcp_yeast_seo_count_noun( count( $findings['filename'] ), 'image has', 'images have' ) . ' alt text that is only a file name: ' . $list( $findings['filename'] ) . '.',
		);
	}

	return $issues;
}

/**
 * The links on a page, as absolute URLs without fragments.
 *
 * Skips anchors, mail and phone links, scripts, WordPress system addresses
 * (the REST API, feeds, admin and login) and the page itself.
 *
 * @param string $html     Page HTML.
 * @param string $page_url The page's own URL.
 * @return string[] Unique URLs.
 */
function lcp_yeast_seo_health_extract_links( $html, $page_url ) {
	$links = array();
	$self  = lcp_yeast_seo_health_link_key( $page_url );

	foreach ( lcp_yeast_seo_health_head_tags( $html, 'a' ) as $anchor ) {
		$href = trim( (string) ( $anchor['href'] ?? '' ) );

		if ( '' === $href || preg_match( '/^(?:#|mailto:|tel:|javascript:|sms:|data:|callto:|whatsapp:)/i', $href ) ) {
			continue;
		}

		$absolute = lcp_yeast_seo_health_resolve_location( $href, $page_url );
		$absolute = preg_replace( '/#.*$/', '', $absolute );

		if ( ! preg_match( '#^https?://#i', $absolute ) || preg_match( '#/(?:wp-json|wp-admin|xmlrpc\.php|wp-login\.php|feed)(?:/|$|\?)|[?&]replytocom=#i', $absolute ) ) {
			continue;
		}

		if ( lcp_yeast_seo_health_link_key( $absolute ) === $self ) {
			continue;
		}

		$links[ $absolute ] = true;

		if ( count( $links ) >= LCP_YEAST_SEO_HEALTH_MAX_LINKS_PER_PAGE ) {
			break;
		}
	}

	return array_keys( $links );
}

/**
 * Reads a page's content: its images, and where its links go.
 *
 * Links to other scanned pages are noted for the orphan check, and need no
 * checking (those pages exist). The rest are kept to be checked.
 *
 * @param string $html     Page HTML.
 * @param string $page_url The page's own URL.
 * @param int    $post_id  The page's ID.
 * @param array  $map      Link key => post ID for every scanned page.
 * @return array{issues:array[],facts:array}
 */
function lcp_yeast_seo_health_analyze_content( $html, $page_url, $post_id, array $map ) {
	$inbound = array();
	$to_check = array();

	foreach ( lcp_yeast_seo_health_extract_links( $html, $page_url ) as $link ) {
		$target = $map[ lcp_yeast_seo_health_link_key( $link ) ] ?? 0;

		if ( $target ) {
			if ( (int) $target !== (int) $post_id ) {
				$inbound[ (int) $target ] = true;
			}

			continue;
		}

		$to_check[] = $link;
	}

	$images = lcp_yeast_seo_health_image_findings( $html );

	return array(
		'issues' => lcp_yeast_seo_health_image_issues( $images ),
		'facts'  => array(
			'links'   => $to_check,
			'inbound' => array_keys( $inbound ),
			'images'  => $images['total'],
		),
	);
}

/**
 * What a link's HTTP status means.
 *
 * Only a missing page is called broken. Refusals to talk to a checker (401,
 * 403, 429 and the like) say nothing about the page, so they are skipped.
 *
 * @param int $code HTTP status, 0 when nothing answered.
 * @return string ok, broken, error, unreachable or skipped.
 */
function lcp_yeast_seo_health_link_verdict( $code ) {
	$code = (int) $code;

	if ( 0 === $code ) {
		return 'unreachable';
	}

	if ( $code >= 200 && $code < 400 ) {
		return 'ok';
	}

	if ( in_array( $code, array( 404, 410 ), true ) ) {
		return 'broken';
	}

	// 5xx is a server fault; odd codes above that (LinkedIn's 999) are bots being turned away.
	return $code >= 500 && $code < 600 ? 'error' : 'skipped';
}

/**
 * Whether a stored link status is recent enough to trust.
 *
 * A good link is trusted for a day; a bad one is re-checked soon, in case it was fixed.
 *
 * @param array $entry code and time.
 * @param int   $now   Current time.
 * @return bool
 */
function lcp_yeast_seo_health_link_fresh( $entry, $now ) {
	if ( ! is_array( $entry ) || ! isset( $entry['code'], $entry['time'] ) ) {
		return false;
	}

	$good = in_array( lcp_yeast_seo_health_link_verdict( $entry['code'] ), array( 'ok', 'skipped' ), true );

	return ( $now - (int) $entry['time'] ) < ( $good ? DAY_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
}

/**
 * The links still to check: those on scanned pages with no recent status.
 *
 * @param array $results  Stored results (page ID => result).
 * @param array $statuses Stored link statuses (URL => code, time).
 * @param int   $now      Current time.
 * @param int   $limit    Most to return.
 * @return string[]
 */
function lcp_yeast_seo_health_pending_links( array $results, array $statuses, $now, $limit = 100000 ) {
	$pending = array();

	foreach ( $results as $result ) {
		foreach ( (array) ( $result['content']['links'] ?? array() ) as $url ) {
			if ( ! isset( $pending[ $url ] ) && ! lcp_yeast_seo_health_link_fresh( $statuses[ $url ] ?? null, $now ) ) {
				$pending[ $url ] = true;

				if ( count( $pending ) >= $limit ) {
					return array_keys( $pending );
				}
			}
		}
	}

	return array_keys( $pending );
}

/**
 * The issues a page's links give rise to, from the checked statuses.
 *
 * @param string[] $urls     The page's links that needed checking.
 * @param array    $statuses URL => code, time.
 * @return array[]
 */
function lcp_yeast_seo_health_link_issues( array $urls, array $statuses ) {
	$broken      = array();
	$unreachable = array();
	$short       = static function ( $url, $code ) {
		return mb_strimwidth( preg_replace( '#^https?://(?:www\.)?#i', '', $url ), 0, 70, '...' ) . ( $code ? ' (' . $code . ')' : '' );
	};

	foreach ( $urls as $url ) {
		if ( ! isset( $statuses[ $url ]['code'] ) ) {
			continue;
		}

		$code    = (int) $statuses[ $url ]['code'];
		$verdict = lcp_yeast_seo_health_link_verdict( $code );

		if ( 'broken' === $verdict ) {
			$broken[] = $short( $url, $code );
		} elseif ( in_array( $verdict, array( 'error', 'unreachable' ), true ) ) {
			$unreachable[] = $short( $url, $code );
		}
	}

	$issues = array();
	$list   = static function ( array $items ) {
		return implode( ', ', array_slice( $items, 0, 4 ) ) . ( count( $items ) > 4 ? ' and ' . ( count( $items ) - 4 ) . ' more' : '' );
	};

	if ( $broken ) {
		$issues[] = array(
			'level'   => 'error',
			'code'    => 'content_broken_link',
			'message' => lcp_yeast_seo_count_noun( count( $broken ), 'broken link' ) . ': ' . $list( $broken ) . '.',
		);
	}

	if ( $unreachable ) {
		$issues[] = array(
			'level'   => 'warning',
			'code'    => 'content_link_unreachable',
			'message' => lcp_yeast_seo_count_noun( count( $unreachable ), 'link' ) . ' did not answer properly: ' . $list( $unreachable ) . '.',
		);
	}

	return $issues;
}

/**
 * Pages that no other scanned page links to.
 *
 * Only meaningful when every page has been scanned, since a link could come
 * from one that hasn't been; the caller checks that.
 *
 * @param int[]   $ids     Page IDs in scope.
 * @param array   $inbound Page ID => IDs of the scanned pages it links to.
 * @param int|int[] $exempt Pages that are never orphans (the front page).
 * @return int[]
 */
function lcp_yeast_seo_health_orphans( array $ids, array $inbound, $exempt = 0 ) {
	$linked = array();

	foreach ( $ids as $id ) {
		foreach ( (array) ( $inbound[ $id ] ?? array() ) as $target ) {
			if ( (int) $target !== (int) $id ) {
				$linked[ (int) $target ] = true;
			}
		}
	}

	$exempt = array_map( 'intval', (array) $exempt );

	return array_values(
		array_filter(
			array_map( 'intval', $ids ),
			static function ( $id ) use ( $linked, $exempt ) {
				return ! isset( $linked[ $id ] ) && ! in_array( $id, $exempt, true );
			}
		)
	);
}

/**
 * Link key => post ID for every page the scan covers, built once per request.
 *
 * @return array<string,int>
 */
function lcp_yeast_seo_health_permalink_map() {
	static $map = null;

	if ( null === $map ) {
		$map = array();

		foreach ( lcp_yeast_seo_health_page_ids() as $id ) {
			$key = lcp_yeast_seo_health_link_key( (string) get_permalink( $id ) );

			if ( '' !== $key ) {
				$map[ $key ] = (int) $id;
			}
		}
	}

	return $map;
}

/**
 * Stored link statuses.
 *
 * @return array URL => code, time.
 */
function lcp_yeast_seo_health_link_statuses() {
	$stored = get_option( LCP_YEAST_SEO_HEALTH_LINKS_OPTION, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Checks one link and returns its HTTP status, or 0 when nothing answered.
 *
 * Links on this site go through the scan's own fetch (which can reach the site
 * even when its public address is blocked). Other sites are asked with a HEAD
 * request through WordPress's safe request functions, which refuse private
 * addresses, falling back to a small GET for servers that don't allow HEAD.
 *
 * @param string $url Absolute URL.
 * @return int
 */
function lcp_yeast_seo_health_check_link( $url ) {
	$args = array(
		'timeout'     => 8,
		'redirection' => 3,
		'user-agent'  => 'Mozilla/5.0 (compatible; Yeast SEO link check)',
		'headers'     => array( 'Cache-Control' => 'no-cache' ),
	);

	if ( wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$response = lcp_yeast_seo_health_fetch( $url, $args + array( 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'limit_response_size' => 2048 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	$response = wp_safe_remote_head( $url, $args );
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

	if ( 0 === $code || in_array( $code, array( 400, 403, 405, 406, 501 ), true ) ) {
		$retry = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 2048 ) );
		$code  = is_wp_error( $retry ) ? $code : (int) wp_remote_retrieve_response_code( $retry );
	}

	return $code;
}

/**
 * AJAX: checks the next links that need it, within a time budget, and returns
 * the refreshed dashboard and how many are left.
 *
 * The links come from the stored scan, never from the request, so this can't
 * be used to make the server fetch an address of the caller's choosing.
 *
 * @return void
 */
function lcp_yeast_seo_health_ajax_links() {
	check_ajax_referer( 'lcp_yeast_seo_health' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
	}

	ignore_user_abort( true );

	if ( ! empty( $_POST['reset'] ) ) {
		delete_option( LCP_YEAST_SEO_HEALTH_LINKS_OPTION );
	}

	$ids      = lcp_yeast_seo_health_page_ids();
	$results  = array_intersect_key( lcp_yeast_seo_health_results(), array_flip( $ids ) );
	$statuses = lcp_yeast_seo_health_link_statuses();
	$pending  = lcp_yeast_seo_health_pending_links( $results, $statuses, time(), (int) apply_filters( 'lcp_yeast_seo_health_max_links', 1500 ) );
	$started  = microtime( true );
	$checked  = 0;

	foreach ( $pending as $url ) {
		if ( microtime( true ) - $started > LCP_YEAST_SEO_HEALTH_LINK_BUDGET ) {
			break;
		}

		$statuses[ $url ] = array(
			'code' => lcp_yeast_seo_health_check_link( $url ),
			'time' => time(),
		);
		++$checked;
	}

	update_option( LCP_YEAST_SEO_HEALTH_LINKS_OPTION, $statuses, false );

	ob_start();
	lcp_yeast_seo_health_render_summary();
	$summary = ob_get_clean();

	ob_start();
	lcp_yeast_seo_health_render_list();
	$list = ob_get_clean();

	wp_send_json_success(
		array(
			'summary'   => $summary,
			'list'      => $list,
			'checked'   => $checked,
			'remaining' => max( 0, count( $pending ) - $checked ),
		)
	);
}
add_action( 'wp_ajax_lcp_yeast_seo_health_links', 'lcp_yeast_seo_health_ajax_links' );

/**
 * Adds the checks that depend on other pages' results: broken links, and orphans.
 *
 * @param int[]      $ids      Page IDs in scope.
 * @param array      $results  Stored results.
 * @param array|null $statuses Link statuses, or null to load the stored ones.
 * @return array The results, with those issues added and the status redone.
 */
function lcp_yeast_seo_health_add_link_issues( array $ids, array $results, $statuses = null ) {
	$statuses = null === $statuses ? lcp_yeast_seo_health_link_statuses() : $statuses;
	$inbound  = array();
	$complete = (bool) $ids;

	foreach ( $ids as $id ) {
		if ( ! isset( $results[ $id ]['content'] ) ) {
			$complete = false;
			continue;
		}

		$inbound[ $id ] = (array) $results[ $id ]['content']['inbound'];
	}

	$orphans = $complete ? lcp_yeast_seo_health_orphans( $ids, $inbound, 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0 ) : array();

	foreach ( $ids as $id ) {
		if ( ! isset( $results[ $id ] ) ) {
			continue;
		}

		$extra = isset( $results[ $id ]['content'] ) ? lcp_yeast_seo_health_link_issues( (array) $results[ $id ]['content']['links'], $statuses ) : array();

		if ( in_array( (int) $id, $orphans, true ) ) {
			$extra[] = array(
				'level'   => 'warning',
				'code'    => 'content_orphan',
				'message' => 'No other page links to this one, so visitors and search engines can only reach it from the sitemap.',
			);
		}

		if ( $extra ) {
			$results[ $id ]['issues'] = array_merge( $results[ $id ]['issues'], $extra );
			$results[ $id ]['status'] = lcp_yeast_seo_health_status_of( $results[ $id ]['issues'] );
		}
	}

	return $results;
}
