<?php
/**
 * IndexNow: tells Bing, Yandex and other engines that a page was published, changed or removed.
 *
 * Off until switched on. Pings wait a minute so a page cache has time to clear, are batched and
 * de-duplicated, and only go out when the page really changed. Nothing is sent from local or test
 * hosts or from a site that discourages search engines. Google does not use IndexNow.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_INDEXNOW_KEY_OPTION', 'lcp_yeast_seo_indexnow_key' );
define( 'LCP_YEAST_SEO_INDEXNOW_STATE_OPTION', 'lcp_yeast_seo_indexnow_state' );
define( 'LCP_YEAST_SEO_INDEXNOW_LOG_OPTION', 'lcp_yeast_seo_indexnow_log' );
define( 'LCP_YEAST_SEO_INDEXNOW_HASH_META', '_lcp_yeast_seo_indexnow_hash' );
define( 'LCP_YEAST_SEO_INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow' );
define( 'LCP_YEAST_SEO_INDEXNOW_FALLBACK_ENDPOINT', 'https://www.bing.com/indexnow' );
define( 'LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY', 300 );
define( 'LCP_YEAST_SEO_INDEXNOW_PAUSE', 3600 );
define( 'LCP_YEAST_SEO_INDEXNOW_PAUSE_OPTION', 'lcp_yeast_seo_indexnow_pause' );
define( 'LCP_YEAST_SEO_INDEXNOW_CRON', 'lcp_yeast_seo_indexnow_flush' );
define( 'LCP_YEAST_SEO_INDEXNOW_DELAY', 60 );
define( 'LCP_YEAST_SEO_INDEXNOW_RESEND_WINDOW', 600 );
define( 'LCP_YEAST_SEO_INDEXNOW_MAX_URLS', 10000 );
define( 'LCP_YEAST_SEO_INDEXNOW_MAX_TRIES', 3 );
define( 'LCP_YEAST_SEO_INDEXNOW_LOG_SIZE', 15 );

/**
 * Whether the integration is switched on.
 *
 * @return bool
 */
function lcp_yeast_seo_indexnow_enabled() {
	return lcp_yeast_seo_flag_setting( 'indexnow', false );
}

/**
 * Whether a host is a local or test address, where no ping should ever be sent.
 *
 * @param string $host Host name.
 * @return bool
 */
function lcp_yeast_seo_indexnow_host_is_local( $host ) {
	$host = strtolower( trim( (string) $host ) );

	if ( '' === $host || 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host || '[::1]' === $host ) {
		return true;
	}

	return (bool) preg_match( '/\.(local|localhost|test|invalid|example|internal|lan)$/', $host );
}

/**
 * Whether a key has the shape IndexNow accepts: 8 to 128 letters, numbers and dashes.
 *
 * @param mixed $key Candidate key.
 * @return bool
 */
function lcp_yeast_seo_indexnow_key_is_valid( $key ) {
	return is_string( $key ) && (bool) preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key );
}

/**
 * The site's IndexNow key, made on first use.
 *
 * @return string
 */
function lcp_yeast_seo_indexnow_key() {
	$key = get_option( LCP_YEAST_SEO_INDEXNOW_KEY_OPTION, '' );

	if ( ! lcp_yeast_seo_indexnow_key_is_valid( $key ) ) {
		$key = bin2hex( random_bytes( 16 ) );
		update_option( LCP_YEAST_SEO_INDEXNOW_KEY_OPTION, $key, false );
	}

	return $key;
}

/**
 * Where the key file is served.
 *
 * @return string
 */
function lcp_yeast_seo_indexnow_key_url() {
	return home_url( '/' . lcp_yeast_seo_indexnow_key() . '.txt' );
}

/**
 * Whether a post should be pinged, from facts about the site and the post.
 *
 * @param array $facts enabled, host, blog_public, post_type, sitemap_types, status, password.
 * @return bool
 */
