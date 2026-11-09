<?php
/**
 * robots.txt editor, with safeguards.
 *
 * WordPress builds robots.txt itself only when no physical file exists, so
 * the editor detects a physical file (which the web server serves instead)
 * and can take it over. Saving is blocked when the rules would hide the site
 * from search engines or break rendering, unless explicitly overridden.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_ROBOTS_MAX_BYTES', 500 * 1024 );

/**
 * Path of a physical robots.txt in the web root.
 *
 * @return string
 */
function lcp_yeast_seo_robots_file_path() {
	return ABSPATH . 'robots.txt';
}

/**
 * What WordPress itself serves when no custom rules are saved.
 *
 * @return string
 */
function lcp_yeast_seo_default_robots_txt() {
	$path = (string) wp_parse_url( site_url(), PHP_URL_PATH );

	return "User-agent: *\nDisallow: {$path}/wp-admin/\nAllow: {$path}/wp-admin/admin-ajax.php\n";
}

/**
 * Normalises robots.txt text: UTF-8, Unix line endings, no control characters
 * or trailing spaces. Deliberately not sanitize_textarea_field(), which strips
 * %-encoded octets that are legitimate in robots rules.
 *
 * @param mixed $text Raw text.
 * @return string
 */
function lcp_yeast_seo_clean_robots_text( $text ) {
	$text = is_string( $text ) ? $text : '';
	$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : $text;
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
	$text = preg_replace( '/[^\P{C}\n\t]+/u', '', $text );
	$text = preg_replace( '/[ \t]+$/m', '', (string) $text );

	return trim( (string) $text ) . ( '' === trim( (string) $text ) ? '' : "\n" );
}

/**
 * Splits robots.txt into directives, ignoring comments and blank lines.
 *
 * @param string $text robots.txt content.
 * @return array[] Each: n (line number), name (lowercase), value.
 */
function lcp_yeast_seo_parse_robots( $text ) {
	$lines = array();

	foreach ( explode( "\n", (string) $text ) as $index => $line ) {
		$line = trim( preg_replace( '/#.*$/', '', $line ) );

		if ( '' === $line ) {
			continue;
		}

		$parts   = explode( ':', $line, 2 );
		$lines[] = array(
			'n'     => $index + 1,
			'name'  => strtolower( trim( $parts[0] ) ),
			'value' => isset( $parts[1] ) ? trim( $parts[1] ) : '',
		);
	}

	return $lines;
}

/**
 * Checks robots.txt rules for mistakes that could hurt the site.
 *
 * Errors block saving unless overridden; warnings are shown but allow it.
 *
 * @param string $text robots.txt content.
 * @return array[] Each: level ('error' or 'warning'), message.
 */
