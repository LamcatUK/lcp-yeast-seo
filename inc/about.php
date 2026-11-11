<?php
/**
 * The About tab: the story behind the name, and what the plugin does.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The origin story, one paragraph per entry.
 *
 * @return string[]
 */
function lcp_yeast_seo_about_story() {
	return array(
		'We build websites for people who fix boilers, clear garages and bake bread. For years we looked after their search settings by hand, then wrote a plugin to do it. It needed a name. Someone was feeding a sourdough starter on the windowsill, and that settled it. We considered other names. None of them rose.',
		'Yeast is small, cheap and easy to ignore, and it is the reason the loaf rises. A website works the same way: a few right things in the right places, left alone to work. So the plugin sets your titles, writes your sitemap, checks your structured data, then comes back to see what has risen.',
		'The starter is still on the windowsill. It\'s doing fine.',
	);
}

/**
 * What the plugin looks after, as short plain lines.
 *
 * @return string[][] Each with a title and a sentence.
 */
function lcp_yeast_seo_about_features() {
	return array(
		array( 'Titles and descriptions', 'Templates, per-page overrides and automatic descriptions, with a preview of how a page reads in search.' ),
		array( 'XML sitemaps', 'Pages, posts, other content types and the archives your theme uses, each switched on only if you want it.' ),
		array( 'Structured data', 'Organization, WebSite, WebPage, Article and breadcrumbs, a validator for anything you write by hand, and a wizard for your business details.' ),
		array( 'Site health', 'Checks every published page for its title, description, schema, image text and links, and finds pages nothing links to.' ),
		array( 'Indexing', 'Noindex and canonical addresses per page, in bulk, and for archives. Your robots.txt, edited safely.' ),
		array( 'Import and export', 'CSV or spreadsheet in and out, with a dry run first, and a way to move over from another SEO plugin and tidy up after.' ),
	);
}

/**
 * Prints the About tab.
 *
 * @return void
 */
function lcp_yeast_seo_render_about_tab() {
	?>
	<section class="lcp-ys-card lcp-ys-about">
		<div class="lcp-ys-about__body">
			<?php echo lcp_yeast_seo_brand_mark( '#d9a21b', '#0f2d2a', 'lcp-ys-about__mark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from fixed shapes and escaped attributes. ?>
			<h2>Leave it to prove.</h2>
			<?php foreach ( lcp_yeast_seo_about_story() as $paragraph ) : ?>
				<p><?php echo esc_html( $paragraph ); ?></p>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="lcp-ys-card">
		<div class="lcp-ys-card__head"><h2>What it looks after</h2></div>
		<ul class="lcp-ys-about__features">
			<?php foreach ( lcp_yeast_seo_about_features() as $feature ) : ?>
				<li><strong><?php echo esc_html( $feature[0] ); ?></strong><span><?php echo esc_html( $feature[1] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<p class="lcp-ys-about__foot">
			<?php echo esc_html( 'Yeast SEO ' . LCP_YEAST_SEO_VERSION . ' by Lamcat. No upsells.' ); ?>
		</p>
	</section>
	<?php
}