function lcp_yeast_seo_indexnow_eligible( array $facts ) {
	return ! empty( $facts['enabled'] )
		&& ! empty( $facts['blog_public'] )
		&& ! lcp_yeast_seo_indexnow_host_is_local( $facts['host'] ?? '' )
		&& 'publish' === ( $facts['status'] ?? '' )
		&& empty( $facts['password'] )
		&& in_array( $facts['post_type'] ?? '', (array) ( $facts['sitemap_types'] ?? array() ), true );
}

/**
 * A fingerprint of what a search engine would see of a page: its text, address and Yeast SEO settings.
 *
 * @param array $parts title, content, excerpt, slug, seo_title, seo_description, canonical, robots.
 * @return string "i:" or "n:" (indexable or noindex) followed by a hash.
 */
function lcp_yeast_seo_indexnow_fingerprint( array $parts ) {
	$robots = 'noindex' === ( $parts['robots'] ?? '' ) ? 'n' : 'i';

	return $robots . ':' . md5( (string) wp_json_encode( $parts ) );
}

/**
 * Whether a change is worth a ping, given the fingerprint saved last time and the new one.
 *
 * A page that was and still is noindex is never pinged: it is not in the index, and its edits do not matter.
 *
 * @param string $previous Saved fingerprint, '' when none.
 * @param string $current  New fingerprint.
 * @return bool
 */
function lcp_yeast_seo_indexnow_changed( $previous, $current ) {
	if ( $previous === $current ) {
		return false;
	}

	return ! ( 0 === strpos( $previous, 'n:' ) && 0 === strpos( $current, 'n:' ) );
}

/**
 * Adds URLs to the waiting list, skipping ones sent in the last few minutes.
 *
 * @param array    $state Waiting list: pending (url => [time, tries]) and sent (url => time).
 * @param string[] $urls  URLs to add.
 * @param int      $now   Current time.
 * @return array
 */
function lcp_yeast_seo_indexnow_state_add( array $state, array $urls, $now ) {
	$state = $state + array( 'pending' => array(), 'sent' => array() );

	foreach ( $state['sent'] as $url => $time ) {
		if ( $time < $now - LCP_YEAST_SEO_INDEXNOW_RESEND_WINDOW ) {
			unset( $state['sent'][ $url ] );
		}
	}

	foreach ( array_unique( $urls ) as $url ) {
		if ( isset( $state['sent'][ $url ] ) || isset( $state['pending'][ $url ] ) ) {
			continue;
		}

		$state['pending'][ $url ] = array( 'time' => $now, 'tries' => 0 );
	}

	return $state;
}

/**
 * Takes the URLs that have waited long enough, and marks them as sent.
 *
 * @param array $state Waiting list.
 * @param int   $now   Current time.
 * @return array{0:array,1:string[],2:int[]} The new state, the URLs, and how many times each has been tried.
 */
function lcp_yeast_seo_indexnow_state_take( array $state, $now ) {
	$state = $state + array( 'pending' => array(), 'sent' => array() );
	$batch = array();
	$tries = array();

	foreach ( $state['pending'] as $url => $entry ) {
		if ( count( $batch ) >= LCP_YEAST_SEO_INDEXNOW_MAX_URLS ) {
			break;
		}

		if ( $entry['time'] <= $now - LCP_YEAST_SEO_INDEXNOW_DELAY ) {
			$batch[]       = $url;
			$tries[ $url ] = (int) $entry['tries'];
			unset( $state['pending'][ $url ] );
			$state['sent'][ $url ] = $now;
		}
	}

	return array( $state, $batch, $tries );
}

/**
 * Puts URLs back on the waiting list after a failed ping, a little later, up to a few tries.
 *
 * @param array    $state Waiting list.
 * @param string[] $batch URLs that failed.
 * @param int[]    $tries How many times each has been tried before.
 * @param int      $now   Current time.
 * @param int      $delay Seconds to wait before the next try.
 * @return array
 */