function lcp_yeast_seo_analyze_robots( $text ) {
	$issues = array();
	$push   = static function ( $level, $message ) use ( &$issues ) {
		$issues[] = array(
			'level'   => $level,
			'message' => $message,
		);
	};

	if ( strlen( (string) $text ) > LCP_YEAST_SEO_ROBOTS_MAX_BYTES ) {
		$push( 'error', 'The file is over 500 KiB. Google ignores everything past that limit.' );
	}

	$known      = array( 'user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay', 'host', 'clean-param', 'content-signal' );
	$important  = array( '*', 'googlebot', 'bingbot' );
	$agents     = array();
	$in_agents  = false;
	$has_agent  = false;
	$seen_error = array();

	foreach ( lcp_yeast_seo_parse_robots( $text ) as $line ) {
		$name  = $line['name'];
		$value = $line['value'];
		$at    = 'Line ' . $line['n'] . ': ';

		if ( ! in_array( $name, $known, true ) ) {
			$hint = 'noindex' === $name ? 'Google ignores "noindex" in robots.txt; use a robots meta tag instead.' : '"' . $name . '" is not a standard robots.txt directive and is probably a typo.';
			$push( 'warning', $at . $hint );
			continue;
		}

		if ( 'user-agent' === $name ) {
			if ( ! $in_agents ) {
				$agents    = array();
				$in_agents = true;
			}

			$agents[]  = strtolower( $value );
			$has_agent = true;
			continue;
		}

		$in_agents = false;

		if ( 'sitemap' === $name ) {
			if ( ! preg_match( '#^https?://#i', $value ) ) {
				$push( 'warning', $at . 'The Sitemap line should be a full URL starting with https://.' );
			}
			continue;
		}

		if ( ! in_array( $name, array( 'allow', 'disallow', 'crawl-delay', 'clean-param', 'host', 'content-signal' ), true ) ) {
			continue;
		}

		if ( ! $has_agent ) {
			$push( 'warning', $at . 'This rule comes before any User-agent line, so crawlers ignore it.' );
			continue;
		}

		if ( 'disallow' !== $name || '' === $value ) {
			continue;
		}

		$applies = array_intersect( $agents, $important );

		if ( ! $applies ) {
			continue;
		}

		$who = implode( ', ', array_unique( $applies ) );

		if ( '/' === $value || '/*' === $value ) {
			$key = 'all:' . $who;

			if ( ! isset( $seen_error[ $key ] ) ) {
				$seen_error[ $key ] = true;
				$push( 'error', $at . 'Disallow: / blocks the whole site for ' . $who . ', which removes it from search results.' );
			}
			continue;
		}

		if ( preg_match( '#^/wp-content/?$|^/wp-content/uploads#i', $value ) ) {
			$push( 'warning', $at . 'Blocking ' . $value . ' hides your images from search engines.' );
		} elseif ( preg_match( '#^/wp-includes|^/wp-content/(themes|plugins)|\.(css|js)\b#i', $value ) ) {
			$push( 'warning', $at . 'Blocking ' . $value . ' stops Google loading the CSS and JavaScript it needs to render your pages.' );
		}
	}

	if ( '' !== trim( (string) $text ) && ! $has_agent ) {
		$push( 'warning', 'There is no User-agent line, so none of these rules will be applied.' );
	}

	return $issues;
}

/**
 * Sanitizes the robots.txt setting on save, applying the safeguards.
 *
 * Serious problems keep the previously saved rules (and say why) unless the
 * "save anyway" box was ticked; warnings are shown but the rules are saved.
 * Nothing is reported when the text is unchanged, so saving another tab
 * doesn't repeat old notices.
 *
 * @param array $input Raw submitted settings.
 * @return string
 */
function lcp_yeast_seo_sanitize_robots_setting( $input ) {
	$previous = (string) lcp_yeast_seo_get_setting( 'robots_txt', '' );

	if ( ! isset( $input['robots_txt'] ) ) {
		return $previous;
	}

	$text = lcp_yeast_seo_clean_robots_text( wp_unslash( $input['robots_txt'] ) );

	if ( $text === $previous ) {
		return $previous;
	}

	$issues = lcp_yeast_seo_analyze_robots( $text );
	$errors = array_filter(
		$issues,
		static function ( $issue ) {
			return 'error' === $issue['level'];
		}
	);

	if ( $errors && empty( $input['robots_override'] ) ) {
		foreach ( $errors as $issue ) {
			add_settings_error( LCP_YEAST_SEO_SETTINGS_OPTION, 'lcp_yeast_seo_robots', 'robots.txt not saved: ' . $issue['message'] . ' Tick "Save anyway" if you really mean it.', 'error' );
		}

		// Keep what was typed in the editor so it can be fixed rather than retyped.
		set_transient( 'lcp_yeast_seo_robots_draft_' . get_current_user_id(), $text, 5 * MINUTE_IN_SECONDS );

		return $previous;
	}

	foreach ( $issues as $issue ) {
		add_settings_error( LCP_YEAST_SEO_SETTINGS_OPTION, 'lcp_yeast_seo_robots', 'robots.txt saved, but: ' . $issue['message'], 'warning' );
	}

	return $text;
}

