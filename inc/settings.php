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
 * Reads an on/off setting, falling back to $default until it has been saved.
 *
 * @param string $key     Setting key.
 * @param bool   $default Value when the setting has never been saved.
 * @return bool
 */
function lcp_yeast_seo_flag_setting( $key, $default = true ) {
	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );

	return isset( $settings[ $key ] ) ? ! empty( $settings[ $key ] ) : $default;
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
		'sitemap_images'            => empty( $input['sitemap_images'] ) ? '0' : '1',
		'auto_schema'               => empty( $input['auto_schema'] ) ? '0' : '1',
		'auto_schema_kinds'         => lcp_yeast_seo_sanitize_auto_schema_kinds( $input['auto_schema_kinds'] ?? array() ),
		'robots_txt'                => lcp_yeast_seo_sanitize_robots_setting( $input ),
		'article_author'            => isset( $input['article_author'] ) && 'organization' === $input['article_author'] ? 'organization' : 'person',
		'auto_description'          => empty( $input['auto_description'] ) ? '0' : '1',
		'title_sep'                 => isset( $input['title_sep'] ) ? lcp_yeast_seo_sanitize_title_sep( wp_unslash( $input['title_sep'] ) ) : '|',
		'title_templates'           => lcp_yeast_seo_sanitize_title_templates( $input['title_templates'] ?? array() ),
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
 * Whether the "build from fields" Organization editor is shown.
 *
 * Off by default: a fixed set of fields can't express owners, opening hours,
 * reviews and the rest of what an Organization node can carry, so the raw
 * JSON-LD box is the only editor until that is designed. Sites still holding
 * field values are migrated to raw JSON-LD on first admin load, and the
 * stored field values are kept untouched. Return true from this filter to
 * bring the field editor back.
 *
 * @return bool
 */
function lcp_yeast_seo_org_fields_ui() {
	return (bool) apply_filters( 'lcp_yeast_seo_org_fields_ui', false );
}

/**
 * Converts a settings-driven (fields) Organization into raw JSON-LD, once, so
 * hiding the field editor changes nothing on the front end.
 *
 * @return void
 */
function lcp_yeast_seo_migrate_org_fields_to_raw() {
	if ( lcp_yeast_seo_org_fields_ui() || lcp_yeast_seo_org_raw_mode() || '' === lcp_yeast_seo_get_setting( 'org_name', '' ) ) {
		return;
	}

	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );

	if ( '' !== trim( (string) ( $settings['org_raw'] ?? '' ) ) ) {
		$settings['org_mode'] = 'raw';
	} else {
		$settings['org_raw']  = wp_json_encode( lcp_yeast_seo_build_organization(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$settings['org_mode'] = 'raw';
	}

	update_option( LCP_YEAST_SEO_SETTINGS_OPTION, $settings );
}
add_action( 'admin_init', 'lcp_yeast_seo_migrate_org_fields_to_raw', 20 );

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

	$out['org_mode'] = isset( $input['org_mode'] ) && 'raw' === $input['org_mode'] ? 'raw' : 'fields';
	$out['org_raw']  = isset( $input['org_raw'] ) ? trim( (string) wp_unslash( $input['org_raw'] ) ) : '';

	if ( '' !== $out['org_raw'] ) {
		foreach ( lcp_yeast_seo_validate_schema_value( $out['org_raw'] ) as $problem ) {
			add_settings_error( LCP_YEAST_SEO_SETTINGS_OPTION, 'lcp_yeast_seo_org_raw', 'Organization JSON-LD: ' . $problem, 'warning' );
		}
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

	if ( ! lcp_yeast_seo_org_fields_ui() ) {
		// The field editor isn't on the page, so its inputs aren't submitted.
		// Keep what is stored rather than blanking it, and stay in raw mode.
		$existing    = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
		$field_keys = array_merge( array_keys( lcp_yeast_seo_organization_text_fields() ), array( 'org_type', 'org_logo', 'org_same_as' ) );

		foreach ( $field_keys as $key ) {
			if ( isset( $existing[ $key ] ) ) {
				$out[ $key ] = $existing[ $key ];
			}
		}

		$out['org_mode'] = 'raw';
	}

	return $out;
}

/**
 * Sanitizes the title separator (one short symbol).
 *
 * @param mixed $value Raw value.
 * @return string
 */
function lcp_yeast_seo_sanitize_title_sep( $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );

	return '' === $value ? '|' : mb_substr( $value, 0, 3 );
}

