<?php
/**
 * Per-post SEO fields, editor UI, and front-end output.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_SCHEMA_META_KEY', 'lcp_yeast_seo_schema_jsonld' );
define( 'LCP_YEAST_SEO_TITLE_META_KEY', 'lcp_yeast_seo_title' );
define( 'LCP_YEAST_SEO_DESCRIPTION_META_KEY', 'lcp_yeast_seo_meta_description' );
define( 'LCP_YEAST_SEO_OG_TITLE_META_KEY', 'lcp_yeast_seo_og_title' );
define( 'LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY', 'lcp_yeast_seo_og_description' );
define( 'LCP_YEAST_SEO_OG_IMAGE_META_KEY', 'lcp_yeast_seo_og_image' );
define( 'LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY', 'lcp_yeast_seo_twitter_image' );
define( 'LCP_YEAST_SEO_ROBOTS_META_KEY', 'lcp_yeast_seo_robots_index' );

/**
 * Returns the post types this plugin attaches to.
 *
 * @return string[]
 */
function lcp_yeast_seo_post_types() {
	$post_types = get_post_types(
		array(
			'public'  => true,
			'show_ui' => true,
		),
		'names'
	);

	unset( $post_types['attachment'] );

	return $post_types;
}

/**
 * Registers all per-post SEO meta fields.
 *
 * @return void
 */
