<?php
/**
 * The WordPress dashboard widget: what needs attention, from results already stored.
 *
 * Nothing here scans or fetches anything, so the dashboard stays quick.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many days old a scan can be before the widget suggests running another.
 */
const LCP_YEAST_SEO_WIDGET_STALE_DAYS = 7;

/**
 * Splits pages into indexable and noindex from their saved setting.
 *
 * @param int[]    $ids      Post IDs.
 * @param string[] $settings Saved robots setting per post ID (missing means index).
 * @return array{indexable:int,noindex:int}
 */
function lcp_yeast_seo_widget_index_counts( array $ids, array $settings ) {
	$noindex = 0;

	foreach ( $ids as $id ) {
		if ( 'noindex' === ( $settings[ $id ] ?? 'index' ) ) {
			++$noindex;
		}
	}

	return array(
		'indexable' => count( $ids ) - $noindex,
		'noindex'   => $noindex,
	);
}

/**
 * Whether the last scan is missing or old enough to run again.
 *
 * @param int $last Time of the last scan, 0 for none.
 * @param int $now  Current time.
 * @return bool
 */
function lcp_yeast_seo_widget_scan_is_stale( $last, $now ) {
	return ! $last || ( $now - $last ) > LCP_YEAST_SEO_WIDGET_STALE_DAYS * DAY_IN_SECONDS;
}

/**
 * The top problems, as short lines for the widget.
 *
 * @param array[] $problems From lcp_yeast_seo_health_common_problems().
 * @param int     $limit    How many to keep.
 * @return array[] Each with level, message and pages.
 */
function lcp_yeast_seo_widget_top_problems( array $problems, $limit = 4 ) {
	return array_slice( $problems, 0, max( 1, (int) $limit ) );
}

/**
 * The IndexNow line for the widget, or '' when it is switched off.
 *
 * @param array $facts enabled, available, paused_until (formatted time or ''), queued, last (ago, count, code) or null.
 * @return string
 */
function lcp_yeast_seo_widget_indexnow_line( array $facts ) {
	if ( empty( $facts['enabled'] ) ) {
		return '';
	}

	if ( empty( $facts['available'] ) ) {
		return 'On, but nothing is sent from this site (a local address, or hidden from search engines).';
	}

	$parts = array();
	$last  = $facts['last'] ?? null;

	if ( $last ) {
		$parts[] = in_array( (int) $last['code'], array( 200, 202 ), true )
			? sprintf( 'Last ping %s ago: %s accepted.', $last['ago'], lcp_yeast_seo_count_noun( (int) $last['count'], 'page' ) )
			: sprintf( 'Last ping %s ago: %s not accepted (%s).', $last['ago'], lcp_yeast_seo_count_noun( (int) $last['count'], 'page' ), (int) $last['code'] ? 'response ' . (int) $last['code'] : 'no answer' );
	}

	if ( ! empty( $facts['paused_until'] ) ) {
		$parts[] = 'Paused until ' . $facts['paused_until'] . '.';
	}

	if ( ! empty( $facts['queued'] ) ) {
		$parts[] = lcp_yeast_seo_count_noun( (int) $facts['queued'], 'page' ) . ' waiting to be sent.';
	}

	return $parts ? implode( ' ', $parts ) : 'On. Nothing has been sent yet.';
}

/**
 * A tab of the settings screen.
 *
 * @param string $tab Tab slug.
 * @return string
 */
function lcp_yeast_seo_widget_tab_url( $tab ) {
	return add_query_arg( array( 'page' => 'lcp-yeast-seo', 'tab' => $tab ), admin_url( 'admin.php' ) );
}

/**
 * Registers the widget for people who can manage the plugin.
 *
 * @return void
 */
function lcp_yeast_seo_register_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget( 'lcp_yeast_seo_dashboard', 'Yeast SEO', 'lcp_yeast_seo_render_dashboard_widget' );
}
add_action( 'wp_dashboard_setup', 'lcp_yeast_seo_register_dashboard_widget' );

/**
 * Loads the styles on the dashboard.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function lcp_yeast_seo_dashboard_widget_assets( $hook ) {
	if ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$css = LCP_YEAST_SEO_PATH . 'assets/admin.css';

	wp_enqueue_style( 'lcp-yeast-seo-admin', LCP_YEAST_SEO_URL . 'assets/admin.css', array(), file_exists( $css ) ? filemtime( $css ) : LCP_YEAST_SEO_VERSION );
}
add_action( 'admin_enqueue_scripts', 'lcp_yeast_seo_dashboard_widget_assets' );

/**
 * Draws the widget.
 *
 * @return void
 */