/**
 * Sanitizes the per-post-type title templates.
 *
 * @param mixed $input Submitted values, post type => template.
 * @return array
 */
function lcp_yeast_seo_sanitize_title_templates( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = array();

	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		$template = isset( $input[ $post_type ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $post_type ] ) ) ) : '';

		if ( '' !== $template ) {
			$out[ $post_type ] = $template;
		}
	}

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

	add_settings_section( 'lcp_yeast_seo_schema', 'Schema', '__return_false', 'lcp-yeast-seo-schema' );
	add_settings_section( 'lcp_yeast_seo_titles', 'Titles and descriptions', 'lcp_yeast_seo_render_titles_intro', 'lcp-yeast-seo' );
	add_settings_section( 'lcp_yeast_seo_defaults', 'Defaults', '__return_false', 'lcp-yeast-seo' );
	add_settings_section( 'lcp_yeast_seo_sitemaps', 'XML Sitemaps', '__return_false', 'lcp-yeast-seo' );
	add_settings_section( 'lcp_yeast_seo_organization', 'Organization schema', 'lcp_yeast_seo_render_organization_intro', 'lcp-yeast-seo-schema' );

	add_settings_field(
		'enable_schema',
		'Enable Schema Markup',
		'lcp_yeast_seo_render_enable_schema_field',
		'lcp-yeast-seo-schema',
		'lcp_yeast_seo_schema'
	);

	if ( ! lcp_yeast_seo_yoast_active() ) {
		add_settings_field(
			'auto_schema',
			'Automatic structured data',
			'lcp_yeast_seo_render_auto_schema_field',
			'lcp-yeast-seo-schema',
			'lcp_yeast_seo_schema'
		);

		add_settings_field(
			'auto_schema_kinds',
			'Generate',
			'lcp_yeast_seo_render_auto_schema_kinds_field',
			'lcp-yeast-seo-schema',
			'lcp_yeast_seo_schema'
		);

		add_settings_field(
			'article_author',
			'Article author',
			'lcp_yeast_seo_render_article_author_field',
			'lcp-yeast-seo-schema',
			'lcp_yeast_seo_schema'
		);
	}

	add_settings_field( 'title_sep', 'Title separator', 'lcp_yeast_seo_render_title_sep_field', 'lcp-yeast-seo', 'lcp_yeast_seo_titles' );
	add_settings_field( 'title_templates', 'Title templates', 'lcp_yeast_seo_render_title_templates_field', 'lcp-yeast-seo', 'lcp_yeast_seo_titles' );
	add_settings_field( 'auto_description', 'Automatic description', 'lcp_yeast_seo_render_auto_description_field', 'lcp-yeast-seo', 'lcp_yeast_seo_titles' );

	add_settings_field(
		'default_social_image',
		'Default Social Image',
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

	add_settings_field(
		'sitemap_images',
		'Images',
		'lcp_yeast_seo_render_sitemap_images_field',
		'lcp-yeast-seo',
		'lcp_yeast_seo_sitemaps'
	);

	if ( lcp_yeast_seo_org_fields_ui() ) {
		add_settings_field( 'org_mode', 'Source', 'lcp_yeast_seo_render_org_mode_field', 'lcp-yeast-seo-schema', 'lcp_yeast_seo_organization' );
	}

	add_settings_field( 'org_raw', 'JSON-LD', 'lcp_yeast_seo_render_org_raw_field', 'lcp-yeast-seo-schema', 'lcp_yeast_seo_organization' );

	if ( lcp_yeast_seo_org_fields_ui() ) {
		add_settings_field( 'org_type', 'Type', 'lcp_yeast_seo_render_org_type_field', 'lcp-yeast-seo-schema', 'lcp_yeast_seo_organization' );

		foreach ( lcp_yeast_seo_organization_text_fields() as $key => $field ) {
			add_settings_field(
				$key,
				$field[0],
				'lcp_yeast_seo_render_org_text_field',
				'lcp-yeast-seo-schema',
				'lcp_yeast_seo_organization',
				array(
					'key'       => $key,
					'type'      => $field[1],
					'help'      => $field[2],
					'label_for' => 'lcp-yeast-seo-' . $key,
				)
			);
		}

		add_settings_field( 'org_logo', 'Logo', 'lcp_yeast_seo_render_org_logo_field', 'lcp-yeast-seo-schema', 'lcp_yeast_seo_organization' );
		add_settings_field( 'org_same_as', 'Social profiles (sameAs)', 'lcp_yeast_seo_render_org_same_as_field', 'lcp-yeast-seo-schema', 'lcp_yeast_seo_organization' );
	}

	add_settings_field( 'org_display', 'Display on', 'lcp_yeast_seo_render_org_display_field', 'lcp-yeast-seo-schema', 'lcp_yeast_seo_organization' );
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
	lcp_yeast_seo_render_image_picker( LCP_YEAST_SEO_SETTINGS_OPTION . '[default_social_image]', (string) lcp_yeast_seo_get_setting( 'default_social_image', '' ), 'Default social image', 'Use as default' );
	?>
	<p class="description">Fallback Open Graph and Twitter image when a post does not set its own and has no featured image. Choose one from the media library or upload a new one; 1200 &times; 630 pixels works best.</p>
	<?php
}