function lcp_yeast_seo_indexnow_state_retry( array $state, array $batch, array $tries, $now, $delay = LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY ) {
	foreach ( $batch as $url ) {
		$count = ( $tries[ $url ] ?? 0 ) + 1;

		unset( $state['sent'][ $url ] );

		if ( $count < LCP_YEAST_SEO_INDEXNOW_MAX_TRIES ) {
			$state['pending'][ $url ] = array( 'time' => $now + $delay, 'tries' => $count );
		}
	}

	return $state;
}

/**
 * The request body for a ping.
 *
 * @param string   $host    Site host.
 * @param string   $key     The key.
 * @param string   $key_url Where the key file is served.
 * @param string[] $urls    URLs, all on this host.
 * @return array
 */
function lcp_yeast_seo_indexnow_payload( $host, $key, $key_url, array $urls ) {
	return array(
		'host'        => $host,
		'key'         => $key,
		'keyLocation' => $key_url,
		'urlList'     => array_values( array_filter( $urls, static function ( $url ) use ( $host ) {
			return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( $host );
		} ) ),
	);
}

/**
 * How to treat a response: done, try again later, or give up.
 *
 * @param int $code HTTP status, 0 for no answer.
 * @return string "ok", "retry" or "stop".
 */
function lcp_yeast_seo_indexnow_outcome( $code ) {
	if ( 200 === $code || 202 === $code ) {
		return 'ok';
	}

	return ( 0 === $code || 429 === $code || $code >= 500 ) ? 'retry' : 'stop';
}

/**
 * Whether a second endpoint is worth trying: only when the first is down (a server error or no answer).
 *
 * "Too many requests" is about the sender, not the endpoint, so asking another one only adds a second refusal.
 *
 * @param int $code HTTP status, 0 for no answer.
 * @return bool
 */
function lcp_yeast_seo_indexnow_should_fallback( $code ) {
	return 0 === (int) $code || (int) $code >= 500;
}

/**
 * How long to wait before trying again: what the server asked for in Retry-After (seconds), within reason.
 *
 * @param mixed $header The Retry-After header value, or ''.
 * @return int Seconds.
 */
function lcp_yeast_seo_indexnow_retry_delay( $header ) {
	$seconds = is_numeric( $header ) ? (int) $header : LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY;

	return max( LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY, min( 3600, $seconds ) );
}

/**
 * When sending should pause until, after a response: an hour (or what the server asked for, if longer)
 * when the search engines said "too many requests", otherwise not at all.
 *
 * @param int $code        HTTP status, 0 for no answer.
 * @param int $retry_after Seconds the server asked us to wait.
 * @param int $now         Current time.
 * @return int Time to pause until, 0 for no pause.
 */
function lcp_yeast_seo_indexnow_pause_until( $code, $retry_after, $now ) {
	return 429 === (int) $code ? $now + max( LCP_YEAST_SEO_INDEXNOW_PAUSE, (int) $retry_after ) : 0;
}

/**
 * Whether sending is paused.
 *
 * @param int $until Time the pause ends, 0 for none.
 * @param int $now   Current time.
 * @return bool
 */
function lcp_yeast_seo_indexnow_is_paused( $until, $now ) {
	return (int) $until > $now;
}

/**
 * When the next send should run: a little after the earliest URL on the list becomes due, and not before a pause ends.
 *
 * @param array $state        Waiting list.
 * @param int   $now          Current time.
 * @param int   $paused_until Time a pause ends, 0 for none.
 * @return int|null Time, or null when nothing is waiting.
 */
function lcp_yeast_seo_indexnow_next_run( array $state, $now, $paused_until = 0 ) {
	$times = array_column( (array) ( $state['pending'] ?? array() ), 'time' );

	if ( ! $times ) {
		return null;
	}

	$when = max( $now + 30, min( $times ) + LCP_YEAST_SEO_INDEXNOW_DELAY + 15 );

	return lcp_yeast_seo_indexnow_is_paused( $paused_until, $now ) ? max( $when, (int) $paused_until + 15 ) : $when;
}

/**
 * The time sending is paused until, or 0.
 *
 * @return int
 */
