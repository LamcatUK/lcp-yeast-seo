<?php
/**
 * SEO for taxonomy, author, date, search and post-type archives.
 *
 * Only archives the active theme actually uses are offered, so a site isn't
 * shown fields for tags it never lists or author pages it never links to. What
 * counts as "in use" is worked out from the theme's own files and menus; see
 * lcp_yeast_seo_archive_kinds().
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_SIGNALS_TRANSIENT', 'lcp_yeast_seo_theme_signals' );

/**
 * Signals a theme's template file names give away.
 *
 * @param string[] $files Template file names (base names), .php or .html.
 * @return string[] Signals: tax:{name}, pt:{name}, author, date, search, generic.
 */
function lcp_yeast_seo_signals_from_templates( array $files ) {
	$signals = array();

	foreach ( $files as $file ) {
		$name = strtolower( (string) preg_replace( '/\.(php|html)$/i', '', basename( (string) $file ) ) );

		if ( preg_match( '/^category(-.+)?$/', $name ) ) {
			$signals[] = 'tax:category';
		} elseif ( preg_match( '/^tag(-.+)?$/', $name ) ) {
			$signals[] = 'tax:post_tag';
		} elseif ( preg_match( '/^taxonomy-(.+)$/', $name, $m ) ) {
			// taxonomy-{taxonomy}.php or taxonomy-{taxonomy}-{term}.php: the first part may itself contain underscores.
			$signals[] = 'tax:' . $m[1];
		} elseif ( in_array( $name, array( 'taxonomy', 'archive' ), true ) ) {
			$signals[] = 'generic';
		} elseif ( preg_match( '/^archive-(.+)$/', $name, $m ) ) {
			$signals[] = 'pt:' . $m[1];
		} elseif ( preg_match( '/^author(-.+)?$/', $name ) ) {
			$signals[] = 'author';
		} elseif ( in_array( $name, array( 'date', 'year', 'month', 'day' ), true ) ) {
			$signals[] = 'date';
		} elseif ( in_array( $name, array( 'search', 'searchform' ), true ) ) {
			$signals[] = 'search';
		}
	}

	return array_values( array_unique( $signals ) );
}

/**
 * Signals a template's source gives away: links to archives, and archive blocks.
 *
 * @param string   $source     File contents.
 * @param string[] $taxonomies Public taxonomy names, so custom ones can be recognised.
 * @return string[] Signals, as for lcp_yeast_seo_signals_from_templates().
 */
function lcp_yeast_seo_signals_from_source( $source, array $taxonomies = array() ) {
	$source  = (string) $source;
	$signals = array();
	$has     = static function ( $pattern ) use ( $source ) {
		return (bool) preg_match( $pattern, $source );
	};

	if ( $has( '/\b(?:the_category|get_the_category|get_the_category_list|wp_list_categories|get_category_link|single_cat_title)\s*\(|wp:categories\b|"term"\s*:\s*"category"/' ) ) {
		$signals[] = 'tax:category';
	}

	if ( $has( '/\b(?:the_tags|get_the_tags|get_the_tag_list|wp_tag_cloud|get_tag_link|single_tag_title)\s*\(|wp:tag-cloud\b|"term"\s*:\s*"post_tag"/' ) ) {
		$signals[] = 'tax:post_tag';
	}

	$links_terms = $has( '/\b(?:get_the_term_list|the_terms|get_term_link|single_term_title)\s*\(/' );

	foreach ( $taxonomies as $taxonomy ) {
		if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			continue;
		}

		$quoted = preg_quote( $taxonomy, '/' );

		if ( ( $links_terms && $has( '/[\'"]' . $quoted . '[\'"]/' ) ) || $has( '/"term"\s*:\s*"' . $quoted . '"/' ) ) {
			$signals[] = 'tax:' . $taxonomy;
		}
	}

	if ( $has( '/\b(?:the_author_posts_link|get_author_posts_url|get_the_author_posts_link|wp_list_authors)\s*\(|wp:post-author[^>]*"isLink"\s*:\s*true/' ) ) {
		$signals[] = 'author';
	}

	if ( $has( '/\b(?:wp_get_archives|get_month_link|get_year_link|get_day_link)\s*\(|wp:archives\b/' ) ) {
		$signals[] = 'date';
	}

	if ( $has( '/\b(?:get_search_form|get_search_query|the_search_query)\s*\(|\bis_search\s*\(|wp:search\b/' ) ) {
		$signals[] = 'search';
	}

	if ( preg_match_all( '/get_post_type_archive_link\s*\(\s*[\'"]([A-Za-z0-9_-]+)[\'"]/', $source, $matches ) ) {
		foreach ( $matches[1] as $post_type ) {
			$signals[] = 'pt:' . $post_type;
		}
	}

	return array_values( array_unique( $signals ) );
}

