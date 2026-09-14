<?php
/**
 * Admin settings page — currently just an enable/disable toggle for the
 * schema module, growing to cover more as this plugin absorbs more of
 * Yoast's job over time.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option name for the single serialized settings array.
 *
 * @var string
 */
define( 'LCP_YEAST_SEO_SETTINGS_OPTION', 'lcp_yeast_seo_settings' );

/**
 * Read one LCP Yeast SEO setting.
 *
 * @param string $key     Setting key.
 * @param string $default Fallback if the key isn't set.
 * @return mixed
 */
function lcp_yeast_seo_get_setting( $key, $default = '' ) {
	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $default;
}

/**
 * Sanitizes the plugin settings array.
 *
 * @param mixed $input Raw submitted settings.
 * @return array
 */
function lcp_yeast_seo_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();

	return array(
		'enable_schema'             => empty( $input['enable_schema'] ) ? '0' : '1',
		'default_social_image'      => isset( $input['default_social_image'] ) ? esc_url_raw( wp_unslash( $input['default_social_image'] ) ) : '',
		'twitter_site'              => isset( $input['twitter_site'] ) ? sanitize_text_field( wp_unslash( $input['twitter_site'] ) ) : '',
		'enable_sitemaps'           => empty( $input['enable_sitemaps'] ) ? '0' : '1',
		'sitemap_post_types'        => lcp_yeast_seo_sanitize_sitemap_post_types( $input['sitemap_post_types'] ?? array() ),
	);
}

/**
 * Sanitizes the per-post-type sitemap settings.
 *
 * @param mixed $input Submitted values.
 * @return array
 */
function lcp_yeast_seo_sanitize_sitemap_post_types( $input ) {
	$input     = is_array( $input ) ? $input : array();
	$sanitized = array();

	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		$sanitized[ $post_type ] = empty( $input[ $post_type ] ) ? '0' : '1';
	}

	return $sanitized;
}

/**
 * Whether the schema module is enabled. Defaults to enabled on a fresh
 * install (no option saved yet) so behaviour matches the source theme's
 * always-on system out of the box; the hidden-field trick in the checkbox
 * markup below is what makes an explicit uncheck actually persist as off
 * rather than silently reverting to "on" (unchecked checkboxes submit
 * nothing at all, not a false value).
 *
 * @return bool
 */
function lcp_yeast_seo_schema_enabled() {
	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	return ! isset( $settings['enable_schema'] ) || ! empty( $settings['enable_schema'] );
}

/**
 * Register the settings page, section, and field.
 *
 * @return void
 */
function lcp_yeast_seo_register_settings_page() {
	add_menu_page(
		'Yeast SEO',
		'Yeast SEO',
		'manage_options',
		'lcp-yeast-seo',
		'lcp_yeast_seo_render_settings_page',
		'dashicons-search',
		80
	);

	register_setting( 'lcp_yeast_seo_settings', LCP_YEAST_SEO_SETTINGS_OPTION, 'lcp_yeast_seo_sanitize_settings' );

	add_settings_section( 'lcp_yeast_seo_schema', 'Schema', '__return_false', 'lcp-yeast-seo' );
	add_settings_section( 'lcp_yeast_seo_defaults', 'Defaults', '__return_false', 'lcp-yeast-seo' );
	add_settings_section( 'lcp_yeast_seo_sitemaps', 'XML Sitemaps', '__return_false', 'lcp-yeast-seo' );

	add_settings_field(
		'enable_schema',
		'Enable Schema Markup',
		'lcp_yeast_seo_render_enable_schema_field',
		'lcp-yeast-seo',
		'lcp_yeast_seo_schema'
	);

	add_settings_field(
		'default_social_image',
		'Default Social Image URL',
		'lcp_yeast_seo_render_default_social_image_field',
		'lcp-yeast-seo',
		'lcp_yeast_seo_defaults'
	);

	add_settings_field(
		'twitter_site',
		'Twitter/X Site Handle',
		'lcp_yeast_seo_render_twitter_site_field',
		'lcp-yeast-seo',
		'lcp_yeast_seo_defaults'
	);

	add_settings_field(
		'enable_sitemaps',
		'Enable XML Sitemaps',
		'lcp_yeast_seo_render_enable_sitemaps_field',
		'lcp-yeast-seo',
		'lcp_yeast_seo_sitemaps'
	);

	add_settings_field(
		'sitemap_post_types',
		'Included Post Types',
		'lcp_yeast_seo_render_sitemap_post_types_field',
		'lcp-yeast-seo',
		'lcp_yeast_seo_sitemaps'
	);
}
add_action( 'admin_menu', 'lcp_yeast_seo_register_settings_page' );

