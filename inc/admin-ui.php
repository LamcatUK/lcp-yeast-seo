<?php
/**
 * Shared admin chrome: the header band with status chips, settings sections
 * as cards, and the health dashboard's markup.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the admin styles (and the dashboard script on its tab).
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function lcp_yeast_seo_admin_ui_assets( $hook ) {
	if ( 'toplevel_page_lcp-yeast-seo' !== $hook ) {
		return;
	}

	$css = LCP_YEAST_SEO_PATH . 'assets/admin.css';

	wp_enqueue_style( 'lcp-yeast-seo-admin', LCP_YEAST_SEO_URL . 'assets/admin.css', array(), file_exists( $css ) ? filemtime( $css ) : LCP_YEAST_SEO_VERSION );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['tab'] ) && 'health' === $_GET['tab'] ) {
		$js = LCP_YEAST_SEO_PATH . 'assets/health.js';

		wp_enqueue_script( 'lcp-yeast-seo-health', LCP_YEAST_SEO_URL . 'assets/health.js', array(), file_exists( $js ) ? filemtime( $js ) : LCP_YEAST_SEO_VERSION, true );
		wp_localize_script(
			'lcp-yeast-seo-health',
			'lcpYeastSeoHealth',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'lcp_yeast_seo_health' ),
				'ids'   => lcp_yeast_seo_health_page_ids(),
				'batch' => LCP_YEAST_SEO_HEALTH_BATCH,
				'requestTimeout' => LCP_YEAST_SEO_HEALTH_BUDGET + LCP_YEAST_SEO_HEALTH_PAGE_TIMEOUT + 15,
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'lcp_yeast_seo_admin_ui_assets' );

/**
 * The status chips shown in the header: label and state (ok, warn or neutral).
 *
 * @return array[]
 */
function lcp_yeast_seo_header_chips() {
	$chips = array();

	$chips[] = lcp_yeast_seo_schema_enabled() ? array( 'Schema on', 'ok' ) : array( 'Schema off', 'warn' );
	$chips[] = lcp_yeast_seo_sitemaps_enabled() ? array( 'Sitemaps on', 'ok' ) : array( 'Sitemaps off', 'warn' );
	$chips[] = get_option( 'blog_public' ) ? array( 'Open to search engines', 'ok' ) : array( 'Hidden from search engines', 'warn' );

	if ( is_file( lcp_yeast_seo_robots_file_path() ) ) {
		$chips[] = array( 'Physical robots.txt in use', 'neutral' );
	} elseif ( '' !== trim( (string) lcp_yeast_seo_get_setting( 'robots_txt', '' ) ) ) {
		$chips[] = array( 'Custom robots.txt', 'neutral' );
	}

	return $chips;
}

/**
 * Prints the header band: wordmark, status chips and the tabs.
 *
 * @param array[] $tabs      Settings tabs: slug => [label, settings page].
 * @param string  $active    Active tab slug.
 * @param bool    $js_tabs   Whether the settings tabs switch client-side.
 * @param callable $tab_url  Builds a tab's URL from its slug.
 * @return void
 */