/**
 * Why an archive counts as in use, or '' when nothing says it is.
 *
 * @param string   $id            tax:{name}, pt:{name}, author, date or search.
 * @param string[] $theme_signals What the theme's files give away.
 * @param string[] $menu_signals  Archives linked from navigation menus (same ids).
 * @param bool     $woo           Whether the theme declares WooCommerce support.
 * @return string Reason shown in the admin, or ''.
 */
function lcp_yeast_seo_archive_reason( $id, array $theme_signals, array $menu_signals, $woo = false ) {
	if ( in_array( $id, $theme_signals, true ) ) {
		return 'The theme links to it or has a template for it.';
	}

	// A template for one term (taxonomy-{taxonomy}-{term}.php) still means the taxonomy is in use.
	foreach ( $theme_signals as $signal ) {
		if ( 0 === strpos( $signal, $id . '-' ) ) {
			return 'The theme links to it or has a template for it.';
		}
	}

	if ( in_array( $id, $menu_signals, true ) ) {
		return 'It is in a menu.';
	}

	if ( 0 === strpos( $id, 'tax:' ) && $woo && preg_match( '/^tax:(product_cat|product_tag|pa_.+)$/', $id ) ) {
		return 'The theme supports WooCommerce, which lists products by these.';
	}

	// A general archive template renders every archive that does not have its own;
	// search results are not archives, so it says nothing about them.
	if ( 'search' !== $id && in_array( 'generic', $theme_signals, true ) ) {
		return 'The theme has a general archive template.';
	}

	return '';
}

/**
 * Scans the active theme (and its parent) for what it does with archives.
 *
 * @param string[] $taxonomies Public taxonomy names.
 * @return array{signals:string[],files:int}
 */
function lcp_yeast_seo_scan_theme( array $taxonomies ) {
	$dirs = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory() ) ) );
	$skip = array( 'node_modules', 'vendor', '.git', 'tests' );
	$seen = array();
	$read = 0;
	$all  = array();

	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) use ( $skip ) {
					return ! ( $current->isDir() && in_array( $current->getFilename(), $skip, true ) );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $read >= 3000 || ! $file->isFile() || ! preg_match( '/\.(php|html)$/i', $file->getFilename() ) ) {
				continue;
			}

			++$read;
			$seen[] = $file->getFilename();

			if ( $file->getSize() > 400 * 1024 ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$all = array_merge( $all, lcp_yeast_seo_signals_from_source( (string) file_get_contents( $file->getPathname() ), $taxonomies ) );
		}
	}

	return array(
		'signals' => array_values( array_unique( array_merge( lcp_yeast_seo_signals_from_templates( $seen ), $all ) ) ),
		'files'   => $read,
	);
}

/**
 * The theme's archive signals, scanned once and then remembered.
 *
 * @param bool $force Scan again.
 * @return array{signals:string[],files:int,theme:string,time:int}
 */