function lcp_yeast_seo_register_meta() {
	$fields = array(
		LCP_YEAST_SEO_TITLE_META_KEY         => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		LCP_YEAST_SEO_DESCRIPTION_META_KEY   => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_textarea_field',
		),
		LCP_YEAST_SEO_OG_TITLE_META_KEY      => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_textarea_field',
		),
		LCP_YEAST_SEO_OG_IMAGE_META_KEY      => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
		),
		LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
		),
		LCP_YEAST_SEO_ROBOTS_META_KEY        => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => 'index',
			'show_in_rest'      => true,
			'sanitize_callback' => 'lcp_yeast_seo_sanitize_robots_meta',
		),
		LCP_YEAST_SEO_SCHEMA_META_KEY        => array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'lcp_yeast_seo_sanitize_schema_meta',
		),
	);

	foreach ( lcp_yeast_seo_post_types() as $post_type ) {
		foreach ( $fields as $meta_key => $args ) {
			register_post_meta(
				$post_type,
				$meta_key,
				$args + array(
					'auth_callback' => function ( $allowed, $registered_meta_key, $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}
}
add_action( 'init', 'lcp_yeast_seo_register_meta' );

/**
 * Sanitizes the robots choice.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function lcp_yeast_seo_sanitize_robots_meta( $value ) {
	return 'noindex' === $value ? 'noindex' : 'index';
}

/**
 * Schema sanitizer shared between classic save and REST saves.
 *
 * @param mixed $value Raw meta value.
 * @return string
 */
function lcp_yeast_seo_sanitize_schema_meta( $value ) {
	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Whether a post type uses the block editor, where this plugin renders a
 * dedicated modal instead of classic meta boxes.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function lcp_yeast_seo_uses_block_editor( $post_type ) {
	return function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type );
}

/**
 * Registers a classic fallback meta box for non-block-editor screens.
 *
 * @param string $post_type Current post type.
 * @return void
 */
function lcp_yeast_seo_add_meta_box( $post_type ) {
	if ( lcp_yeast_seo_uses_block_editor( $post_type ) ) {
		return;
	}

	add_meta_box(
		'lcp-yeast-seo',
		__( 'Yeast SEO', 'lcp-yeast-seo' ),
		'lcp_yeast_seo_render_meta_box',
		$post_type,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'lcp_yeast_seo_add_meta_box' );

/**
 * Renders the classic-editor fallback meta box.
 *
 * @param WP_Post $post Current post.
 * @return void
 */
function lcp_yeast_seo_render_meta_box( $post ) {
	$title            = get_post_meta( $post->ID, LCP_YEAST_SEO_TITLE_META_KEY, true );
	$description      = get_post_meta( $post->ID, LCP_YEAST_SEO_DESCRIPTION_META_KEY, true );
	$og_title         = get_post_meta( $post->ID, LCP_YEAST_SEO_OG_TITLE_META_KEY, true );
	$og_description   = get_post_meta( $post->ID, LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY, true );
	$og_image         = get_post_meta( $post->ID, LCP_YEAST_SEO_OG_IMAGE_META_KEY, true );
	$twitter_image    = get_post_meta( $post->ID, LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY, true );
	$robots_index     = get_post_meta( $post->ID, LCP_YEAST_SEO_ROBOTS_META_KEY, true );
	$schema           = get_post_meta( $post->ID, LCP_YEAST_SEO_SCHEMA_META_KEY, true );
	$global_noindex   = ! get_option( 'blog_public' );
	$robots_index     = $robots_index ? $robots_index : 'index';

	wp_nonce_field( 'lcp_yeast_seo_save_meta_box', 'lcp_yeast_seo_meta_box_nonce' );
	?>
	<p>
		<label for="lcp-yeast-seo-title-field"><strong><?php esc_html_e( 'Page title', 'lcp-yeast-seo' ); ?></strong></label>
		<input type="text" id="lcp-yeast-seo-title-field" name="<?php echo esc_attr( LCP_YEAST_SEO_TITLE_META_KEY ); ?>" value="<?php echo esc_attr( $title ); ?>" class="widefat">
	</p>
	<p>
		<label for="lcp-yeast-seo-description-field"><strong><?php esc_html_e( 'Meta description', 'lcp-yeast-seo' ); ?></strong></label>
		<textarea id="lcp-yeast-seo-description-field" name="<?php echo esc_attr( LCP_YEAST_SEO_DESCRIPTION_META_KEY ); ?>" rows="3" class="widefat"><?php echo esc_textarea( $description ); ?></textarea>
	</p>
	<hr>
	<p>
		<label for="lcp-yeast-seo-robots-field"><strong><?php esc_html_e( 'Indexing', 'lcp-yeast-seo' ); ?></strong></label>
		<select id="lcp-yeast-seo-robots-field" name="<?php echo esc_attr( LCP_YEAST_SEO_ROBOTS_META_KEY ); ?>">
			<option value="index" <?php selected( $robots_index, 'index' ); ?>><?php esc_html_e( 'Index', 'lcp-yeast-seo' ); ?></option>
			<option value="noindex" <?php selected( $robots_index, 'noindex' ); ?>><?php esc_html_e( 'Noindex', 'lcp-yeast-seo' ); ?></option>
		</select>
	</p>
	<?php if ( $global_noindex ) : ?>
		<p class="description"><?php esc_html_e( 'WordPress is currently set to discourage search engines, so this page will output noindex regardless of the per-post setting.', 'lcp-yeast-seo' ); ?></p>
	<?php endif; ?>
	<hr>
	<p>
		<label for="lcp-yeast-seo-og-title-field"><strong><?php esc_html_e( 'Open Graph title', 'lcp-yeast-seo' ); ?></strong></label>
		<input type="text" id="lcp-yeast-seo-og-title-field" name="<?php echo esc_attr( LCP_YEAST_SEO_OG_TITLE_META_KEY ); ?>" value="<?php echo esc_attr( $og_title ); ?>" class="widefat">
	</p>
	<p>
		<label for="lcp-yeast-seo-og-description-field"><strong><?php esc_html_e( 'Open Graph description', 'lcp-yeast-seo' ); ?></strong></label>
		<textarea id="lcp-yeast-seo-og-description-field" name="<?php echo esc_attr( LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY ); ?>" rows="3" class="widefat"><?php echo esc_textarea( $og_description ); ?></textarea>
	</p>
	<p>
		<label for="lcp-yeast-seo-og-image-field"><strong><?php esc_html_e( 'Open Graph image URL', 'lcp-yeast-seo' ); ?></strong></label>
		<input type="url" id="lcp-yeast-seo-og-image-field" name="<?php echo esc_attr( LCP_YEAST_SEO_OG_IMAGE_META_KEY ); ?>" value="<?php echo esc_attr( $og_image ); ?>" class="widefat code">
	</p>
	<p>
		<label for="lcp-yeast-seo-twitter-image-field"><strong><?php esc_html_e( 'Twitter/X image URL', 'lcp-yeast-seo' ); ?></strong></label>
		<input type="url" id="lcp-yeast-seo-twitter-image-field" name="<?php echo esc_attr( LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY ); ?>" value="<?php echo esc_attr( $twitter_image ); ?>" class="widefat code">
	</p>
	<?php if ( lcp_yeast_seo_schema_enabled() ) : ?>
		<hr>
		<p>
			<label for="lcp-yeast-seo-schema-field"><strong><?php esc_html_e( 'Schema (JSON-LD)', 'lcp-yeast-seo' ); ?></strong></label>
		</p>
		<p>
			<?php esc_html_e( 'One JSON object, or an array/@graph of objects. Leave blank to use Yoast defaults. Saving with a problem will not be blocked; check for an admin notice after save.', 'lcp-yeast-seo' ); ?>
		</p>
		<textarea id="lcp-yeast-seo-schema-field" name="<?php echo esc_attr( LCP_YEAST_SEO_SCHEMA_META_KEY ); ?>" rows="10" class="widefat" style="font-family:monospace;"><?php echo esc_textarea( $schema ); ?></textarea>
	<?php endif; ?>
	<?php
}

/**
 * Saves the classic-editor fallback meta box.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function lcp_yeast_seo_save_meta_box( $post_id ) {
	if ( ! isset( $_POST['lcp_yeast_seo_meta_box_nonce'] ) ||
		! wp_verify_nonce( wp_unslash( $_POST['lcp_yeast_seo_meta_box_nonce'] ), 'lcp_yeast_seo_save_meta_box' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		LCP_YEAST_SEO_TITLE_META_KEY          => 'sanitize_text_field',
		LCP_YEAST_SEO_DESCRIPTION_META_KEY    => 'sanitize_textarea_field',
		LCP_YEAST_SEO_OG_TITLE_META_KEY       => 'sanitize_text_field',
		LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY => 'sanitize_textarea_field',
		LCP_YEAST_SEO_OG_IMAGE_META_KEY       => 'esc_url_raw',
		LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY  => 'esc_url_raw',
		LCP_YEAST_SEO_ROBOTS_META_KEY         => 'lcp_yeast_seo_sanitize_robots_meta',
		LCP_YEAST_SEO_SCHEMA_META_KEY         => 'lcp_yeast_seo_sanitize_schema_meta',
	);

	foreach ( $fields as $meta_key => $sanitize_callback ) {
		if ( ! isset( $_POST[ $meta_key ] ) ) {
			continue;
		}

		$value = call_user_func( $sanitize_callback, wp_unslash( $_POST[ $meta_key ] ) );

		if ( LCP_YEAST_SEO_ROBOTS_META_KEY === $meta_key ) {
			update_post_meta( $post_id, $meta_key, $value );
			continue;
		}

		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}
}
add_action( 'save_post', 'lcp_yeast_seo_save_meta_box' );

/**
 * Loads the block-editor modal assets.
 *
 * @return void
 */
function lcp_yeast_seo_enqueue_block_editor_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || empty( $screen->post_type ) || ! lcp_yeast_seo_uses_block_editor( $screen->post_type ) ) {
		return;
	}

	$script_path = LCP_YEAST_SEO_PATH . 'assets/editor.js';
	$style_path  = LCP_YEAST_SEO_PATH . 'assets/editor.css';

	wp_enqueue_media();

	wp_enqueue_script(
		'lcp-yeast-seo-editor',
		LCP_YEAST_SEO_URL . 'assets/editor.js',
		array( 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ),
		file_exists( $script_path ) ? filemtime( $script_path ) : LCP_YEAST_SEO_VERSION,
		true
	);

	wp_enqueue_style(
		'lcp-yeast-seo-editor',
		LCP_YEAST_SEO_URL . 'assets/editor.css',
		array( 'wp-components' ),
		file_exists( $style_path ) ? filemtime( $style_path ) : LCP_YEAST_SEO_VERSION
	);

	wp_add_inline_script(
		'lcp-yeast-seo-editor',
		'window.lcpYeastSeoEditor = ' . wp_json_encode(
			array(
				'schemaEnabled' => lcp_yeast_seo_schema_enabled(),
				'blogPublic'    => (bool) get_option( 'blog_public' ),
				'homeUrl'       => home_url( '/' ),
				'defaultSocialImage' => lcp_yeast_seo_get_setting( 'default_social_image', '' ),
				'twitterSite'   => lcp_yeast_seo_get_setting( 'twitter_site', '' ),
				'metaKeys'      => array(
					'title'            => LCP_YEAST_SEO_TITLE_META_KEY,
					'description'      => LCP_YEAST_SEO_DESCRIPTION_META_KEY,
					'ogTitle'          => LCP_YEAST_SEO_OG_TITLE_META_KEY,
					'ogDescription'    => LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY,
					'ogImage'          => LCP_YEAST_SEO_OG_IMAGE_META_KEY,
					'twitterImage'     => LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY,
					'robotsIndex'      => LCP_YEAST_SEO_ROBOTS_META_KEY,
					'schema'           => LCP_YEAST_SEO_SCHEMA_META_KEY,
				),
			)
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'lcp_yeast_seo_enqueue_block_editor_assets' );

/**
 * Whether the current request maps to a specific post this plugin can pull
 * per-post SEO fields from — either a singular view, or the static page set
 * as the site's "Posts page" (Settings → Reading) being shown as the blog
 * index. is_singular() alone is false for the latter even though it's a
 * real page with its own Yeast SEO fields, which otherwise left that
 * page's title/description/OG/robots settings entirely unused whenever
 * it's viewed in its posts-page role rather than opened directly.
 *
 * @return bool
 */
function lcp_yeast_seo_is_managed_view() {
	return is_singular() || ( is_home() && (int) get_option( 'page_for_posts' ) );
}

/**
 * Returns the post ID this plugin's per-post fields apply to for the
 * current request, per lcp_yeast_seo_is_managed_view().
 *
 * @return int
 */
function lcp_yeast_seo_current_post_id() {
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}

	if ( is_home() ) {
		return (int) get_option( 'page_for_posts' );
	}

	return 0;
}

/**
 * Returns one post-meta value for the current singular post.
 *
 * @param string $meta_key Meta key.
 * @return string
 */
function lcp_yeast_seo_current_meta( $meta_key ) {
	$post_id = lcp_yeast_seo_current_post_id();
	return $post_id ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
}

/**
 * Returns the current singular page's custom title.
 *
 * @return string
 */
function lcp_yeast_seo_current_title() {
	return lcp_yeast_seo_current_meta( LCP_YEAST_SEO_TITLE_META_KEY );
}

/**
 * Returns the effective meta description.
 *
 * @return string
 */
function lcp_yeast_seo_current_description() {
	return trim( lcp_yeast_seo_current_meta( LCP_YEAST_SEO_DESCRIPTION_META_KEY ) );
}

/**
 * Returns the effective Open Graph title.
 *
 * @return string
 */
function lcp_yeast_seo_current_og_title() {
	$title = trim( lcp_yeast_seo_current_meta( LCP_YEAST_SEO_OG_TITLE_META_KEY ) );

	if ( '' !== $title ) {
		return $title;
	}

	$title = trim( lcp_yeast_seo_current_title() );

	if ( '' !== $title ) {
		return $title;
	}

	$post_id = lcp_yeast_seo_current_post_id();

	return $post_id ? get_the_title( $post_id ) : '';
}

/**
 * Returns the effective Open Graph description.
 *
 * @return string
 */
function lcp_yeast_seo_current_og_description() {
	$description = trim( lcp_yeast_seo_current_meta( LCP_YEAST_SEO_OG_DESCRIPTION_META_KEY ) );

	if ( '' !== $description ) {
		return $description;
	}

	return lcp_yeast_seo_current_description();
}

/**
 * Returns the effective Open Graph image.
 *
 * @return string
 */
function lcp_yeast_seo_current_og_image() {
	$image = trim( lcp_yeast_seo_current_meta( LCP_YEAST_SEO_OG_IMAGE_META_KEY ) );

	if ( '' !== $image ) {
		return $image;
	}

	return (string) lcp_yeast_seo_get_setting( 'default_social_image', '' );
}

/**
 * Returns the effective Twitter image.
 *
 * @return string
 */
function lcp_yeast_seo_current_twitter_image() {
	$image = trim( lcp_yeast_seo_current_meta( LCP_YEAST_SEO_TWITTER_IMAGE_META_KEY ) );

	if ( '' !== $image ) {
		return $image;
	}

	return lcp_yeast_seo_current_og_image();
}

/**
 * Returns whether the current page should be indexed.
 *
 * @return bool
 */
function lcp_yeast_seo_should_index_current_page() {
	if ( ! get_option( 'blog_public' ) ) {
		return false;
	}

	if ( ! lcp_yeast_seo_is_managed_view() ) {
		return true;
	}

	return 'noindex' !== lcp_yeast_seo_current_meta( LCP_YEAST_SEO_ROBOTS_META_KEY );
}

/**
 * Whether this request's robots directives should be overridden.
 *
 * @return bool
 */
function lcp_yeast_seo_should_override_robots() {
	return ! get_option( 'blog_public' ) || lcp_yeast_seo_is_managed_view();
}

/**
 * Overrides the document title when a custom one is set.
 *
 * @param string $title Existing title.
 * @return string
 */
function lcp_yeast_seo_filter_document_title( $title ) {
	$custom_title = trim( lcp_yeast_seo_current_title() );
	return '' !== $custom_title ? $custom_title : $title;
}
add_filter( 'pre_get_document_title', 'lcp_yeast_seo_filter_document_title' );
add_filter( 'wpseo_title', 'lcp_yeast_seo_filter_document_title' );

/**
 * Overrides the meta description Yoast outputs when present.
 *
 * @param string $description Existing description.
 * @return string
 */
function lcp_yeast_seo_filter_meta_description( $description ) {
	$custom_description = trim( lcp_yeast_seo_current_description() );
	return '' !== $custom_description ? $custom_description : $description;
}
add_filter( 'wpseo_metadesc', 'lcp_yeast_seo_filter_meta_description' );

/**
 * Filters WordPress core robots directives.
 *
 * @param array $robots Existing directives.
 * @return array
 */
function lcp_yeast_seo_filter_wp_robots( $robots ) {
	if ( ! lcp_yeast_seo_should_override_robots() ) {
		return $robots;
	}

	if ( lcp_yeast_seo_should_index_current_page() ) {
		unset( $robots['noindex'] );
		$robots['index'] = true;
	} else {
		unset( $robots['index'] );
		$robots['noindex'] = true;
	}

	return $robots;
}
add_filter( 'wp_robots', 'lcp_yeast_seo_filter_wp_robots' );

/**
 * Filters Yoast robots directives when Yoast is active.
 *
 * @param array $robots Existing directives.
 * @return array
 */
function lcp_yeast_seo_filter_wpseo_robots_array( $robots ) {
	if ( ! is_array( $robots ) ) {
		$robots = array();
	}

	if ( ! lcp_yeast_seo_should_override_robots() ) {
		return $robots;
	}

	if ( lcp_yeast_seo_should_index_current_page() ) {
		unset( $robots['noindex'] );
		$robots['index'] = 'index';
	} else {
		unset( $robots['index'] );
		$robots['noindex'] = 'noindex';
	}

	if ( ! isset( $robots['follow'] ) && ! isset( $robots['nofollow'] ) ) {
		$robots['follow'] = 'follow';
	}

	return $robots;
}
add_filter( 'wpseo_robots_array', 'lcp_yeast_seo_filter_wpseo_robots_array' );

/**
 * Filters Yoast Open Graph and Twitter values when present.
 *
 * @param string $value Existing value.
 * @param string $type  Value type.
 * @return string
 */
function lcp_yeast_seo_filter_social_value( $value, $type ) {
	$map = array(
		'og_title'       => lcp_yeast_seo_current_og_title(),
		'og_description' => lcp_yeast_seo_current_og_description(),
		'og_image'       => lcp_yeast_seo_current_og_image(),
		'twitter_title'  => lcp_yeast_seo_current_og_title(),
		'twitter_desc'   => lcp_yeast_seo_current_og_description(),
		'twitter_image'  => lcp_yeast_seo_current_twitter_image(),
	);

	return ! empty( $map[ $type ] ) ? $map[ $type ] : $value;
}

add_filter(
	'wpseo_opengraph_title',
	function ( $value ) {
		return lcp_yeast_seo_filter_social_value( $value, 'og_title' );
	}
);

add_filter(
	'wpseo_opengraph_desc',
	function ( $value ) {
		return lcp_yeast_seo_filter_social_value( $value, 'og_description' );
	}
);

add_filter(
	'wpseo_opengraph_image',
	function ( $value ) {
		return lcp_yeast_seo_filter_social_value( $value, 'og_image' );
	}
);

add_filter(
	'wpseo_twitter_title',
	function ( $value ) {
		return lcp_yeast_seo_filter_social_value( $value, 'twitter_title' );
	}
);

add_filter(
	'wpseo_twitter_description',
	function ( $value ) {
		return lcp_yeast_seo_filter_social_value( $value, 'twitter_desc' );
	}
);

add_filter(
	'wpseo_twitter_image',
	function ( $value ) {
		return lcp_yeast_seo_filter_social_value( $value, 'twitter_image' );
	}
);

/**
 * Outputs the plugin's meta tags when Yoast is not handling them.
 *
 * @return void
 */
function lcp_yeast_seo_render_frontend_meta() {
	if ( ! lcp_yeast_seo_is_managed_view() || defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) ) {
		return;
	}

	$post_id      = lcp_yeast_seo_current_post_id();
	$title        = lcp_yeast_seo_current_og_title();
	$description  = lcp_yeast_seo_current_og_description();
	$og_image     = lcp_yeast_seo_current_og_image();
	$twitter_image = lcp_yeast_seo_current_twitter_image();
	$url          = get_permalink( $post_id );
	$site_name    = get_bloginfo( 'name' );
	$twitter_site = lcp_yeast_seo_get_setting( 'twitter_site', '' );
	$card_type    = $twitter_image ? 'summary_large_image' : 'summary';
	// The posts page shown as the blog index is a listing, not a single
	// piece of content — "article" would misdescribe it to OG consumers.
	$og_type      = is_singular() ? 'article' : 'website';

	if ( '' !== $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";

	if ( '' !== $title ) {
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	}

	if ( '' !== $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	if ( '' !== $site_name ) {
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
	}

	if ( '' !== $og_image ) {
		echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
	}

	if ( '' !== $twitter_image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $twitter_image ) . '">' . "\n";
	}

	echo '<meta name="twitter:card" content="' . esc_attr( $card_type ) . '">' . "\n";

	if ( '' !== $twitter_site ) {
		echo '<meta name="twitter:site" content="' . esc_attr( $twitter_site ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'lcp_yeast_seo_render_frontend_meta', 1 );

/**
 * Outputs a canonical link tag for non-singular views.
 *
 * WordPress core's own rel_canonical() (wp-includes/link-template.php) only
 * fires for is_singular() — the posts page, taxonomy/post-type archives,
 * and author archives get no canonical at all out of core, which is what
 * left a page like the posts page (e.g. a "Guides" index) without one.
 * Skipped entirely when Yoast is active/handling it (same detection this
 * file already uses for render_frontend_meta) or on singular content, since
 * core's own rel_canonical() already covers both cases there.
 *
 * @return void
 */
function lcp_yeast_seo_render_canonical() {
	if ( is_singular() || defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) ) {
		return;
	}

	if ( is_404() || is_search() ) {
		return; // Nothing stable to canonicalise to.
	}

	$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ), 1 );
	$url   = get_pagenum_link( $paged, false );

	if ( ! $url ) {
		return;
	}

	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
}
add_action( 'wp_head', 'lcp_yeast_seo_render_canonical', 1 );