/**
 * Render the enable/disable checkbox.
 *
 * @return void
 */
function lcp_yeast_seo_render_enable_schema_field() {
	$checked = lcp_yeast_seo_schema_enabled();
	?>
	<label>
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[enable_schema]" value="0">
		<input
			type="checkbox"
			name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[enable_schema]"
			value="1"
			<?php checked( $checked ); ?>
		>
		Output the per-post JSON-LD schema field and the Yoast Organization-node suppression filter.
	</label>
	<p class="description">Turning this off stops schema output entirely and leaves Yoast's own output untouched — the per-post field content is preserved either way.</p>
	<?php
}

/**
 * Render the default social image field.
 *
 * @return void
 */
function lcp_yeast_seo_render_default_social_image_field() {
	$value = lcp_yeast_seo_get_setting( 'default_social_image', '' );
	?>
	<input type="url" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[default_social_image]" value="<?php echo esc_attr( $value ); ?>" class="regular-text code">
	<p class="description">Fallback Open Graph and Twitter image when a post does not set its own.</p>
	<?php
}

/**
 * Render the Twitter/X site handle field.
 *
 * @return void
 */
function lcp_yeast_seo_render_twitter_site_field() {
	$value = lcp_yeast_seo_get_setting( 'twitter_site', '' );
	?>
	<input type="text" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[twitter_site]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
	<p class="description">Optional handle like <code>@chillibyte</code> for <code>twitter:site</code>.</p>
	<?php
}

/**
 * Whether XML sitemaps are enabled.
 *
 * @return bool
 */
function lcp_yeast_seo_sitemaps_enabled() {
	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	return ! isset( $settings['enable_sitemaps'] ) || ! empty( $settings['enable_sitemaps'] );
}

/**
 * Whether a given post type should appear in the sitemap.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function lcp_yeast_seo_sitemap_post_type_enabled( $post_type ) {
	$settings   = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	$post_types = isset( $settings['sitemap_post_types'] ) && is_array( $settings['sitemap_post_types'] ) ? $settings['sitemap_post_types'] : array();

	return ! isset( $post_types[ $post_type ] ) || ! empty( $post_types[ $post_type ] );
}

/**
 * Render the enable sitemaps field.
 *
 * @return void
 */
function lcp_yeast_seo_render_enable_sitemaps_field() {
	$checked = lcp_yeast_seo_sitemaps_enabled();
	?>
	<label>
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[enable_sitemaps]" value="0">
		<input type="checkbox" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[enable_sitemaps]" value="1" <?php checked( $checked ); ?>>
		Generate a Yoast-style <code>sitemap_index.xml</code> and per-post-type sitemap files.
	</label>
	<?php
}

/**
 * Render the sitemap post type checkboxes.
 *
 * @return void
 */
function lcp_yeast_seo_render_sitemap_post_types_field() {
	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		$obj = get_post_type_object( $post_type );
		?>
		<label style="display:block;margin-bottom:8px;">
			<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[sitemap_post_types][<?php echo esc_attr( $post_type ); ?>]" value="0">
			<input type="checkbox" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[sitemap_post_types][<?php echo esc_attr( $post_type ); ?>]" value="1" <?php checked( lcp_yeast_seo_sitemap_post_type_enabled( $post_type ) ); ?>>
			<?php echo esc_html( $obj ? $obj->labels->singular_name : $post_type ); ?>
			<code><?php echo esc_html( $post_type ); ?></code>
		</label>
		<?php
	}

	if ( lcp_yeast_seo_sitemaps_enabled() ) {
		?>
		<p class="description">Index URL: <code><?php echo esc_html( home_url( '/sitemap_index.xml' ) ); ?></code></p>
		<?php
	}
}

/**
 * Settings page HTML.
 *
 * @return void
 */
function lcp_yeast_seo_render_settings_page() {
	?>
	<div class="wrap">
		<h1>Yeast SEO</h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'lcp_yeast_seo_settings' );
			do_settings_sections( 'lcp-yeast-seo' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