function lcp_yeast_seo_indexnow_paused_until() {
	$until = (int) get_option( LCP_YEAST_SEO_INDEXNOW_PAUSE_OPTION, 0 );

	return lcp_yeast_seo_indexnow_is_paused( $until, time() ) ? $until : 0;
}

/**
 * A short plain-English reading of an IndexNow response.
 *
 * @param int $code HTTP status, 0 for no answer.
 * @return string
 */
function lcp_yeast_seo_indexnow_describe( $code ) {
	$messages = array(
		0   => 'No answer. It will be tried again.',
		200 => 'Accepted.',
		202 => 'Accepted. The key is still being checked.',
		400 => 'Rejected as badly formed.',
		403 => 'The key was not accepted. Check that the key address opens.',
		422 => 'The addresses did not match this site.',
		429 => 'Too many requests. Sending pauses for a while, then tries again.',
	);

	return $messages[ $code ] ?? ( $code >= 500 ? 'The search engine had a problem. It will be tried again.' : 'Response ' . $code . '.' );
}

/**
 * The site's host name.
 *
 * @return string
 */
function lcp_yeast_seo_indexnow_host() {
	return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
}

/**
 * Whether pings can be sent from this site at all.
 *
 * @return bool
 */
function lcp_yeast_seo_indexnow_available() {
	return get_option( 'blog_public' ) && ! lcp_yeast_seo_indexnow_host_is_local( lcp_yeast_seo_indexnow_host() );
}

/**
 * A post's public address, even from a state where WordPress would give a draft link.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function lcp_yeast_seo_indexnow_post_url( $post ) {
	$public              = clone $post;
	$public->post_status = 'publish';

	return (string) get_permalink( $public );
}

/**
 * Whether a post is of a kind that is pinged, from its type, status and password.
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function lcp_yeast_seo_indexnow_post_eligible( $post ) {
	return lcp_yeast_seo_indexnow_eligible(
		array(
			'enabled'       => lcp_yeast_seo_indexnow_enabled(),
			'host'          => lcp_yeast_seo_indexnow_host(),
			'blog_public'   => (bool) get_option( 'blog_public' ),
			'post_type'     => $post->post_type,
			'sitemap_types' => lcp_yeast_seo_sitemap_post_types(),
			'status'        => $post->post_status,
			'password'      => '' !== (string) $post->post_password,
		)
	);
}

/**
 * Adds URLs to the waiting list and makes sure a send is scheduled.
 *
 * @param string[] $urls URLs.
 * @return void
 */
function lcp_yeast_seo_indexnow_queue( array $urls ) {
	$urls = array_filter( $urls );

	if ( ! $urls ) {
		return;
	}

	$state = lcp_yeast_seo_indexnow_state_add( (array) get_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, array() ), $urls, time() );

	update_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, $state, false );

	lcp_yeast_seo_indexnow_schedule( $state );
}

/**
 * Makes sure a send is scheduled for when the earliest waiting URL is due.
 *
 * @param array $state Waiting list.
 * @return void
 */
function lcp_yeast_seo_indexnow_schedule( array $state ) {
	$when = lcp_yeast_seo_indexnow_next_run( $state, time(), lcp_yeast_seo_indexnow_paused_until() );

	if ( $when && ! wp_next_scheduled( LCP_YEAST_SEO_INDEXNOW_CRON ) ) {
		wp_schedule_single_event( $when, LCP_YEAST_SEO_INDEXNOW_CRON );
	}
}

/**
 * Notices a post that was published or changed, or that stopped being public.
 *
 * @param int          $post_id     Post ID.
 * @param WP_Post      $post        Post after the save.
 * @param bool         $update      Whether it updated an existing post.
 * @param WP_Post|null $post_before Post before the save.
 * @return void
 */