function lcp_yeast_seo_theme_signals( $force = false ) {
	$stored = get_transient( LCP_YEAST_SEO_SIGNALS_TRANSIENT );
	$theme  = get_stylesheet() . '|' . wp_get_theme()->get( 'Version' );

	if ( ! $force && is_array( $stored ) && ( $stored['theme'] ?? '' ) === $theme ) {
		return $stored;
	}

	$taxonomies = array_keys( get_taxonomies( array( 'public' => true ) ) );
	$result     = lcp_yeast_seo_scan_theme( $taxonomies ) + array(
		'theme' => $theme,
		'time'  => time(),
	);

	set_transient( LCP_YEAST_SEO_SIGNALS_TRANSIENT, $result, DAY_IN_SECONDS );

	return $result;
}

/**
 * Forgets the scan when the theme changes, so the next look is fresh.
 *
 * @return void
 */
function lcp_yeast_seo_forget_theme_signals() {
	delete_transient( LCP_YEAST_SEO_SIGNALS_TRANSIENT );
}
add_action( 'after_switch_theme', 'lcp_yeast_seo_forget_theme_signals' );
add_action( 'upgrader_process_complete', 'lcp_yeast_seo_forget_theme_signals' );

/**
 * Archives linked from navigation menus.
 *
 * @return string[] Archive ids: tax:{name} or pt:{name}.
 */
function lcp_yeast_seo_menu_signals() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();

	foreach ( (array) wp_get_nav_menus() as $menu ) {
		foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
			if ( 'taxonomy' === $item->type ) {
				$cache[] = 'tax:' . $item->object;
			} elseif ( 'post_type_archive' === $item->type ) {
				$cache[] = 'pt:' . $item->object;
			}
		}
	}

	$cache = array_values( array_unique( $cache ) );

	return $cache;
}

/**
 * Every archive this site could have SEO settings for, and whether the theme uses it.
 *
 * Each entry: id (tax:{name}, pt:{name}, author, date, search), type, name,
 * label, count (terms or authors, 0 when not meaningful), in_use, why, and
 * recommend_noindex with recommend_why.
 *
 * @return array[]
 */
function lcp_yeast_seo_archive_kinds() {
	$signals = lcp_yeast_seo_theme_signals()['signals'];
	$menu    = lcp_yeast_seo_menu_signals();
	$woo     = current_theme_supports( 'woocommerce' );
	$kinds   = array();
	$add     = static function ( array $kind ) use ( $signals, $menu, $woo ) {
		$kind['why']    = lcp_yeast_seo_archive_reason( $kind['id'], $signals, $menu, $woo );
		$kind['in_use'] = (bool) apply_filters( 'lcp_yeast_seo_archive_in_use', '' !== $kind['why'], $kind['id'], $kind );

		return $kind;
	};

	foreach ( get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ), 'objects' ) as $taxonomy ) {
		if ( 'post_format' === $taxonomy->name ) {
			continue;
		}

		$terms = wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) || ! (int) $terms ) {
			continue;
		}

		$kinds[] = $add(
			array(
				'id'                => 'tax:' . $taxonomy->name,
				'type'              => 'taxonomy',
				'name'              => $taxonomy->name,
				'label'             => $taxonomy->labels->name,
				'singular'          => $taxonomy->labels->singular_name,
				'count'             => (int) $terms,
				'recommend_noindex' => false,
				'recommend_why'     => '',
			)
		);
	}

	$authors = get_users( array( 'has_published_posts' => true, 'number' => 2, 'fields' => 'ID' ) );

	if ( $authors ) {
		$single  = 1 === count( $authors );
		$kinds[] = $add(
			array(
				'id'                => 'author',
				'type'              => 'author',
				'name'              => 'author',
				'label'             => 'Author archives',
				'count'             => count( $authors ),
				'recommend_noindex' => $single,
				'recommend_why'     => $single ? 'With one author this page repeats your blog.' : '',
			)
		);
	}

	$kinds[] = $add(
		array(
			'id'                => 'date',
			'type'              => 'date',
			'name'              => 'date',
			'label'             => 'Date archives',
			'count'             => 0,
			'recommend_noindex' => true,
			'recommend_why'     => 'Date pages repeat content that already has its own page.',
		)
	);

	$kinds[] = $add(
		array(
			'id'                => 'search',
			'type'              => 'search',
			'name'              => 'search',
			'label'             => 'Search results',
			'count'             => 0,
			'recommend_noindex' => true,
			'recommend_why'     => 'Search results are endless and thin; search engines advise against indexing them.',
		)
	);

	foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $post_type ) {
		$kinds[] = $add(
			array(
				'id'                => 'pt:' . $post_type->name,
				'type'              => 'post_type',
				'name'              => $post_type->name,
				'label'             => $post_type->labels->name . ' archive',
				'count'             => 0,
				'recommend_noindex' => false,
				'recommend_why'     => '',
			)
		);
	}

	return $kinds;
}