/**
 * Serves the saved rules in place of WordPress's default robots.txt.
 *
 * Stands down when Yoast is active. The "discourage search engines" setting
 * doesn't change robots.txt in current WordPress (it noindexes pages instead),
 * so the saved rules are served either way.
 *
 * @param string $output Content so far.
 * @return string
 */
function lcp_yeast_seo_custom_robots_txt( $output ) {
	$custom = trim( (string) lcp_yeast_seo_get_setting( 'robots_txt', '' ) );

	if ( '' === $custom || lcp_yeast_seo_yoast_active() ) {
		return $output;
	}

	return $custom . "\n";
}
add_filter( 'robots_txt', 'lcp_yeast_seo_custom_robots_txt', 5 );

/**
 * Asks the live site for its robots.txt, to confirm the saved rules are what
 * visitors get (a physical file or a cache would say otherwise).
 *
 * @return array{status:string,message:string} status is match, differs or unknown.
 */
function lcp_yeast_seo_check_live_robots() {
	$saved = trim( (string) lcp_yeast_seo_get_setting( 'robots_txt', '' ) );

	if ( '' === $saved ) {
		return array(
			'status'  => 'unknown',
			'message' => 'No custom rules are saved, so WordPress serves its default.',
		);
	}

	$response = wp_remote_get(
		add_query_arg( 'lcp_check', time(), home_url( '/robots.txt' ) ),
		array(
			'timeout'   => 8,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return array(
			'status'  => 'unknown',
			'message' => 'Could not fetch the live robots.txt to compare.',
		);
	}

	$live = trim( (string) wp_remote_retrieve_body( $response ) );

	return 0 === strpos( $live, $saved ) ? array(
		'status'  => 'match',
		'message' => 'The live robots.txt matches the saved rules.',
	) : array(
		'status'  => 'differs',
		'message' => 'The live robots.txt is different from the saved rules. A physical robots.txt file, a cache, or the "discourage search engines" setting is overriding it.',
	);
}

/**
 * Takes over from a physical robots.txt: copies its rules into the editor and
 * renames the file out of the way (kept, with a timestamp, so it can be
 * restored).
 *
 * @return void
 */
function lcp_yeast_seo_handle_robots_takeover() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( 'lcp_yeast_seo_robots_takeover' );

	$file    = lcp_yeast_seo_robots_file_path();
	$message = '';
	$ok      = false;

	if ( ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) > LCP_YEAST_SEO_ROBOTS_MAX_BYTES ) {
		$message = 'There is no readable robots.txt file to take over.';
	} else {
		$text     = lcp_yeast_seo_clean_robots_text( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );

		$settings['robots_txt'] = $text;
		update_option( LCP_YEAST_SEO_SETTINGS_OPTION, $settings );

		$backup = $file . '.bak-' . gmdate( 'Ymd-His' );

		if ( @rename( $file, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			$ok      = true;
			$message = 'The rules were copied into the editor and the physical file was renamed to ' . basename( $backup ) . '. WordPress now serves robots.txt. Purge your host cache if the old one still appears.';
		} else {
			$message = 'The rules were copied into the editor, but the physical robots.txt could not be renamed (file permissions). Delete or rename it yourself, or the editor will have no effect.';
		}
	}

	set_transient( 'lcp_yeast_seo_robots_notice_' . get_current_user_id(), array( $ok, $message ), 5 * MINUTE_IN_SECONDS );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lcp-yeast-seo', 'tab' => 'robots' ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_robots_takeover', 'lcp_yeast_seo_handle_robots_takeover' );

/**
 * Registers the robots.txt section on the settings page.
 *
 * @return void
 */
function lcp_yeast_seo_register_robots_settings() {
	add_settings_section( 'lcp_yeast_seo_robots', 'robots.txt', 'lcp_yeast_seo_render_robots_intro', 'lcp-yeast-seo-robots' );
	add_settings_field( 'robots_txt', 'Rules', 'lcp_yeast_seo_render_robots_field', 'lcp-yeast-seo-robots', 'lcp_yeast_seo_robots' );
}
add_action( 'admin_menu', 'lcp_yeast_seo_register_robots_settings', 11 );