function lcp_yeast_seo_indexnow_after_insert_post( $post_id, $post, $update, $post_before = null ) {
	if ( ! lcp_yeast_seo_indexnow_enabled() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// No longer public (back to draft, say): tell them to look again. Trash is handled before it happens.
	if ( $post_before && 'publish' === $post_before->post_status && 'publish' !== $post->post_status && 'trash' !== $post->post_status && lcp_yeast_seo_indexnow_post_eligible( $post_before ) ) {
		lcp_yeast_seo_indexnow_queue( array( lcp_yeast_seo_indexnow_post_url( $post_before ) ) );
		delete_post_meta( $post_id, LCP_YEAST_SEO_INDEXNOW_HASH_META );
		return;
	}

	if ( ! lcp_yeast_seo_indexnow_post_eligible( $post ) ) {
		return;
	}

	$fingerprint = lcp_yeast_seo_indexnow_fingerprint(
		array(
			'title'           => $post->post_title,
			'content'         => $post->post_content,
			'excerpt'         => $post->post_excerpt,
			'slug'            => $post->post_name,
			'seo_title'       => (string) get_post_meta( $post_id, LCP_YEAST_SEO_TITLE_META_KEY, true ),
			'seo_description' => (string) get_post_meta( $post_id, LCP_YEAST_SEO_DESCRIPTION_META_KEY, true ),
			'canonical'       => (string) get_post_meta( $post_id, LCP_YEAST_SEO_CANONICAL_META_KEY, true ),
			'robots'          => (string) get_post_meta( $post_id, LCP_YEAST_SEO_ROBOTS_META_KEY, true ),
		)
	);

	if ( ! lcp_yeast_seo_indexnow_changed( (string) get_post_meta( $post_id, LCP_YEAST_SEO_INDEXNOW_HASH_META, true ), $fingerprint ) ) {
		return;
	}

	update_post_meta( $post_id, LCP_YEAST_SEO_INDEXNOW_HASH_META, $fingerprint );

	// A slug change moves the page: the old address needs a look too.
	$urls = array( lcp_yeast_seo_indexnow_post_url( $post ) );

	if ( $post_before && $post_before->post_name !== $post->post_name && 'publish' === $post_before->post_status ) {
		$urls[] = lcp_yeast_seo_indexnow_post_url( $post_before );
	}

	lcp_yeast_seo_indexnow_queue( $urls );
}
add_action( 'wp_after_insert_post', 'lcp_yeast_seo_indexnow_after_insert_post', 20, 4 );

/**
 * Notices a published post about to be trashed or deleted, while its address is still right.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function lcp_yeast_seo_indexnow_before_removal( $post_id ) {
	$post = get_post( $post_id );

	if ( $post && lcp_yeast_seo_indexnow_enabled() && lcp_yeast_seo_indexnow_post_eligible( $post ) ) {
		lcp_yeast_seo_indexnow_queue( array( lcp_yeast_seo_indexnow_post_url( $post ) ) );
	}
}
add_action( 'wp_trash_post', 'lcp_yeast_seo_indexnow_before_removal' );
add_action( 'before_delete_post', 'lcp_yeast_seo_indexnow_before_removal' );

/**
 * Sends a list of URLs and records what came back.
 *
 * Tries the shared IndexNow endpoint first. If that is down (a server error or no answer) it tries
 * Bing's own, which takes the same request and shares it with the other engines. A "too many requests"
 * answer is not retried against the second endpoint: the limit is on the sender.
 *
 * @param string[] $urls URLs on this site.
 * @return array{code:int,retry_after:int,paused_until:int} The final HTTP status (0 for no answer), how long to wait before trying again, and the time sending is paused until (0 for no pause).
 */
function lcp_yeast_seo_indexnow_send( array $urls ) {
	$payload = lcp_yeast_seo_indexnow_payload( lcp_yeast_seo_indexnow_host(), lcp_yeast_seo_indexnow_key(), lcp_yeast_seo_indexnow_key_url(), $urls );
	$code    = 0;
	$delay   = LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY;

	$tried = array();
	$reply = '';

	foreach ( array( LCP_YEAST_SEO_INDEXNOW_ENDPOINT, LCP_YEAST_SEO_INDEXNOW_FALLBACK_ENDPOINT ) as $endpoint ) {
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$reply    = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
		$delay    = lcp_yeast_seo_indexnow_retry_delay( is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'retry-after' ) );
		$tried[]  = (string) wp_parse_url( $endpoint, PHP_URL_HOST );

		if ( ! lcp_yeast_seo_indexnow_should_fallback( $code ) ) {
			break;
		}
	}

	// One row per attempt, naming each endpoint that was asked.
	lcp_yeast_seo_indexnow_log( count( $payload['urlList'] ), $code, implode( ' then ', $tried ), $reply );

	$until = lcp_yeast_seo_indexnow_pause_until( $code, $delay, time() );

	if ( $until ) {
		update_option( LCP_YEAST_SEO_INDEXNOW_PAUSE_OPTION, $until, false );
	} elseif ( 'ok' === lcp_yeast_seo_indexnow_outcome( $code ) ) {
		delete_option( LCP_YEAST_SEO_INDEXNOW_PAUSE_OPTION );
	}

	return array( 'code' => $code, 'retry_after' => $delay, 'paused_until' => $until );
}