function lcp_yeast_seo_render_admin_header( array $tabs, $active, $js_tabs, $tab_url ) {
	$bubbles = array(
		array( 6, 22, 0.00 ),
		array( 13, 12, 0.18 ),
		array( 21, 30, 0.08 ),
		array( 33, 16, 0.30 ),
		array( 47, 26, 0.12 ),
		array( 58, 10, 0.36 ),
		array( 69, 34, 0.04 ),
		array( 78, 14, 0.24 ),
		array( 88, 24, 0.16 ),
		array( 95, 12, 0.40 ),
	);
	?>
	<header class="lcp-ys-header">
		<div class="lcp-ys-bubbles" aria-hidden="true">
			<?php foreach ( $bubbles as $bubble ) : ?>
				<span style="--x:<?php echo (int) $bubble[0]; ?>%;--s:<?php echo (int) $bubble[1]; ?>px;--d:<?php echo esc_attr( $bubble[2] ); ?>s"></span>
			<?php endforeach; ?>
		</div>
		<div class="lcp-ys-header__main">
			<div class="lcp-ys-brand">
				<h1>Yeast SEO</h1>
				<span class="lcp-ys-version">v<?php echo esc_html( LCP_YEAST_SEO_VERSION ); ?></span>
			</div>
			<ul class="lcp-ys-chips" aria-label="Site status">
				<?php foreach ( lcp_yeast_seo_header_chips() as $chip ) : ?>
					<li class="lcp-ys-chip lcp-ys-chip--<?php echo esc_attr( $chip[1] ); ?>"><?php echo esc_html( $chip[0] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<nav class="nav-tab-wrapper" <?php echo $js_tabs ? 'data-lcp-tabs' : ''; ?> aria-label="Yeast SEO sections">
			<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a href="<?php echo esc_url( $tab_url( $slug ) ); ?>" class="nav-tab<?php echo $js_tabs && $slug === $active ? ' nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tab[0] ); ?></a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $tab_url( 'health' ) ); ?>" class="nav-tab<?php echo 'health' === $active ? ' nav-tab-active' : ''; ?>">Health</a>
			<a href="<?php echo esc_url( $tab_url( 'import-export' ) ); ?>" class="nav-tab<?php echo 'import-export' === $active ? ' nav-tab-active' : ''; ?>">Import / Export</a>
		</nav>
	</header>
	<hr class="wp-header-end">
	<?php
}

/**
 * Prints a settings page's sections as cards, like do_settings_sections().
 *
 * @param string $page Settings page slug.
 * @return void
 */
function lcp_yeast_seo_render_sections( $page ) {
	global $wp_settings_sections;

	foreach ( (array) ( $wp_settings_sections[ $page ] ?? array() ) as $section ) {
		ob_start();

		if ( $section['callback'] ) {
			call_user_func( $section['callback'], $section );
		}

		$intro = trim( (string) ob_get_clean() );
		?>
		<section class="lcp-ys-card" id="lcp-ys-<?php echo esc_attr( $section['id'] ); ?>">
			<div class="lcp-ys-card__head">
				<h2><?php echo esc_html( $section['title'] ); ?></h2>
				<?php if ( '' !== $intro ) : ?>
					<div class="lcp-ys-card__intro"><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- section callbacks print their own escaped markup. ?></div>
				<?php endif; ?>
			</div>
			<table class="form-table" role="presentation">
				<?php do_settings_fields( $page, $section['id'] ); ?>
			</table>
		</section>
		<?php
	}
}

/**
 * The starter jar: its fill level is the share of healthy pages.
 *
 * @param int  $percent Healthy share, 0 to 100.
 * @param bool $animate Whether the level rises into place on load.
 * @param string $label Accessible description.
 * @return string SVG markup.
 */
function lcp_yeast_seo_health_jar( $percent, $animate, $label ) {
	$percent = max( 0, min( 100, (int) $percent ) );
	$bubbles = $percent > 8 ? array( array( 44, 118, 3.2, 0 ), array( 62, 104, 2.4, 1 ), array( 76, 124, 3.6, 2 ), array( 54, 92, 2, 3 ) ) : array();

	ob_start();
	?>
	<svg class="lcp-ys-jar<?php echo $animate ? ' is-animated' : ''; ?>" viewBox="0 0 120 150" role="img" aria-label="<?php echo esc_attr( $label ); ?>" style="--fill:<?php echo (int) $percent; ?>">
		<defs>
			<clipPath id="lcp-ys-jar-clip"><path d="M32 30H88V38C98 46 100 54 100 66V128C100 138 94 144 84 144H36C26 144 20 138 20 128V66C20 54 22 46 32 38Z"/></clipPath>
		</defs>
		<g clip-path="url(#lcp-ys-jar-clip)">
			<rect class="lcp-ys-jar__glass" x="0" y="0" width="120" height="150"/>
			<g class="lcp-ys-jar__fill">
				<rect x="0" y="40" width="120" height="110"/>
				<path class="lcp-ys-jar__surface" d="M0 40 Q15 35 30 40 T60 40 T90 40 T120 40 V46 H0Z"/>
				<?php foreach ( $bubbles as $bubble ) : ?>
					<circle class="lcp-ys-jar__bubble" cx="<?php echo (int) $bubble[0]; ?>" cy="<?php echo (int) $bubble[1]; ?>" r="<?php echo esc_attr( $bubble[2] ); ?>" style="--i:<?php echo (int) $bubble[3]; ?>"/>
				<?php endforeach; ?>
			</g>
		</g>
		<path class="lcp-ys-jar__outline" d="M32 30H88V38C98 46 100 54 100 66V128C100 138 94 144 84 144H36C26 144 20 138 20 128V66C20 54 22 46 32 38Z"/>
		<rect class="lcp-ys-jar__lid" x="28" y="18" width="64" height="12" rx="3"/>
		<path class="lcp-ys-jar__ticks" d="M20 70H28M20 90H26M20 110H28M20 130H26"/>
	</svg>
	<?php
	return (string) ob_get_clean();
}

/**
 * The status label and CSS state for a result status.
 *
 * @param string $status ok, warning, error or none.
 * @return array{0:string,1:string} Label and modifier.
 */
function lcp_yeast_seo_health_status_chip( $status ) {
	$map = array(
		'ok'      => array( 'Healthy', 'ok' ),
		'warning' => array( 'Warnings', 'warn' ),
		'error'   => array( 'Errors', 'error' ),
	);

	return $map[ $status ] ?? array( 'Not checked', 'neutral' );
}

/**
 * Pluralises a count with a noun.
 *
 * @param int    $count    Count.
 * @param string $singular Singular noun.
 * @param string $plural   Plural noun.
 * @return string
 */
function lcp_yeast_seo_count_noun( $count, $singular, $plural = '' ) {
	return $count . ' ' . ( 1 === (int) $count ? $singular : ( '' !== $plural ? $plural : $singular . 's' ) );
}

/**
 * The dashboard summary: the jar and what it means.
 *
 * @param bool $animate Whether the jar rises into place.
 * @return void
 */
function lcp_yeast_seo_health_render_summary( $animate = false ) {
	$ids     = lcp_yeast_seo_health_page_ids();
	$results = lcp_yeast_seo_health_effective( $ids, lcp_yeast_seo_health_results() );
	$totals  = lcp_yeast_seo_health_totals( $ids, $results );
	$percent = $totals['checked'] ? (int) round( 100 * $totals['ok'] / max( 1, $totals['total'] ) ) : 0;
	$last    = $totals['checked'] ? max( array_column( array_intersect_key( $results, array_flip( $ids ) ), 'time' ) ) : 0;

	if ( ! $totals['checked'] ) {
		$headline = 'No scan yet';
		$detail   = 'Scan the site to check every page in your sitemaps for its title, description, canonical and structured data.';
		$label    = 'The jar is empty: no pages have been checked yet';
	} else {
		$headline = $totals['ok'] === $totals['total'] ? 'Every page is healthy' : $totals['ok'] . ' of ' . $totals['total'] . ' pages are healthy';
		$parts    = array();

		if ( $totals['error'] ) {
			$parts[] = lcp_yeast_seo_count_noun( $totals['error'], 'page has', 'pages have' ) . ' errors';
		}

		if ( $totals['warning'] ) {
			$parts[] = lcp_yeast_seo_count_noun( $totals['warning'], 'page has', 'pages have' ) . ' warnings';
		}

		$detail = ( $parts ? ucfirst( implode( ' and ', $parts ) ) . '. ' : 'Nothing needs fixing. ' )
			. ( $totals['checked'] < $totals['total'] ? ( $totals['total'] - $totals['checked'] ) . ' not checked yet. ' : '' )
			. 'Last checked ' . human_time_diff( $last ) . ' ago.';
		$label    = $totals['ok'] . ' of ' . $totals['total'] . ' pages are healthy';
	}
	?>
	<div class="lcp-ys-summary">
		<?php echo lcp_yeast_seo_health_jar( $percent, $animate, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with escaped values. ?>
		<div class="lcp-ys-summary__text">
			<p class="lcp-ys-summary__headline"><?php echo esc_html( $headline ); ?></p>
			<p class="lcp-ys-summary__detail"><?php echo esc_html( $detail ); ?></p>
			<?php if ( $totals['checked'] ) : ?>
				<ul class="lcp-ys-tally">
					<li class="lcp-ys-chip lcp-ys-chip--<?php echo $totals['ok'] ? 'ok' : 'neutral'; ?>"><?php echo esc_html( $totals['ok'] . ' healthy' ); ?></li>
					<li class="lcp-ys-chip lcp-ys-chip--<?php echo $totals['warning'] ? 'warn' : 'neutral'; ?>"><?php echo esc_html( $totals['warning'] . ' with warnings' ); ?></li>
					<li class="lcp-ys-chip lcp-ys-chip--<?php echo $totals['error'] ? 'error' : 'neutral'; ?>"><?php echo esc_html( $totals['error'] . ' with errors' ); ?></li>
				</ul>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * The dashboard lists: what to fix first, and every page.
 *
 * @return void
 */
function lcp_yeast_seo_health_render_list() {
	$ids      = lcp_yeast_seo_health_page_ids();
	$results  = lcp_yeast_seo_health_effective( $ids, lcp_yeast_seo_health_results() );
	$problems = lcp_yeast_seo_health_common_problems( $ids, $results );

	if ( $problems ) :
		?>
		<section class="lcp-ys-card">
			<div class="lcp-ys-card__head"><h2>Fix these first</h2><div class="lcp-ys-card__intro"><p>The problems that affect the most pages, errors before warnings. Titles, descriptions and structured data are all checked.</p></div></div>
			<ul class="lcp-ys-problems">
				<?php foreach ( array_slice( $problems, 0, 8 ) as $problem ) : ?>
					<li class="lcp-ys-problem lcp-ys-problem--<?php echo esc_attr( $problem['level'] ); ?>">
						<button type="button" class="lcp-ys-problem__btn" data-problem="<?php echo esc_attr( $problem['code'] ); ?>" data-label="<?php echo esc_attr( $problem['message'] ); ?>" aria-label="<?php echo esc_attr( 'Show the ' . lcp_yeast_seo_count_noun( $problem['pages'], 'page' ) . ' with this problem: ' . $problem['message'] ); ?>">
							<span class="lcp-ys-problem__text">
								<?php echo esc_html( $problem['message'] ); ?>
								<?php if ( '' !== lcp_yeast_seo_health_hint( $problem['code'] ) ) : ?>
									<span class="lcp-ys-hint"><?php echo esc_html( lcp_yeast_seo_health_hint( $problem['code'] ) ); ?></span>
								<?php endif; ?>
							</span>
							<span class="lcp-ys-problem__count"><?php echo esc_html( lcp_yeast_seo_count_noun( $problem['pages'], 'page' ) ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<section class="lcp-ys-card lcp-ys-pages">
		<div class="lcp-ys-card__head lcp-ys-card__head--row">
			<h2>Pages</h2>
			<div class="lcp-ys-filter" role="group" aria-label="Filter pages">
				<button type="button" class="lcp-ys-filter__btn is-active" data-filter="all">All</button>
				<button type="button" class="lcp-ys-filter__btn" data-filter="attention">Needs attention</button>
				<button type="button" class="lcp-ys-filter__btn" data-filter="error">Errors</button>
				<button type="button" class="lcp-ys-filter__btn" data-filter="ok">Healthy</button>
			</div>
		</div>
		<p class="lcp-ys-filterbar" data-filterbar hidden>
			<span data-filterbar-text></span>
			<button type="button" class="button button-small" data-filter="all">Show all pages</button>
		</p>
		<div class="lcp-ys-rows">
			<p class="lcp-ys-empty" data-filter-empty hidden>No pages match this filter.</p>
			<?php
			foreach ( $ids as $id ) {
				lcp_yeast_seo_health_render_row( $id, $results[ $id ] ?? null );
			}
			?>
			<?php if ( ! $ids ) : ?>
				<p class="lcp-ys-empty">No pages are included in your sitemaps yet. Check the post types selected under General, XML Sitemaps.</p>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/**
 * One page's row: status, title, the types it carries, and its problems.
 *
 * @param int        $post_id Post ID.
 * @param array|null $result  Stored result, or null when not checked yet.
 * @return void
 */
function lcp_yeast_seo_health_render_row( $post_id, $result ) {
	$status = $result['status'] ?? 'none';
	$chip   = lcp_yeast_seo_health_status_chip( $status );
	$issues = $result['issues'] ?? array();
	$types  = $result['types'] ?? array();
	$errors = count( wp_list_filter( $issues, array( 'level' => 'error' ) ) );
	$warns  = count( $issues ) - $errors;
	$stale  = $result && (string) get_post_field( 'post_modified_gmt', $post_id ) > (string) ( $result['modified'] ?? '' );
	$object = get_post_type_object( get_post_type( $post_id ) );
	$edit   = get_edit_post_link( $post_id, 'raw' );
	?>
	<details class="lcp-ys-row" data-status="<?php echo esc_attr( $status ); ?>" data-id="<?php echo (int) $post_id; ?>" data-codes="<?php echo esc_attr( implode( ' ', array_unique( array_column( $issues, 'code' ) ) ) ); ?>">
		<summary>
			<span class="lcp-ys-chip lcp-ys-chip--<?php echo esc_attr( $chip[1] ); ?>"><?php echo esc_html( $chip[0] ); ?></span>
			<span class="lcp-ys-row__title"><?php echo esc_html( wp_strip_all_tags( get_the_title( $post_id ) ) ); ?><span class="lcp-ys-row__type"><?php echo esc_html( $object ? $object->labels->singular_name : '' ); ?></span></span>
			<span class="lcp-ys-areas">
				<?php foreach ( array( 'schema' => 'Schema', 'meta' => 'Meta' ) as $area => $label ) : ?>
					<?php $area_chip = lcp_yeast_seo_health_status_chip( lcp_yeast_seo_health_area_status( $result, $area ) ); ?>
					<span class="lcp-ys-area lcp-ys-area--<?php echo esc_attr( $area_chip[1] ); ?>" title="<?php echo esc_attr( $label . ': ' . strtolower( $area_chip[0] ) ); ?>"><?php echo esc_html( $label ); ?></span>
				<?php endforeach; ?>
			</span>
			<span class="lcp-ys-row__types">
				<?php foreach ( array_slice( $types, 0, 4 ) as $type ) : ?>
					<code><?php echo esc_html( $type ); ?></code>
				<?php endforeach; ?>
				<?php if ( count( $types ) > 4 ) : ?>
					<em>+<?php echo (int) ( count( $types ) - 4 ); ?></em>
				<?php endif; ?>
			</span>
			<span class="lcp-ys-row__count">
				<?php
				if ( $issues ) {
					echo esc_html( trim( ( $errors ? lcp_yeast_seo_count_noun( $errors, 'error' ) : '' ) . ( $errors && $warns ? ', ' : '' ) . ( $warns ? lcp_yeast_seo_count_noun( $warns, 'warning' ) : '' ) ) );
				}
				?>
			</span>
		</summary>
		<div class="lcp-ys-row__body">
			<?php if ( $stale ) : ?>
				<p class="lcp-ys-row__note">This page was edited after it was last checked.</p>
			<?php endif; ?>
			<?php if ( $issues ) : ?>
				<ul class="lcp-ys-issues">
					<?php foreach ( $issues as $issue ) : ?>
						<li class="lcp-ys-issue lcp-ys-issue--<?php echo esc_attr( $issue['level'] ); ?>">
							<span class="lcp-ys-issue__area"><?php echo esc_html( 'meta' === lcp_yeast_seo_health_area_of( $issue['code'] ) ? 'Meta' : 'Schema' ); ?></span>
							<?php echo esc_html( $issue['message'] ); ?>
							<?php if ( '' !== lcp_yeast_seo_health_hint( $issue['code'] ) ) : ?>
								<span class="lcp-ys-hint"><?php echo esc_html( lcp_yeast_seo_health_hint( $issue['code'] ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $result ) : ?>
				<p class="lcp-ys-row__note">No problems found. <?php echo esc_html( lcp_yeast_seo_count_noun( (int) $result['blocks'], 'JSON-LD block' ) ); ?> on the page.</p>
			<?php else : ?>
				<p class="lcp-ys-row__note">This page has not been checked yet.</p>
			<?php endif; ?>
			<?php if ( ! empty( $result['meta']['indexable'] ) ) : ?>
				<dl class="lcp-ys-facts">
					<dt>Title</dt>
					<dd><?php echo '' !== $result['meta']['title'] ? esc_html( $result['meta']['title'] ) . ' <span>(' . (int) mb_strlen( $result['meta']['title'] ) . ')</span>' : '<em>none</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></dd>
					<dt>Description</dt>
					<dd><?php echo '' !== $result['meta']['description'] ? esc_html( $result['meta']['description'] ) . ' <span>(' . (int) mb_strlen( $result['meta']['description'] ) . ')</span>' : '<em>none</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></dd>
				</dl>
			<?php endif; ?>
			<p class="lcp-ys-row__actions">
				<button type="button" class="button button-small" data-recheck="<?php echo (int) $post_id; ?>">Check again</button>
				<a class="button button-small" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener">View page</a>
				<?php if ( $edit ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $edit ); ?>">Edit page</a>
				<?php endif; ?>
			</p>
		</div>
	</details>
	<?php
}

/**
 * Prints the Health tab.
 *
 * @return void
 */
function lcp_yeast_seo_health_render_tab() {
	?>
	<div data-health data-filter="all">
		<?php if ( ! get_option( 'blog_public' ) ) : ?>
			<p class="lcp-ys-notice">This site is set to discourage search engines (Settings, Reading), so every page carries a noindex tag. That is not counted as a problem here, and the pages are checked as if the site were public.</p>
		<?php endif; ?>
		<section class="lcp-ys-card lcp-ys-overview">
			<div data-health-summary><?php lcp_yeast_seo_health_render_summary( true ); ?></div>
			<div class="lcp-ys-scan">
				<button type="button" class="button button-primary button-hero" data-health-scan>Scan site</button>
				<button type="button" class="button" data-health-recheck-errors hidden>Re-check pages with errors</button>
				<div class="lcp-ys-progress" data-health-progress hidden>
					<div class="lcp-ys-progress__track"><div class="lcp-ys-progress__bar" data-health-bar></div></div>
					<p class="lcp-ys-progress__text" data-health-status role="status" aria-live="polite"></p>
				</div>
			</div>
		</section>
		<div data-health-list><?php lcp_yeast_seo_health_render_list(); ?></div>
	</div>
	<?php
}