/**
 * The archive setting for an archive id: noindex, title or description.
 *
 * @param string $id      tax:{name}, pt:{name}, author, date or search.
 * @param string $field   noindex, title or description.
 * @param string $default Value when unset.
 * @return string
 */
function lcp_yeast_seo_archive_setting( $id, $field, $default = '' ) {
	$all = lcp_yeast_seo_get_setting( 'archives', array() );

	return is_array( $all ) && isset( $all[ $id ][ $field ] ) ? (string) $all[ $id ][ $field ] : $default;
}

/**
 * Whether the active theme uses an archive, as far as can be told.
 *
 * @param string $id Archive id.
 * @return bool
 */
function lcp_yeast_seo_archive_is_in_use( $id ) {
	$in_use = '' !== lcp_yeast_seo_archive_reason( $id, lcp_yeast_seo_theme_signals()['signals'], lcp_yeast_seo_menu_signals(), current_theme_supports( 'woocommerce' ) );

	return (bool) apply_filters( 'lcp_yeast_seo_archive_in_use', $in_use, $id, array( 'id' => $id ) );
}

/**
 * The name of the date archive being viewed ("March 2026", "2026" or a full date).
 *
 * @return string
 */
function lcp_yeast_seo_date_archive_name() {
	$year  = (int) get_query_var( 'year' );
	$month = (int) get_query_var( 'monthnum' );
	$day   = (int) get_query_var( 'day' );

	if ( $year && $month && $day ) {
		return wp_date( get_option( 'date_format' ), mktime( 12, 0, 0, $month, $day, $year ) );
	}

	if ( $year && $month ) {
		return wp_date( 'F Y', mktime( 12, 0, 0, $month, 1, $year ) );
	}

	return $year ? (string) $year : '';
}

/**
 * The archive view being shown, if it is one this plugin covers.
 *
 * @return array|null id, type (term, author, date, search or post_type), object_id, name and description.
 */
function lcp_yeast_seo_current_archive() {
	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		return $term instanceof WP_Term ? array(
			'id'          => 'tax:' . $term->taxonomy,
			'type'        => 'term',
			'object_id'   => (int) $term->term_id,
			'name'        => $term->name,
			'description' => (string) $term->description,
		) : null;
	}

	if ( is_author() ) {
		$user_id = (int) get_queried_object_id();

		return array(
			'id'          => 'author',
			'type'        => 'author',
			'object_id'   => $user_id,
			'name'        => (string) get_the_author_meta( 'display_name', $user_id ),
			'description' => (string) get_the_author_meta( 'description', $user_id ),
		);
	}

	if ( is_date() ) {
		return array(
			'id'          => 'date',
			'type'        => 'date',
			'object_id'   => 0,
			'name'        => lcp_yeast_seo_date_archive_name(),
			'description' => '',
		);
	}

	if ( is_search() ) {
		return array(
			'id'          => 'search',
			'type'        => 'search',
			'object_id'   => 0,
			'name'        => get_search_query(),
			'description' => '',
		);
	}

	if ( is_post_type_archive() ) {
		$post_type = get_queried_object();

		return $post_type instanceof WP_Post_Type ? array(
			'id'          => 'pt:' . $post_type->name,
			'type'        => 'post_type',
			'object_id'   => 0,
			'name'        => $post_type->labels->name,
			'description' => (string) $post_type->description,
		) : null;
	}

	return null;
}