/**
 * Adds an entry to the short log of pings.
 *
 * @param int    $count How many URLs were sent.
 * @param int    $code  HTTP status, 0 for no answer.
 * @param string $via   The host it was sent to.
 * @param string $reply What the server said, if anything.
 * @return void
 */
function lcp_yeast_seo_indexnow_log( $count, $code, $via = '', $reply = '' ) {
	$log = (array) get_option( LCP_YEAST_SEO_INDEXNOW_LOG_OPTION, array() );

	array_unshift(
		$log,
		array(
			'time'  => time(),
			'count' => (int) $count,
			'code'  => (int) $code,
			'via'   => (string) $via,
			'reply' => mb_substr( trim( wp_strip_all_tags( (string) $reply ) ), 0, 160 ),
		)
	);
	update_option( LCP_YEAST_SEO_INDEXNOW_LOG_OPTION, array_slice( $log, 0, LCP_YEAST_SEO_INDEXNOW_LOG_SIZE ), false );
}

/**
 * Sends what has waited long enough. Runs from the scheduler.
 *
 * @return void
 */
function lcp_yeast_seo_indexnow_flush() {
	$state = (array) get_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, array() );

	if ( ! lcp_yeast_seo_indexnow_enabled() || ! lcp_yeast_seo_indexnow_available() ) {
		delete_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION );
		return;
	}

	// Paused after a "too many requests": leave everything on the list and come back when it ends.
	if ( lcp_yeast_seo_indexnow_paused_until() ) {
		lcp_yeast_seo_indexnow_schedule( $state );
		return;
	}

	list( $state, $batch, $tries ) = lcp_yeast_seo_indexnow_state_take( $state, time() );

	if ( $batch ) {
		$result = lcp_yeast_seo_indexnow_send( $batch );

		if ( 'retry' === lcp_yeast_seo_indexnow_outcome( $result['code'] ) ) {
			$state = lcp_yeast_seo_indexnow_state_retry( $state, $batch, $tries, time(), $result['retry_after'] );
		}
	}

	update_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, $state, false );

	lcp_yeast_seo_indexnow_schedule( $state );
}
add_action( LCP_YEAST_SEO_INDEXNOW_CRON, 'lcp_yeast_seo_indexnow_flush' );

/**
 * Serves the key file at /{key}.txt, which is how the engines check the site is yours.
 *
 * @return void
 */
