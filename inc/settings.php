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
 * Whether Yoast SEO is active, so Yoast-specific wording and behaviour are
 * only shown where they mean something.
 *
 * @return bool
 */
function lcp_yeast_seo_yoast_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' );
}

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
	) + lcp_yeast_seo_sanitize_organization( $input );
}

/**
 * Organization schema fields: setting key => label, input type, help.
 *
 * @return array[]
 */
function lcp_yeast_seo_organization_text_fields() {
	return array(
		'org_name'        => array( 'Organisation name', 'text', 'The name shown to search engines. Nothing is output until this is filled in.' ),
		'org_legal_name'  => array( 'Legal name', 'text', 'Registered company name, if different.' ),
		'org_url'         => array( 'URL', 'url', 'Defaults to the site home page when empty.' ),
		'org_description' => array( 'Description', 'textarea', '' ),
		'org_email'       => array( 'Email', 'email', '' ),
		'org_phone'       => array( 'Phone', 'text', 'Include the country code if you can, e.g. +1 407-349-3590.' ),
		'org_street'      => array( 'Street address', 'text', '' ),
		'org_locality'    => array( 'City / locality', 'text', '' ),
		'org_region'      => array( 'State / region', 'text', '' ),
		'org_postcode'    => array( 'Postcode / ZIP', 'text', '' ),
		'org_country'     => array( 'Country', 'text', 'Two-letter code (US, GB) preferred.' ),
		'org_founded'     => array( 'Founding date', 'text', 'YYYY, YYYY-MM or YYYY-MM-DD.' ),
	);
}

/**
 * Organization schema @type choices.
 *
 * @return string[] Type => label.
 */
function lcp_yeast_seo_organization_type_choices() {
	return array(
		'Organization'        => 'Organization',
		'Corporation'         => 'Corporation',
		'LocalBusiness'       => 'Local business',
		'ProfessionalService' => 'Professional service',
	);
}

/**
 * Sanitizes the Organization schema settings.
 *
 * @param array $input Raw submitted settings.
 * @return array
 */
function lcp_yeast_seo_sanitize_organization( $input ) {
	$out = array();

	foreach ( lcp_yeast_seo_organization_text_fields() as $key => $field ) {
		$raw = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';

		switch ( $field[1] ) {
			case 'url':
				$out[ $key ] = esc_url_raw( $raw );
				break;
			case 'email':
				$out[ $key ] = sanitize_email( $raw );
				break;
			case 'textarea':
				$out[ $key ] = sanitize_textarea_field( $raw );
				break;
			default:
				$out[ $key ] = sanitize_text_field( $raw );
		}
	}

	if ( ! preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $out['org_founded'] ) ) {
		$out['org_founded'] = '';
	}

	$type             = isset( $input['org_type'] ) ? sanitize_text_field( wp_unslash( $input['org_type'] ) ) : '';
	$out['org_type']  = isset( lcp_yeast_seo_organization_type_choices()[ $type ] ) ? $type : 'Organization';
	$out['org_logo']  = isset( $input['org_logo'] ) ? absint( $input['org_logo'] ) : 0;

	$urls = array();
	foreach ( preg_split( '/\R/', isset( $input['org_same_as'] ) ? (string) wp_unslash( $input['org_same_as'] ) : '' ) as $line ) {
		$url = esc_url_raw( trim( $line ), array( 'http', 'https' ) );
		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}
	$out['org_same_as'] = implode( "\n", array_unique( $urls ) );

	$out['org_show_front']   = empty( $input['org_show_front'] ) ? '0' : '1';
	$out['org_show_all']     = empty( $input['org_show_all'] ) ? '0' : '1';
	$out['org_contact_page'] = isset( $input['org_contact_page'] ) ? absint( $input['org_contact_page'] ) : 0;
	$out['org_extra_pages']  = isset( $input['org_extra_pages'] ) && is_array( $input['org_extra_pages'] )
		? array_values( array_unique( array_filter( array_map( 'absint', $input['org_extra_pages'] ) ) ) )
		: array();

	return $out;
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
	add_settings_section( 'lcp_yeast_seo_organization', 'Organization schema', 'lcp_yeast_seo_render_organization_intro', 'lcp-yeast-seo' );

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

	add_settings_field( 'org_type', 'Type', 'lcp_yeast_seo_render_org_type_field', 'lcp-yeast-seo', 'lcp_yeast_seo_organization' );

	foreach ( lcp_yeast_seo_organization_text_fields() as $key => $field ) {
		add_settings_field(
			$key,
			$field[0],
			'lcp_yeast_seo_render_org_text_field',
			'lcp-yeast-seo',
			'lcp_yeast_seo_organization',
			array(
				'key'       => $key,
				'type'      => $field[1],
				'help'      => $field[2],
				'label_for' => 'lcp-yeast-seo-' . $key,
			)
		);
	}

	add_settings_field( 'org_logo', 'Logo', 'lcp_yeast_seo_render_org_logo_field', 'lcp-yeast-seo', 'lcp_yeast_seo_organization' );
	add_settings_field( 'org_same_as', 'Social profiles (sameAs)', 'lcp_yeast_seo_render_org_same_as_field', 'lcp-yeast-seo', 'lcp_yeast_seo_organization' );
	add_settings_field( 'org_display', 'Display on', 'lcp_yeast_seo_render_org_display_field', 'lcp-yeast-seo', 'lcp_yeast_seo_organization' );
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
		<?php echo lcp_yeast_seo_yoast_active() ? 'Output the per-post JSON-LD schema field and the Yoast Organization-node suppression filter.' : 'Output the Organization schema and the per-post JSON-LD schema field.'; ?>
	</label>
	<p class="description">Turning this off stops schema output entirely<?php echo lcp_yeast_seo_yoast_active() ? ' and leaves Yoast\'s own output untouched' : ''; ?> — the per-post field content is preserved either way.</p>
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
		Generate a <code>sitemap_index.xml</code> and per-post-type sitemap files.
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