/**
 * A stored SEO value for a term or an author.
 *
 * @param array  $archive  From lcp_yeast_seo_current_archive().
 * @param string $meta_key Meta key.
 * @return string
 */
function lcp_yeast_seo_archive_meta( array $archive, $meta_key ) {
	if ( 'term' === $archive['type'] ) {
		return (string) get_term_meta( $archive['object_id'], $meta_key, true );
	}

	if ( 'author' === $archive['type'] ) {
		return (string) get_user_meta( $archive['object_id'], $meta_key, true );
	}

	return '';
}

/**
 * Whether an archive should be indexed: its own choice first, then the archive
 * setting, then the "archives the theme does not use" setting.
 *
 * @param string $own            The term's or author's own choice: index, noindex or ''.
 * @param bool   $kind_noindex   The archive's noindex setting.
 * @param bool   $unused_noindex Whether unused archives are noindexed.
 * @param bool   $in_use         Whether the theme uses this archive.
 * @return string noindex, index, or '' for no opinion.
 */
function lcp_yeast_seo_decide_archive_robots( $own, $kind_noindex, $unused_noindex, $in_use ) {
	if ( in_array( $own, array( 'noindex', 'index' ), true ) ) {
		return $own;
	}

	if ( $kind_noindex ) {
		return 'noindex';
	}

	if ( $unused_noindex && ! $in_use ) {
		return 'noindex';
	}

	return '';
}

/**
 * The robots decision for the archive being viewed.
 *
 * @return string noindex, index, or '' when this plugin has no opinion.
 */
function lcp_yeast_seo_archive_robots() {
	$archive = lcp_yeast_seo_current_archive();

	if ( ! $archive ) {
		return '';
	}

	$unused = lcp_yeast_seo_flag_setting( 'noindex_unused_archives', false );

	return lcp_yeast_seo_decide_archive_robots(
		lcp_yeast_seo_archive_meta( $archive, LCP_YEAST_SEO_ROBOTS_META_KEY ),
		'1' === lcp_yeast_seo_archive_setting( $archive['id'], 'noindex', '0' ),
		$unused,
		$unused ? lcp_yeast_seo_archive_is_in_use( $archive['id'] ) : true
	);
}

/**
 * Fills an archive title template and adds the page number on later pages,
 * which WordPress would otherwise have added itself.
 *
 * @param string   $template Template, e.g. "%term% Archives %sep% %sitename%".
 * @param string[] $vars     Tag => value, without the percent signs.
 * @param int      $paged    Page number.
 * @return string
 */
function lcp_yeast_seo_archive_title_text( $template, array $vars, $paged ) {
	$title = lcp_yeast_seo_render_title_template( $template, $vars );

	if ( $paged > 1 && '' !== $title ) {
		$title .= ' ' . trim( ( $vars['sep'] ?? '' ) . ' Page ' . (int) $paged );
	}

	return $title;
}

/**
 * The templated title for the archive being viewed, or '' to keep WordPress's.
 *
 * @param string $title Title so far.
 * @return string
 */
function lcp_yeast_seo_filter_archive_title( $title ) {
	if ( '' !== $title || lcp_yeast_seo_yoast_active() ) {
		return $title;
	}

	$archive = lcp_yeast_seo_current_archive();

	if ( ! $archive ) {
		return $title;
	}

	$template = lcp_yeast_seo_archive_setting( $archive['id'], 'title' );

	if ( '' === $template ) {
		return $title;
	}

	$name = wp_strip_all_tags( $archive['name'] );

	return lcp_yeast_seo_archive_title_text(
		$template,
		array(
			'term'     => $name,
			'author'   => $name,
			'date'     => $name,
			'search'   => $name,
			'archive'  => $name,
			'sitename' => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'tagline'  => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'sep'      => lcp_yeast_seo_title_sep(),
		),
		max( (int) get_query_var( 'paged' ), 1 )
	);
}
add_filter( 'pre_get_document_title', 'lcp_yeast_seo_filter_archive_title', 11 );