function lcp_yeast_seo_indexnow_serve_key_file() {
	if ( is_admin() || ! lcp_yeast_seo_indexnow_enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with the key.
	$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$key  = lcp_yeast_seo_indexnow_key();

	if ( trailingslashit( $base ) . $key . '.txt' !== $path ) {
		return;
	}

	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a generated key of letters, numbers and dashes.
	exit;
}
add_action( 'init', 'lcp_yeast_seo_indexnow_serve_key_file', 1 );

/**
 * The URLs of every published page that is in the sitemap and not noindex.
 *
 * @return string[]
 */
function lcp_yeast_seo_indexnow_all_urls() {
	$urls = array();

	foreach ( lcp_yeast_seo_sitemap_post_types() as $post_type ) {
		foreach ( lcp_yeast_seo_sitemap_post_ids( $post_type ) as $post_id ) {
			$urls[] = get_permalink( $post_id );
		}
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/**
 * Handles "Send every page now".
 *
 * @return void
 */
function lcp_yeast_seo_indexnow_handle_submit_all() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( 'lcp_yeast_seo_indexnow_all' );

	if ( ! lcp_yeast_seo_indexnow_enabled() || ! lcp_yeast_seo_indexnow_available() ) {
		$message = array( false, 'Nothing was sent. Pings are off, or this site is hidden from search engines or on a local address.' );
	} elseif ( lcp_yeast_seo_indexnow_paused_until() ) {
		// Already told to slow down: queue everything for when the pause ends, and send nothing now.
		$urls  = lcp_yeast_seo_indexnow_all_urls();
		$state = lcp_yeast_seo_indexnow_state_add( (array) get_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, array() ), $urls, time() );

		update_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, $state, false );
		lcp_yeast_seo_indexnow_schedule( $state );

		$message = array( false, sprintf( 'Sending is paused until %s because the search engines asked us to slow down. The %s are queued and will go out automatically then.', wp_date( 'H:i', lcp_yeast_seo_indexnow_paused_until() ), lcp_yeast_seo_count_noun( count( $urls ), 'page' ) ) );
	} else {
		$urls   = lcp_yeast_seo_indexnow_all_urls();
		$sent   = 0;
		$result = array( 'code' => 200, 'retry_after' => LCP_YEAST_SEO_INDEXNOW_RETRY_DELAY );

		foreach ( array_chunk( $urls, LCP_YEAST_SEO_INDEXNOW_MAX_URLS ) as $chunk ) {
			$result  = lcp_yeast_seo_indexnow_send( $chunk );
			$outcome = lcp_yeast_seo_indexnow_outcome( $result['code'] );

			if ( 'ok' === $outcome ) {
				$sent += count( $chunk );
				continue;
			}

			// Busy or no answer: keep the rest, and try again on its own a few minutes later.
			if ( 'retry' === $outcome ) {
				$left  = array_slice( $urls, $sent );
				$state = lcp_yeast_seo_indexnow_state_retry( (array) get_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, array() ), $left, array(), time(), $result['retry_after'] );

				update_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, $state, false );
				lcp_yeast_seo_indexnow_schedule( $state );
			}

			break;
		}

		$outcome = lcp_yeast_seo_indexnow_outcome( $result['code'] );

		if ( 'ok' === $outcome ) {
			$message = array( true, sprintf( 'Sent %s to the search engines. %s', lcp_yeast_seo_count_noun( $sent, 'page' ), lcp_yeast_seo_indexnow_describe( $result['code'] ) ) );
		} elseif ( 'retry' === $outcome ) {
			$message = array( false, sprintf( 'The search engines asked us to slow down (%s). The %s have been queued and %s', $result['code'] ? 'response ' . $result['code'] : 'no answer', lcp_yeast_seo_count_noun( count( $urls ) - $sent, 'page' ), ! empty( $result['paused_until'] ) ? 'will go out automatically after a pause until ' . wp_date( 'H:i', $result['paused_until'] ) . '.' : sprintf( 'will be tried again automatically in about %d minutes, up to %d times.', (int) ceil( $result['retry_after'] / 60 ), LCP_YEAST_SEO_INDEXNOW_MAX_TRIES ) ) );
		} else {
			$message = array( false, 'The send failed and will not be retried. ' . lcp_yeast_seo_indexnow_describe( $result['code'] ) );
		}
	}

	set_transient( 'lcp_yeast_seo_indexnow_notice_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lcp-yeast-seo' ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_indexnow_submit_all', 'lcp_yeast_seo_indexnow_handle_submit_all' );

/**
 * Intro text for the settings card.
 *
 * @return void
 */
function lcp_yeast_seo_render_indexnow_intro() {
	echo '<p>Tell Bing, Yandex and other search engines the moment a page is published, changed or removed, instead of waiting for them to visit. Google does not use this, so it does not replace your sitemap.</p>';
}

/**
 * The form the "Send every page now" button belongs to. It lives outside the settings form, which cannot hold another.
 *
 * @return void
 */
function lcp_yeast_seo_render_indexnow_form() {
	?>
	<form id="lcp-yeast-seo-indexnow-all" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lcp_yeast_seo_indexnow_submit_all">
		<?php wp_nonce_field( 'lcp_yeast_seo_indexnow_all' ); ?>
	</form>
	<?php
}

/**
 * The settings card's controls: the tick, the key, the latest pings and the send-everything button.
 *
 * @return void
 */
function lcp_yeast_seo_render_indexnow_field() {
	$option    = esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION );
	$enabled   = lcp_yeast_seo_indexnow_enabled();
	$available = lcp_yeast_seo_indexnow_available();
	$notice    = get_transient( 'lcp_yeast_seo_indexnow_notice_' . get_current_user_id() );
	$log       = array_slice( (array) get_option( LCP_YEAST_SEO_INDEXNOW_LOG_OPTION, array() ), 0, 5 );
	$paused    = lcp_yeast_seo_indexnow_paused_until();

	if ( is_array( $notice ) ) {
		delete_transient( 'lcp_yeast_seo_indexnow_notice_' . get_current_user_id() );
		?>
		<div class="notice notice-<?php echo $notice[0] ? 'success' : 'error'; ?> inline"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php
	}
	?>
	<label>
		<input type="hidden" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[indexnow]" value="0">
		<input type="checkbox" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[indexnow]" value="1" <?php checked( $enabled ); ?>>
		Send a ping when a page is published, changed or removed
	</label>
	<p class="description">Only pages in your sitemap that can be indexed are sent, a minute after the change so a page cache can clear. A page is sent only when its text, address or Yeast SEO settings really changed.</p>
	<?php if ( ! $available ) : ?>
		<p class="description"><strong>Nothing will be sent from this site.</strong> <?php echo esc_html( get_option( 'blog_public' ) ? 'It is on a local or test address.' : 'It is set to discourage search engines.' ); ?></p>
	<?php endif; ?>
	<?php if ( $enabled ) : ?>
		<p class="description">Key file: <a href="<?php echo esc_url( lcp_yeast_seo_indexnow_key_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( lcp_yeast_seo_indexnow_key_url() ); ?></code></a></p>
		<?php if ( $paused ) : ?>
			<p class="description"><strong>Paused until <?php echo esc_html( wp_date( 'H:i', $paused ) ); ?></strong> because the search engines asked us to slow down. Pages published or changed meanwhile, and anything waiting, go out automatically after that.</p>
		<?php endif; ?>
		<p>
			<button type="submit" class="button" form="lcp-yeast-seo-indexnow-all" <?php disabled( ! $available || $paused ); ?>>Send every page now</button>
			<span class="description">Use it once after switching this on, or after a big change.</span>
		</p>
		<?php if ( $log ) : ?>
			<ul class="lcp-ys-pings">
				<?php foreach ( $log as $entry ) : ?>
					<li>
						<span><?php echo esc_html( human_time_diff( (int) $entry['time'] ) . ' ago' ); ?></span>
						<span><?php echo esc_html( lcp_yeast_seo_count_noun( (int) $entry['count'], 'page' ) ); ?></span>
						<span>
							<?php echo esc_html( lcp_yeast_seo_indexnow_describe( (int) $entry['code'] ) . ( ! empty( $entry['via'] ) ? ' (' . $entry['via'] . ')' : '' ) ); ?>
							<?php if ( ! empty( $entry['reply'] ) && ! in_array( (int) $entry['code'], array( 200, 202 ), true ) ) : ?>
								<em class="lcp-ys-pings__reply"><?php echo esc_html( 'They said: ' . $entry['reply'] ); ?></em>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="description">Nothing has been sent yet.</p>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}