function lcp_yeast_seo_render_dashboard_widget() {
	$ids      = lcp_yeast_seo_health_page_ids();
	$results  = lcp_yeast_seo_health_effective( $ids, lcp_yeast_seo_health_results() );
	$totals   = lcp_yeast_seo_health_totals( $ids, $results );
	$problems = lcp_yeast_seo_widget_top_problems( lcp_yeast_seo_health_common_problems( $ids, $results ), 1 );
	$last     = $totals['checked'] ? (int) max( array_column( array_intersect_key( $results, array_flip( $ids ) ), 'time' ) ) : 0;
	$stale    = lcp_yeast_seo_widget_scan_is_stale( $last, time() );
	$score    = lcp_yeast_seo_org_score_for_site();
	$tip      = 'ok' === $score['state'] && $score['result']['score'] < 100 ? ( $score['result']['tips'][0] ?? null ) : null;
	$score_total  = 'ok' === $score['state'] ? count( $score['result']['checks'] ) : 0;
	$score_filled = 'ok' === $score['state'] ? count( wp_list_filter( $score['result']['checks'], array( 'status' => 'pass' ) ) ) : 0;
	$pending  = get_transient( lcp_yeast_seo_ie_pending_key() );
	$hidden   = ! get_option( 'blog_public' );

	if ( $ids ) {
		update_meta_cache( 'post', $ids );
	}

	$settings = array();

	foreach ( $ids as $id ) {
		$settings[ $id ] = (string) get_post_meta( $id, LCP_YEAST_SEO_ROBOTS_META_KEY, true );
	}

	$counts = lcp_yeast_seo_widget_index_counts( $ids, $settings );

	$ping_log   = (array) get_option( LCP_YEAST_SEO_INDEXNOW_LOG_OPTION, array() );
	$ping_state = (array) get_option( LCP_YEAST_SEO_INDEXNOW_STATE_OPTION, array() );
	$ping_until = lcp_yeast_seo_indexnow_paused_until();
	$ping_line  = lcp_yeast_seo_widget_indexnow_line(
		array(
			'enabled'      => lcp_yeast_seo_indexnow_enabled(),
			'available'    => lcp_yeast_seo_indexnow_available(),
			'paused_until' => $ping_until ? wp_date( 'H:i', $ping_until ) : '',
			'queued'       => count( (array) ( $ping_state['pending'] ?? array() ) ),
			'last'         => $ping_log ? array(
				'ago'   => human_time_diff( (int) $ping_log[0]['time'] ),
				'count' => (int) $ping_log[0]['count'],
				'code'  => (int) $ping_log[0]['code'],
			) : null,
		)
	);
	?>
	<div class="lcp-ys lcp-ys-dw">
		<?php if ( $hidden ) : ?>
			<p class="lcp-ys-dw__alert">This site is set to discourage search engines, so every page carries noindex. <a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>">Change it under Settings, Reading</a> when you are ready to launch.</p>
		<?php endif; ?>

			<section class="lcp-ys-dw__block">
				<h3>Site health</h3>
				<?php if ( ! $totals['checked'] ) : ?>
					<p>The site has not been scanned yet. <a href="<?php echo esc_url( lcp_yeast_seo_widget_tab_url( 'health' ) ); ?>">Scan it now</a>.</p>
				<?php else : ?>
					<div class="lcp-ys-dw__lead">
						<?php echo lcp_yeast_seo_health_jar( $totals['total'] ? (int) round( 100 * $totals['ok'] / $totals['total'] ) : 0, false, $totals['ok'] . ' of ' . $totals['total'] . ' pages are healthy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped values. ?>
						<p><strong><?php echo esc_html( $totals['ok'] . ' of ' . $totals['total'] . ' pages are healthy' ); ?></strong></p>
					</div>
					<ul class="lcp-ys-tally">
						<li class="lcp-ys-chip lcp-ys-chip--<?php echo $totals['ok'] ? 'ok' : 'neutral'; ?>"><?php echo esc_html( $totals['ok'] . ' healthy' ); ?></li>
						<li class="lcp-ys-chip lcp-ys-chip--<?php echo $totals['warning'] ? 'warn' : 'neutral'; ?>"><?php echo esc_html( $totals['warning'] . ' with warnings' ); ?></li>
						<li class="lcp-ys-chip lcp-ys-chip--<?php echo $totals['error'] ? 'error' : 'neutral'; ?>"><?php echo esc_html( $totals['error'] . ' with errors' ); ?></li>
					</ul>
					<?php if ( ! $problems ) : ?>
						<p>No problems found.</p>
					<?php else : ?>
						<ul class="lcp-ys-dw__list">
							<?php foreach ( $problems as $problem ) : ?>
								<li class="lcp-ys-dw__item lcp-ys-dw__item--<?php echo esc_attr( $problem['level'] ); ?>">
									<strong>Fix first:</strong>
									<a href="<?php echo esc_url( add_query_arg( 'problem', $problem['code'], lcp_yeast_seo_widget_tab_url( 'health' ) ) ); ?>"><?php echo esc_html( mb_strimwidth( $problem['message'], 0, 90, '...' ) ); ?></a>
									<span><?php echo esc_html( lcp_yeast_seo_count_noun( $problem['pages'], 'page' ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p class="lcp-ys-dw__meta">
						Last scanned <?php echo esc_html( human_time_diff( $last ) ); ?> ago.
						<?php if ( $stale ) : ?>
							<a href="<?php echo esc_url( lcp_yeast_seo_widget_tab_url( 'health' ) ); ?>">Scan again</a>
						<?php else : ?>
							<a href="<?php echo esc_url( lcp_yeast_seo_widget_tab_url( 'health' ) ); ?>">Open Health</a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</section>

		<?php if ( $tip ) : ?>
			<section class="lcp-ys-dw__block">
				<h3>Organization details: <?php echo (int) $score_filled; ?> of <?php echo (int) $score_total; ?> filled in</h3>
				<p><strong><?php echo esc_html( $tip['label'] ); ?>.</strong> <?php echo esc_html( wp_trim_words( $tip['tip'], 24 ) ); ?> <a href="<?php echo esc_url( lcp_yeast_seo_widget_tab_url( 'schema' ) ); ?>">Open the wizard</a></p>
			</section>
		<?php elseif ( 'ok' === $score['state'] ) : ?>
			<section class="lcp-ys-dw__block">
				<h3>Organization details: <?php echo (int) $score_filled; ?> of <?php echo (int) $score_total; ?> filled in</h3>
				<p>Nothing to add.</p>
			</section>
		<?php else : ?>
			<section class="lcp-ys-dw__block">
				<h3>Organization details</h3>
				<p><?php echo esc_html( $score['message'] ); ?> <a href="<?php echo esc_url( lcp_yeast_seo_widget_tab_url( 'schema' ) ); ?>">Open Schema</a></p>
			</section>
		<?php endif; ?>

		<?php if ( $ids ) : ?>
			<section class="lcp-ys-dw__block">
				<h3>Indexing</h3>
				<p>
					<?php
					echo esc_html(
						sprintf(
							'%s can be indexed, %s set to noindex. %s',
							lcp_yeast_seo_count_noun( $counts['indexable'], 'page' ),
							$counts['noindex'],
							lcp_yeast_seo_sitemaps_enabled() ? 'The XML sitemap is on.' : 'The XML sitemap is off.'
						)
					);
					?>
				</p>
			</section>
		<?php endif; ?>

		<?php if ( '' !== $ping_line ) : ?>
			<section class="lcp-ys-dw__block">
				<h3>Search engine pings</h3>
				<p><?php echo esc_html( $ping_line ); ?> <a href="<?php echo esc_url( lcp_yeast_seo_widget_tab_url( 'general' ) ); ?>">Settings</a></p>
			</section>
		<?php endif; ?>

		<?php if ( is_array( $pending ) && ! empty( $pending['parsed'] ) ) : ?>
			<section class="lcp-ys-dw__block">
				<h3>Import waiting</h3>
				<p><?php echo esc_html( $pending['name'] . ' was checked ' . human_time_diff( (int) $pending['time'] ) . ' ago and has not been applied.' ); ?></p>
				<div class="lcp-ys-pending__actions">
					<?php
					lcp_yeast_seo_ie_render_pending_button( 'lcp_yeast_seo_import_apply', 'Apply these changes', 'primary small' );
					lcp_yeast_seo_ie_render_pending_button( 'lcp_yeast_seo_import_cancel', 'Cancel', 'secondary small' );
					?>
				</div>
			</section>
		<?php endif; ?>
	</div>
	<?php
}