/**
 * The meta description for the archive being viewed: its own, the archive's
 * setting, or (when automatic descriptions are on) the term's description or
 * the author's biography.
 *
 * @return string
 */
function lcp_yeast_seo_archive_description() {
	$archive = lcp_yeast_seo_current_archive();

	if ( ! $archive ) {
		return '';
	}

	foreach ( array( lcp_yeast_seo_archive_meta( $archive, LCP_YEAST_SEO_DESCRIPTION_META_KEY ), lcp_yeast_seo_archive_setting( $archive['id'], 'description' ) ) as $own ) {
		if ( '' !== trim( $own ) ) {
			return trim( $own );
		}
	}

	return lcp_yeast_seo_auto_description_enabled() ? lcp_yeast_seo_description_from_text( $archive['description'], '' ) : '';
}

/**
 * Prints the description and social tags for an archive view.
 *
 * Singular content and the posts page are handled by lcp_yeast_seo_render_frontend_meta().
 *
 * @return void
 */
function lcp_yeast_seo_render_archive_meta() {
	if ( lcp_yeast_seo_yoast_active() || lcp_yeast_seo_is_managed_view() || ! lcp_yeast_seo_current_archive() || is_search() ) {
		return;
	}

	$description = lcp_yeast_seo_effective_description();
	$title       = lcp_yeast_seo_current_og_title();
	$title       = '' !== $title ? $title : wp_get_document_title();
	$og_desc     = lcp_yeast_seo_current_og_description();
	$image       = lcp_yeast_seo_current_og_image();
	$twitter_img = lcp_yeast_seo_current_twitter_image();
	$site_name   = get_bloginfo( 'name' );
	$twitter     = lcp_yeast_seo_get_setting( 'twitter_site', '' );

	if ( '' !== $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( lcp_yeast_seo_current_archive_url() ) . '">' . "\n";

	if ( '' !== $title ) {
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	}

	if ( '' !== $og_desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
	}

	if ( '' !== $site_name ) {
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
	}

	if ( '' !== $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	}

	if ( '' !== $twitter_img ) {
		echo '<meta name="twitter:image" content="' . esc_url( $twitter_img ) . '">' . "\n";
	}

	echo '<meta name="twitter:card" content="' . esc_attr( '' !== $twitter_img ? 'summary_large_image' : 'summary' ) . '">' . "\n";

	if ( '' !== $twitter ) {
		echo '<meta name="twitter:site" content="' . esc_attr( $twitter ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'lcp_yeast_seo_render_archive_meta', 1 );

/**
 * Cleans the settings' per-archive values, keeping the ones for archives the form did not list.
 *
 * @param mixed $input Submitted archives => id => noindex, title, description.
 * @return array
 */
function lcp_yeast_seo_sanitize_archives( $input ) {
	$existing = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	$out      = is_array( $existing ) && isset( $existing['archives'] ) && is_array( $existing['archives'] ) ? $existing['archives'] : array();

	foreach ( is_array( $input ) ? $input : array() as $id => $row ) {
		if ( ! is_array( $row ) || ! preg_match( '/^(?:tax:[a-z0-9_-]+|pt:[a-z0-9_-]+|author|date|search)$/', (string) $id ) ) {
			continue;
		}

		$entry = array(
			'noindex'     => empty( $row['noindex'] ) ? '0' : '1',
			'title'       => trim( sanitize_text_field( wp_unslash( $row['title'] ?? '' ) ) ),
			'description' => trim( sanitize_textarea_field( wp_unslash( $row['description'] ?? '' ) ) ),
		);

		if ( '0' === $entry['noindex'] && '' === $entry['title'] && '' === $entry['description'] ) {
			unset( $out[ $id ] );
			continue;
		}

		$out[ $id ] = $entry;
	}

	return $out;
}

/**
 * Cleans a term's or author's own robots choice.
 *
 * @param mixed $value Submitted value.
 * @return string index, noindex, or '' for the archive default.
 */
function lcp_yeast_seo_sanitize_archive_robots( $value ) {
	return in_array( $value, array( 'index', 'noindex' ), true ) ? $value : '';
}