/**
 * Intro, status and the physical-file notice.
 *
 * @return void
 */
function lcp_yeast_seo_render_robots_intro() {
	$key    = 'lcp_yeast_seo_robots_notice_' . get_current_user_id();
	$notice = get_transient( $key );

	if ( is_array( $notice ) ) {
		delete_transient( $key );
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', $notice[0] ? 'success' : 'warning', esc_html( $notice[1] ) );
	}

	if ( lcp_yeast_seo_yoast_active() ) {
		echo '<p>Yoast SEO is active and manages robots.txt, so these rules are not used.</p>';
		return;
	}

	echo '<p>Leave this empty to keep WordPress\'s default robots.txt. When rules are saved here they replace it, and a <code>Sitemap:</code> line for your sitemap index is added automatically when sitemaps are on.</p>';

	if ( ! get_option( 'blog_public' ) ) {
		echo '<div class="notice notice-warning inline"><p>WordPress is set to discourage search engines (Settings, Reading), so every page is sent with noindex whatever these rules say.</p></div>';
	}

	$file = lcp_yeast_seo_robots_file_path();

	if ( is_file( $file ) ) {
		$contents = is_readable( $file ) && filesize( $file ) <= LCP_YEAST_SEO_ROBOTS_MAX_BYTES ? lcp_yeast_seo_clean_robots_text( (string) file_get_contents( $file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		?>
		<div class="notice notice-warning inline">
			<p><strong>A physical robots.txt file exists</strong> in the site root. The web server serves that file instead of WordPress, so the rules below have no effect until it is removed.</p>
			<p>
				<button type="button" class="button" data-robots-copy="<?php echo esc_attr( $contents ); ?>">Copy its rules into the editor</button>
				<button type="submit" class="button button-primary" form="lcp-yeast-seo-robots-takeover" onclick="return window.confirm('Copy the rules into the editor and rename the physical robots.txt out of the way? The file is kept, with a timestamp, so you can restore it.');">Take over: copy and rename the file</button>
			</p>
		</div>
		<?php
	}
}

/**
 * The editor, safeguard override and live check.
 *
 * @return void
 */
function lcp_yeast_seo_render_robots_field() {
	if ( lcp_yeast_seo_yoast_active() ) {
		return;
	}

	$name  = LCP_YEAST_SEO_SETTINGS_OPTION;
	$value = (string) lcp_yeast_seo_get_setting( 'robots_txt', '' );
	$draft = get_transient( 'lcp_yeast_seo_robots_draft_' . get_current_user_id() );

	if ( is_string( $draft ) ) {
		delete_transient( 'lcp_yeast_seo_robots_draft_' . get_current_user_id() );
		$value = $draft;
		echo '<p class="description" style="color:#b32d2e;"><strong>These rules are not saved yet.</strong> They are what you typed, kept here so you can fix them.</p>';
	}
	?>
	<textarea name="<?php echo esc_attr( $name ); ?>[robots_txt]" rows="16" class="large-text code" style="font-family:monospace;" data-robots-editor spellcheck="false"><?php echo esc_textarea( $value ); ?></textarea>
	<p>
		<button type="button" class="button" data-robots-default="<?php echo esc_attr( lcp_yeast_seo_default_robots_txt() ); ?>">Insert WordPress default</button>
		<a class="button-link" style="margin-left:1em;" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener">View the live file</a>
	</p>
	<p class="description">Saving is blocked if the rules would hide the whole site from Google or Bing, or exceed the 500 KiB limit. Other likely mistakes (blocked CSS, JavaScript or images, typos in directives) are flagged but saved.</p>
	<label style="display:block;margin-top:8px;">
		<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[robots_override]" value="1">
		Save anyway, even if the checks above find a serious problem
	</label>
	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['tab'] ) && 'robots' === $_GET['tab'] ) {
		$check = lcp_yeast_seo_check_live_robots();
		printf( '<p class="description" style="margin-top:10px;"><strong>Live check:</strong> %s</p>', esc_html( $check['message'] ) );
	}
}