/**
 * Prints a media-library image picker that stores the image's URL.
 *
 * Needs assets/image-picker.js and wp_enqueue_media() on the page.
 *
 * @param string $name   Form field name.
 * @param string $url    Current image URL, or ''.
 * @param string $title  Title of the media modal.
 * @param string $button Label of the modal's select button.
 * @return void
 */
function lcp_yeast_seo_render_image_picker( $name, $url, $title = 'Choose image', $button = 'Use this image' ) {
	$url = (string) $url;
	$id  = '' !== $url ? attachment_url_to_postid( $url ) : 0;
	?>
	<div class="lcp-yeast-seo-image" data-image-picker data-image-id="<?php echo (int) $id; ?>" data-title="<?php echo esc_attr( $title ); ?>" data-button="<?php echo esc_attr( $button ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $url ); ?>" data-image-input>
		<div class="lcp-yeast-seo-image__preview" data-image-preview><?php echo '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="">' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
		<button type="button" class="button" data-image-select><?php echo '' !== $url ? 'Change image' : 'Choose image'; ?></button>
		<button type="button" class="button-link" data-image-remove <?php echo '' !== $url ? '' : 'hidden'; ?>>Remove</button>
	</div>
	<?php
}

/**
 * Loads the picker script and styles.
 *
 * @return void
 */
function lcp_yeast_seo_enqueue_image_picker() {
	wp_enqueue_media();

	foreach ( array(
		'style'  => 'image-picker.css',
		'script' => 'image-picker.js',
	) as $kind => $file ) {
		$path   = LCP_YEAST_SEO_PATH . 'assets/' . $file;
		$ver    = file_exists( $path ) ? filemtime( $path ) : LCP_YEAST_SEO_VERSION;
		$handle = 'lcp-yeast-seo-image-picker';

		if ( 'style' === $kind ) {
			wp_enqueue_style( $handle, LCP_YEAST_SEO_URL . 'assets/' . $file, array(), $ver );
		} else {
			wp_enqueue_script( $handle, LCP_YEAST_SEO_URL . 'assets/' . $file, array(), $ver, true );
		}
	}
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
	<p class="description">Optional handle like <code>@yourhandle</code> for <code>twitter:site</code>.</p>
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
 * Render the sitemap images checkbox.
 *
 * @return void
 */
function lcp_yeast_seo_render_sitemap_images_field() {
	?>
	<label>
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[sitemap_images]" value="0">
		<input type="checkbox" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[sitemap_images]" value="1" <?php checked( lcp_yeast_seo_sitemap_images_enabled() ); ?>>
		List each page's featured image and the images in its content (up to 10 per page).
	</label>
	<?php
}

/**
 * Render the automatic structured data checkbox.
 *
 * @return void
 */
function lcp_yeast_seo_render_auto_schema_field() {
	?>
	<label>
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[auto_schema]" value="0">
		<input type="checkbox" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[auto_schema]" value="1" <?php checked( lcp_yeast_seo_auto_schema_enabled() ); ?>>
		Generate WebSite, WebPage, Article and BreadcrumbList data for pages and posts.
	</label>
	<p class="description">A type is only generated where the page's own schema field doesn't already declare it, so hand-written schema always wins.</p>
	<?php
}

/**
 * Render the per-kind switches for automatic structured data.
 *
 * @return void
 */
function lcp_yeast_seo_render_auto_schema_kinds_field() {
	$disabled = lcp_yeast_seo_auto_schema_disabled_kinds();

	foreach ( lcp_yeast_seo_auto_schema_kinds() as $kind => $label ) {
		$name = LCP_YEAST_SEO_SETTINGS_OPTION . '[auto_schema_kinds][' . $kind . ']';
		?>
		<label style="display:block;margin-bottom:6px;">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! in_array( $kind, $disabled, true ) ); ?>>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}
	?>
	<p class="description">Each is added only where the page doesn't already have it, whether from the page's own schema field or something your theme prints (such as a breadcrumbs block), so leaving them all on is safe. Untick one to stop the plugin generating it at all.</p>
	<?php
}