/**
 * Loads the media picker for the Organization logo on the settings page only.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function lcp_yeast_seo_settings_assets( $hook ) {
	if ( 'toplevel_page_lcp-yeast-seo' !== $hook ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_script( 'lcp-yeast-seo-settings', LCP_YEAST_SEO_URL . 'assets/settings.js', array(), LCP_YEAST_SEO_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'lcp_yeast_seo_settings_assets' );

/**
 * Intro text for the Organization section.
 *
 * @return void
 */
function lcp_yeast_seo_render_organization_intro() {
	echo '<p>Describes the business in JSON-LD. Only fields with a value are output, and nothing is output until a name is set. A page whose own schema field declares an Organization keeps that instead.</p>';
}

/**
 * Render the Organization @type select.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_type_field() {
	$current = lcp_yeast_seo_get_setting( 'org_type', 'Organization' );
	?>
	<select name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[org_type]">
		<?php foreach ( lcp_yeast_seo_organization_type_choices() as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Render one Organization text-style field.
 *
 * @param array $args Field args: key, type, help.
 * @return void
 */
function lcp_yeast_seo_render_org_text_field( $args ) {
	$name  = LCP_YEAST_SEO_SETTINGS_OPTION . '[' . $args['key'] . ']';
	$value = lcp_yeast_seo_get_setting( $args['key'], '' );
	$id    = 'lcp-yeast-seo-' . $args['key'];

	if ( 'textarea' === $args['type'] ) {
		printf( '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
	} else {
		printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text">', esc_attr( $args['type'] ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
	}

	if ( '' !== $args['help'] ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
}

/**
 * Render the logo media picker.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_logo_field() {
	$id  = absint( lcp_yeast_seo_get_setting( 'org_logo', 0 ) );
	$img = $id ? wp_get_attachment_image( $id, 'medium', false, array( 'style' => 'max-width:200px;height:auto;' ) ) : '';
	?>
	<div class="lcp-yeast-seo-logo">
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[org_logo]" value="<?php echo esc_attr( $id ); ?>" data-logo-input>
		<div data-logo-preview><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<button type="button" class="button" data-logo-select>Choose logo</button>
		<button type="button" class="button-link" data-logo-remove <?php echo $id ? '' : 'hidden'; ?>>Remove</button>
	</div>
	<?php
}

/**
 * Render the sameAs textarea.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_same_as_field() {
	?>
	<textarea name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[org_same_as]" rows="5" class="large-text code"><?php echo esc_textarea( lcp_yeast_seo_get_setting( 'org_same_as', '' ) ); ?></textarea>
	<p class="description">One profile URL per line (LinkedIn, Facebook, Instagram, X, YouTube…).</p>
	<?php
}

/**
 * Whether the home page shows the Organization schema. On unless switched off.
 *
 * @return bool
 */
function lcp_yeast_seo_org_show_front() {
	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	return ! isset( $settings['org_show_front'] ) || ! empty( $settings['org_show_front'] );
}

/**
 * Render the "Display on" controls.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_display_field() {
	$option  = esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION );
	$extra   = (array) lcp_yeast_seo_get_setting( 'org_extra_pages', array() );
	$pages   = get_pages( array( 'sort_column' => 'post_title' ) );
	?>
	<label style="display:block;margin-bottom:8px;">
		<input type="hidden" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[org_show_front]" value="0">
		<input type="checkbox" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[org_show_front]" value="1" <?php checked( lcp_yeast_seo_org_show_front() ); ?>>
		Home page (front page)
	</label>
	<p style="margin:0 0 8px;">
		<label for="lcp-yeast-seo-org-contact">Contact page</label><br>
		<?php
		wp_dropdown_pages(
			array(
				'name'             => LCP_YEAST_SEO_SETTINGS_OPTION . '[org_contact_page]',
				'id'               => 'lcp-yeast-seo-org-contact',
				'selected'         => absint( lcp_yeast_seo_get_setting( 'org_contact_page', 0 ) ),
				'show_option_none' => '— None —',
				'option_none_value' => '0',
			)
		);
		?>
	</p>
	<p style="margin:0 0 8px;">
		<label for="lcp-yeast-seo-org-extra">Other pages (hold Ctrl/Cmd to select several)</label><br>
		<select id="lcp-yeast-seo-org-extra" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[org_extra_pages][]" multiple size="6" style="min-width:20em;">
			<?php foreach ( $pages as $page ) : ?>
				<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( in_array( $page->ID, array_map( 'absint', $extra ), true ) ); ?>><?php echo esc_html( $page->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<label style="display:block;">
		<input type="hidden" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[org_show_all]" value="0">
		<input type="checkbox" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[org_show_all]" value="1" <?php checked( '1' === (string) lcp_yeast_seo_get_setting( 'org_show_all', '0' ) ); ?>>
		Every page on the site (overrides the choices above)
	</label>
	<?php
}