/**
 * Render the article author select.
 *
 * @return void
 */
function lcp_yeast_seo_render_article_author_field() {
	$current = lcp_yeast_seo_article_author_is_organization() ? 'organization' : 'person';
	?>
	<select name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[article_author]">
		<option value="person" <?php selected( $current, 'person' ); ?>>The post's author (a Person)</option>
		<option value="organization" <?php selected( $current, 'organization' ); ?>>The site, as an Organization</option>
	</select>
	<p class="description">Who posts are credited to in their structured data. Choose the site if you publish under one name and don't want WordPress usernames shown.</p>
	<?php
}

/**
 * Intro text for the titles section.
 *
 * @return void
 */
function lcp_yeast_seo_render_titles_intro() {
	if ( lcp_yeast_seo_yoast_active() ) {
		echo '<p>Yoast SEO is active and builds the default titles and descriptions, so the templates below are not used. Per-post overrides still apply.</p>';
		return;
	}

	echo '<p>Defaults for pages that have no title or description of their own. A page\'s own value is always used as typed.</p>';
}

/**
 * Render the title separator field.
 *
 * @return void
 */
function lcp_yeast_seo_render_title_sep_field() {
	?>
	<input type="text" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[title_sep]" value="<?php echo esc_attr( lcp_yeast_seo_title_sep() ); ?>" class="small-text" maxlength="3">
	<p class="description">Used for <code>%sep%</code> in the templates below, e.g. <code>|</code>, <code>-</code> or <code>&middot;</code>.</p>
	<?php
}

/**
 * Render the per-post-type title template fields.
 *
 * @return void
 */
function lcp_yeast_seo_render_title_templates_field() {
	$templates = lcp_yeast_seo_get_setting( 'title_templates', array() );
	$templates = is_array( $templates ) ? $templates : array();

	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		$obj = get_post_type_object( $post_type );
		?>
		<p style="margin:0 0 8px;">
			<label for="lcp-yeast-seo-tpl-<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $obj ? $obj->labels->singular_name : $post_type ); ?> <code><?php echo esc_html( $post_type ); ?></code></label><br>
			<input type="text" id="lcp-yeast-seo-tpl-<?php echo esc_attr( $post_type ); ?>" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[title_templates][<?php echo esc_attr( $post_type ); ?>]" value="<?php echo esc_attr( $templates[ $post_type ] ?? '' ); ?>" class="large-text" placeholder="<?php echo esc_attr( 'e.g. ' . LCP_YEAST_SEO_DEFAULT_TITLE_TEMPLATE ); ?>">
		</p>
		<?php
	}
	?>
	<p class="description">Leave a box empty to keep WordPress's normal title. Tags: <code>%title%</code> <code>%sitename%</code> <code>%tagline%</code> <code>%sep%</code>.</p>
	<?php
}

/**
 * Render the automatic description checkbox.
 *
 * @return void
 */
function lcp_yeast_seo_render_auto_description_field() {
	?>
	<label>
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[auto_description]" value="0">
		<input type="checkbox" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[auto_description]" value="1" <?php checked( lcp_yeast_seo_auto_description_enabled() ); ?>>
		When a page has no meta description, use its excerpt or the start of its content.
	</label>
	<?php
}

/**
 * Settings page HTML.
 *
 * @return void
 */
function lcp_yeast_seo_render_settings_page() {
	$tabs = array(
		'general' => array( 'General', 'lcp-yeast-seo' ),
		'schema'  => array( 'Schema', 'lcp-yeast-seo-schema' ),
		'robots'  => array( 'robots.txt', 'lcp-yeast-seo-robots' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
	$is_tool   = in_array( $requested, array( 'health', 'import-export' ), true );
	$active    = $is_tool ? $requested : ( isset( $tabs[ $requested ] ) ? $requested : 'general' );
	$tab_url   = static function ( $slug ) {
		return add_query_arg( array( 'page' => 'lcp-yeast-seo', 'tab' => $slug ), admin_url( 'admin.php' ) );
	};
	?>
	<div class="wrap lcp-ys">
		<?php lcp_yeast_seo_render_admin_header( $tabs, $active, ! $is_tool, $tab_url ); ?>
		<?php settings_errors(); ?>
		<?php if ( 'health' === $active ) : ?>
			<?php lcp_yeast_seo_health_render_tab(); ?>
		<?php elseif ( 'import-export' === $active ) : ?>
			<?php lcp_yeast_seo_ie_render_tab(); ?>
		<?php else : ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'lcp_yeast_seo_settings' ); ?>
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<div class="lcp-yeast-seo-panel" data-panel="<?php echo esc_attr( $slug ); ?>" <?php echo $slug === $active ? '' : 'hidden'; ?>>
						<?php lcp_yeast_seo_render_sections( $tab[1] ); ?>
					</div>
				<?php endforeach; ?>
				<div class="lcp-ys-savebar">
					<?php submit_button( 'Save changes', 'primary', 'submit', false ); ?>
					<span class="lcp-ys-savebar__hint">Changes on every tab are saved together.</span>
				</div>
			</form>
			<?php if ( is_file( lcp_yeast_seo_robots_file_path() ) ) : ?>
				<form id="lcp-yeast-seo-robots-takeover" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lcp_yeast_seo_robots_takeover">
					<?php wp_nonce_field( 'lcp_yeast_seo_robots_takeover' ); ?>
				</form>
			<?php endif; ?>
		<?php endif; ?>
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

	lcp_yeast_seo_enqueue_image_picker();
	$script_path = LCP_YEAST_SEO_PATH . 'assets/settings.js';
	wp_enqueue_script( 'lcp-yeast-seo-settings', LCP_YEAST_SEO_URL . 'assets/settings.js', array(), file_exists( $script_path ) ? filemtime( $script_path ) : LCP_YEAST_SEO_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'lcp_yeast_seo_settings_assets' );

/**
 * Intro text for the Organization section.
 *
 * @return void
 */
function lcp_yeast_seo_render_organization_intro() {
	echo '<p>Paste the Organization JSON-LD for this site. It is output only on the pages chosen under Display on; a page whose own schema field declares an Organization keeps that instead.</p>';
}

/**
 * Render the fields / raw JSON-LD source toggle.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_mode_field() {
	$mode = lcp_yeast_seo_get_setting( 'org_mode', 'fields' );
	?>
	<?php
	$logo_id = absint( lcp_yeast_seo_get_setting( 'org_logo', 0 ) );
	$logo    = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
	?>
	<fieldset data-org-mode data-org-id="<?php echo esc_attr( lcp_yeast_seo_organization_id() ); ?>" data-org-home="<?php echo esc_attr( home_url( '/' ) ); ?>" <?php echo $logo ? 'data-logo-url="' . esc_attr( $logo[0] ) . '" data-logo-w="' . (int) $logo[1] . '" data-logo-h="' . (int) $logo[2] . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<label style="margin-right:1.5em;"><input type="radio" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[org_mode]" value="fields" <?php checked( 'raw' !== $mode ); ?>> Build from fields</label>
		<label><input type="radio" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[org_mode]" value="raw" <?php checked( 'raw' === $mode ); ?>> Paste raw JSON-LD</label>
	</fieldset>
	<p class="description">The display rules below apply either way. Switching copies the current values across (asking first if it would overwrite something).</p>
	<?php
}

/**
 * Render the raw JSON-LD textarea.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_raw_field() {
	?>
	<textarea name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[org_raw]" rows="14" class="large-text code" style="font-family:monospace;" data-org-raw><?php echo esc_textarea( lcp_yeast_seo_get_setting( 'org_raw', '' ) ); ?></textarea>
	<p class="description">One JSON object, or an array/@graph of objects, with <code>@context</code> and an <code>@type</code>. Give the Organization an <code>@id</code> ending in <code>#organization</code>. Invalid JSON is not output.</p>
	<?php
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
