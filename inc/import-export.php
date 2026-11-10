<?php
/**
 * Import / Export tab: bulk titles and descriptions by URL, plus migration
 * from other SEO plugins.
 *
 * Ported from the standalone cbp-update-metadata plugin. The CSV format is the
 * same (URL, Meta Title, Meta Description), so files move between the two.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_IE_NONCE', 'lcp_yeast_seo_import_export' );
define( 'LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN', 500 );

/**
 * SEO plugins whose titles and descriptions can be exported or migrated.
 *
 * @return array[] Slug => label, title meta key, description meta key.
 */
function lcp_yeast_seo_ie_engines() {
	return array(
		'yeast'    => array(
			'label'       => 'Yeast SEO (this plugin)',
			'title'       => LCP_YEAST_SEO_TITLE_META_KEY,
			'description' => LCP_YEAST_SEO_DESCRIPTION_META_KEY,
			'robots'      => LCP_YEAST_SEO_ROBOTS_META_KEY,
			'canonical'   => LCP_YEAST_SEO_CANONICAL_META_KEY,
		),
		'yoast'    => array(
			'label'       => 'Yoast SEO',
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'robots'      => '_yoast_wpseo_meta-robots-noindex',
			'canonical'   => '_yoast_wpseo_canonical',
			'prefix'      => '_yoast_wpseo_',
		),
		'rankmath' => array(
			'label'       => 'Rank Math',
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'robots'      => 'rank_math_robots',
			'canonical'   => 'rank_math_canonical_url',
			'prefix'      => 'rank_math_',
		),
		'seopress' => array(
			'label'       => 'SEOPress',
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
			'robots'      => '_seopress_robots_index',
			'canonical'   => '_seopress_robots_canonical',
			'prefix'      => '_seopress_',
		),
	);
}

/**
 * The engine to pre-select for export: whichever other SEO plugin is active.
 *
 * @return string
 */
function lcp_yeast_seo_ie_default_engine() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}

	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'seopress';
	}

	if ( class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}

	return 'yeast';
}

/**
 * Replaces SEO-plugin template variables with real values. Yoast writes them
 * as %%title%%, Rank Math as %title%; any variable not in $vars is left alone.
 *
 * @param string   $text Text containing variables.
 * @param string[] $vars Variable name => value.
 * @return string
 */
function lcp_yeast_seo_ie_resolve_variables( $text, array $vars ) {
	$resolved = preg_replace_callback(
		'/%%?([a-z_]+)%%?/i',
		static function ( $match ) use ( $vars ) {
			$name = strtolower( $match[1] );

			return array_key_exists( $name, $vars ) ? (string) $vars[ $name ] : $match[0];
		},
		(string) $text
	);

	return trim( preg_replace( '/\s+/u', ' ', $resolved ) );
}

/**
 * Whether text still contains a template variable (so wouldn't read right as is).
 *
 * @param string $text Text.
 * @return bool
 */
function lcp_yeast_seo_ie_has_variables( $text ) {
	return (bool) preg_match( '/%%?[a-z_]+%%?/i', (string) $text );
}

/**
 * Variable values for one post.
 *
 * @param WP_Post $post Post.
 * @return string[]
 */
function lcp_yeast_seo_ie_post_variables( $post ) {
	$categories = 'post' === $post->post_type ? get_the_category( $post->ID ) : array();
	$parent     = $post->post_parent ? get_the_title( $post->post_parent ) : '';
	$category   = $categories ? $categories[0]->name : '';

	return array(
		'title'            => wp_strip_all_tags( get_the_title( $post ) ),
		'parent_title'     => wp_strip_all_tags( $parent ),
		'sitename'         => wp_strip_all_tags( get_bloginfo( 'name' ) ),
		'sitedesc'         => wp_strip_all_tags( get_bloginfo( 'description' ) ),
		'tagline'          => wp_strip_all_tags( get_bloginfo( 'description' ) ),
		'sep'              => lcp_yeast_seo_title_sep(),
		'excerpt'          => lcp_yeast_seo_description_from_text( $post->post_excerpt, '' ),
		'category'         => $category,
		'primary_category' => $category,
		'page'             => '',
		'currentyear'      => gmdate( 'Y' ),
	);
}

/**
 * One post's title and description in an engine, with variables resolved.
 *
 * @param WP_Post $post   Post.
 * @param string  $engine Engine slug.
 * @return string[] title, description.
 */
function lcp_yeast_seo_ie_read_post( $post, $engine ) {
	$engines = lcp_yeast_seo_ie_engines();
	$keys    = $engines[ $engine ];
	$title   = trim( (string) get_post_meta( $post->ID, $keys['title'], true ) );
	$desc    = trim( (string) get_post_meta( $post->ID, $keys['description'], true ) );

	if ( 'yeast' !== $engine ) {
		$vars  = lcp_yeast_seo_ie_post_variables( $post );
		$title = lcp_yeast_seo_ie_resolve_variables( $title, $vars );
		$desc  = lcp_yeast_seo_ie_resolve_variables( $desc, $vars );
	}

	return array( $title, $desc );
}

/**
 * Whether another SEO plugin's stored robots value means "noindex".
 *
 * Only noindex is carried over: index is this plugin's default, so there is
 * nothing to copy. Yoast stores 1 for noindex and 2 for a forced index.
 *
 * @param string $engine Engine slug.
 * @param mixed  $raw    Stored meta value.
 * @return bool
 */
function lcp_yeast_seo_ie_is_noindex( $engine, $raw ) {
	switch ( $engine ) {
		case 'yeast':
			return 'noindex' === (string) $raw;
		case 'yoast':
			return '1' === (string) $raw;
		case 'seopress':
			return 'yes' === (string) $raw;
		case 'rankmath':
			return is_array( $raw ) && in_array( 'noindex', $raw, true );
	}

	return false;
}

/**
 * A canonical worth copying: set, and pointing somewhere other than the page itself.
 *
 * @param string $url       Stored canonical URL.
 * @param string $permalink The page's own URL.
 * @return string The URL, or '' when there is nothing to copy.
 */
function lcp_yeast_seo_ie_clean_canonical( $url, $permalink ) {
	$url = trim( (string) $url );

	if ( '' === $url || untrailingslashit( $url ) === untrailingslashit( (string) $permalink ) ) {
		return '';
	}

	return $url;
}

/**
 * Noindex and canonical for one post, as another SEO plugin stored them.
 *
 * @param WP_Post $post   Post.
 * @param string  $engine Engine slug.
 * @return array{noindex:bool,canonical:string}
 */
function lcp_yeast_seo_ie_read_extras( $post, $engine ) {
	$keys = lcp_yeast_seo_ie_engines()[ $engine ];

	return array(
		'noindex'   => lcp_yeast_seo_ie_is_noindex( $engine, get_post_meta( $post->ID, $keys['robots'], true ) ),
		'canonical' => lcp_yeast_seo_ie_clean_canonical( (string) get_post_meta( $post->ID, $keys['canonical'], true ), (string) get_permalink( $post ) ),
	);
}

/**
 * Lowercases and trims header cells, dropping a leading UTF-8 BOM.
 *
 * @param array $headers Raw header cells.
 * @return string[]
 */
function lcp_yeast_seo_ie_normalize_headers( array $headers ) {
	return array_map(
		static function ( $header ) {
			return strtolower( trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header ) ) );
		},
		$headers
	);
}

/**
 * Maps normalised headers to the fields this importer understands.
 *
 * @param string[] $headers Normalised headers.
 * @return array|WP_Error Field => column index.
 */
function lcp_yeast_seo_ie_map_headers( array $headers ) {
	$aliases = array(
		'url'              => array( 'url' ),
		'meta_title'       => array( 'meta title', 'title', 'meta_title' ),
		'meta_description' => array( 'meta description', 'description', 'meta_description', 'meta desc' ),
		'noindex'          => array( 'noindex', 'no index', 'robots' ),
		'canonical'        => array( 'canonical', 'canonical url', 'canonical_url' ),
	);
	$mapped  = array();

	foreach ( $aliases as $field => $names ) {
		foreach ( $headers as $index => $header ) {
			if ( in_array( $header, $names, true ) ) {
				$mapped[ $field ] = $index;
				break;
			}
		}
	}

	if ( ! isset( $mapped['url'] ) ) {
		return new WP_Error( 'lcp_missing_url_header', 'Missing required URL column.' );
	}

	if ( ! array_intersect_key( $mapped, array_flip( array( 'meta_title', 'meta_description', 'noindex', 'canonical' ) ) ) ) {
		return new WP_Error( 'lcp_missing_meta_headers', 'Include at least one Meta Title, Meta Description, Noindex or Canonical column.' );
	}

	return $mapped;
}

/**
 * Pulls one row's fields out by mapped column.
 *
 * @param array $mapped Field => column index.
 * @param array $row    Row cells.
 * @return string[]
 */
function lcp_yeast_seo_ie_extract_row( array $mapped, array $row ) {
	$out = array();

	foreach ( array( 'url', 'meta_title', 'meta_description', 'noindex', 'canonical' ) as $field ) {
		$out[ $field ] = isset( $mapped[ $field ], $row[ $mapped[ $field ] ] ) ? (string) $row[ $mapped[ $field ] ] : '';
	}

	return $out;
}

/**
 * Reads a Noindex / Robots spreadsheet cell.
 *
 * Anything containing "noindex", or a yes/true/1/x, means noindex; a plain
 * "index" or a no/false/0 means index. A blank or unrecognised cell means "leave
 * the page alone".
 *
 * @param string $cell Cell text.
 * @return string noindex, index or '' for no instruction.
 */
function lcp_yeast_seo_ie_parse_robots_cell( $cell ) {
	$cell = strtolower( trim( (string) $cell ) );

	if ( '' === $cell ) {
		return '';
	}

	if ( false !== strpos( $cell, 'noindex' ) || in_array( $cell, array( 'yes', 'y', 'true', '1', 'x' ), true ) ) {
		return 'noindex';
	}

	if ( 0 === strpos( $cell, 'index' ) || in_array( $cell, array( 'no', 'n', 'false', '0' ), true ) ) {
		return 'index';
	}

	return '';
}

/**
 * Parses a CSV upload.
 *
 * @param string $path File path.
 * @return array|WP_Error Rows (url, meta_title, meta_description).
 */
function lcp_yeast_seo_ie_parse_csv( $path ) {
	$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

	if ( false === $handle ) {
		return new WP_Error( 'lcp_csv_open_failed', 'Could not open the CSV file.' );
	}

	$headers = fgetcsv( $handle, 0, ',', '"', '\\' );

	if ( false === $headers ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return new WP_Error( 'lcp_csv_empty', 'The CSV file is empty.' );
	}

	$mapped = lcp_yeast_seo_ie_map_headers( lcp_yeast_seo_ie_normalize_headers( $headers ) );

	if ( is_wp_error( $mapped ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $mapped;
	}

	$rows = array();

	while ( false !== ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		if ( array( null ) === $data ) {
			continue;
		}

		$rows[] = lcp_yeast_seo_ie_extract_row( $mapped, $data );
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return $rows;
}

/**
 * Converts an Excel column reference (A, B, AA) to a zero-based index.
 *
 * @param string $reference Cell reference such as "C2".
 * @return int
 */
function lcp_yeast_seo_ie_column_index( $reference ) {
	$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $reference ) );
	$index   = 0;

	for ( $i = 0, $length = strlen( $letters ); $i < $length; $i++ ) {
		$index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
	}

	return max( 0, $index - 1 );
}

/**
 * Reads the shared-strings table of an XLSX file.
 *
 * @param string $xml Contents of xl/sharedStrings.xml.
 * @return string[]
 */
function lcp_yeast_seo_ie_xlsx_shared_strings( $xml ) {
	$document = simplexml_load_string( $xml );
	$items    = $document ? $document->xpath( '/*[local-name()="sst"]/*[local-name()="si"]' ) : false;

	if ( ! $items ) {
		return array();
	}

	$strings = array();

	foreach ( $items as $item ) {
		$value = '';

		foreach ( (array) $item->xpath( './/*[local-name()="t"]' ) as $text_node ) {
			$value .= (string) $text_node;
		}

		$strings[] = $value;
	}

	return $strings;
}

/**
 * Reads one XLSX cell as text, resolving shared and inline strings.
 *
 * @param SimpleXMLElement $cell           Cell.
 * @param string[]         $shared_strings Shared strings.
 * @return string
 */
function lcp_yeast_seo_ie_xlsx_cell_value( SimpleXMLElement $cell, array $shared_strings ) {
	$type  = (string) $cell['t'];
	$nodes = $cell->xpath( './*[local-name()="v"]' );
	$value = ! empty( $nodes ) ? (string) $nodes[0] : '';

	if ( 's' === $type ) {
		return $shared_strings[ (int) $value ] ?? '';
	}

	if ( 'inlineStr' === $type ) {
		$inline = $cell->xpath( './*[local-name()="is"]//*[local-name()="t"]' );

		if ( ! empty( $inline ) ) {
			return (string) $inline[0];
		}
	}

	return $value;
}

/**
 * Parses an XLSX upload (first worksheet).
 *
 * @param string $path File path.
 * @return array|WP_Error Rows (url, meta_title, meta_description).
 */
function lcp_yeast_seo_ie_parse_xlsx( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'lcp_ziparchive_missing', 'XLSX support requires the PHP ZipArchive extension.' );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'lcp_xlsx_open_failed', 'Could not open the XLSX file.' );
	}

	$strings_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
	$sheet_xml   = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->close();

	$shared = false !== $strings_xml ? lcp_yeast_seo_ie_xlsx_shared_strings( $strings_xml ) : array();

	if ( false === $sheet_xml ) {
		return new WP_Error( 'lcp_xlsx_sheet_missing', 'Could not find the first worksheet in the XLSX file.' );
	}

	$sheet      = simplexml_load_string( $sheet_xml );
	$sheet_rows = $sheet ? $sheet->xpath( '/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]' ) : false;

	if ( false === $sheet_rows || null === $sheet_rows ) {
		return new WP_Error( 'lcp_xlsx_invalid', 'The XLSX worksheet could not be parsed.' );
	}

	$header = null;
	$raw    = array();

	foreach ( $sheet_rows as $row ) {
		$values = array();

		foreach ( (array) $row->xpath( './*[local-name()="c"]' ) as $cell ) {
			$values[ lcp_yeast_seo_ie_column_index( (string) $cell['r'] ) ] = lcp_yeast_seo_ie_xlsx_cell_value( $cell, $shared );
		}

		if ( null === $header ) {
			$header = $values;
			continue;
		}

		$raw[] = $values;
	}

	if ( null === $header ) {
		return new WP_Error( 'lcp_xlsx_empty', 'The XLSX file is empty.' );
	}

	$normalised = array();
	$width      = $header ? max( array_keys( $header ) ) + 1 : 0;

	for ( $i = 0; $i < $width; $i++ ) {
		$normalised[ $i ] = $header[ $i ] ?? '';
	}

	$mapped = lcp_yeast_seo_ie_map_headers( lcp_yeast_seo_ie_normalize_headers( $normalised ) );

	if ( is_wp_error( $mapped ) ) {
		return $mapped;
	}

	return array_map(
		static function ( $values ) use ( $mapped ) {
			return lcp_yeast_seo_ie_extract_row( $mapped, $values );
		},
		$raw
	);
}

/**
 * Whether a string is an absolute http(s) URL.
 *
 * @param string $value Value.
 * @return bool
 */
function lcp_yeast_seo_ie_is_absolute_url( $value ) {
	return (bool) preg_match( '#^https?://#i', $value );
}

/**
 * Normalises the path of a URL or path-only input ("/about/", "about").
 *
 * @param string $input URL or path.
 * @return string|null
 */
function lcp_yeast_seo_ie_normalized_path( $input ) {
	$value = trim( $input );

	if ( '' === $value ) {
		return null;
	}

	if ( ! lcp_yeast_seo_ie_is_absolute_url( $value ) && 0 !== strpos( $value, '/' ) ) {
		$value = '/' . $value;
	}

	$parts = wp_parse_url( $value );

	if ( false === $parts ) {
		return null;
	}

	$path = preg_replace( '#/+#', '/', isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '/' );

	return ( ! is_string( $path ) || '' === $path ) ? '/' : '/' . ltrim( $path, '/' );
}

/**
 * Candidate paths for matching: as given, without and with a trailing slash.
 *
 * @param string $input URL or path.
 * @return string[]
 */
function lcp_yeast_seo_ie_candidate_paths( $input ) {
	$path = lcp_yeast_seo_ie_normalized_path( $input );

	if ( null === $path ) {
		return array();
	}

	$paths = array( $path );

	if ( '/' !== $path ) {
		$paths[] = untrailingslashit( $path );
		$paths[] = trailingslashit( $path );
	}

	return array_values( array_unique( array_filter( $paths ) ) );
}

/**
 * Candidate absolute URLs for matching, on this site's scheme and host.
 *
 * @param string $input URL or path.
 * @return string[]
 */
function lcp_yeast_seo_ie_candidate_urls( $input ) {
	$urls = array();

	if ( lcp_yeast_seo_ie_is_absolute_url( $input ) ) {
		$parts     = wp_parse_url( $input );
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host      = $parts['host'] ?? '';

		if ( $site_host && preg_replace( '/^www\./i', '', $host ) === preg_replace( '/^www\./i', '', $site_host ) ) {
			$host = $site_host;
		}

		$urls[] = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . $host . ( lcp_yeast_seo_ie_normalized_path( $input ) ?? '/' );
	}

	foreach ( lcp_yeast_seo_ie_candidate_paths( $input ) as $path ) {
		$urls[] = home_url( $path );
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/**
 * Finds the post a URL or path refers to.
 *
 * @param string $input URL or path from the spreadsheet.
 * @return array{post_id:int,error:?string} Post ID (0 when none) and an error for ambiguous matches.
 */
function lcp_yeast_seo_ie_resolve_post( $input ) {
	$input = html_entity_decode( trim( $input ), ENT_QUOTES, 'UTF-8' );

	if ( '' === $input ) {
		return array(
			'post_id' => 0,
			'error'   => 'Missing URL.',
		);
	}

	$types = lcp_yeast_seo_post_types();

	foreach ( lcp_yeast_seo_ie_candidate_urls( $input ) as $url ) {
		$post_id = url_to_postid( $url );

		if ( $post_id && in_array( get_post_type( $post_id ), $types, true ) ) {
			return array(
				'post_id' => (int) $post_id,
				'error'   => null,
			);
		}
	}

	$matches = array();

	foreach ( lcp_yeast_seo_ie_candidate_paths( $input ) as $path ) {
		$trimmed = trim( $path, '/' );

		if ( '' === $trimmed ) {
			continue;
		}

		$by_path = get_page_by_path( $trimmed, OBJECT, array_values( $types ) );

		if ( $by_path instanceof WP_Post ) {
			$matches[ $by_path->ID ] = $by_path->ID;
		}

		$segments = array_values( array_filter( explode( '/', $trimmed ) ) );
		$slug     = end( $segments );

		if ( $slug ) {
			$by_slug = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => array_values( $types ),
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);

			foreach ( $by_slug as $id ) {
				$matches[ (int) $id ] = (int) $id;
			}
		}
	}

	if ( count( $matches ) > 1 ) {
		$labels = array();

		foreach ( $matches as $id ) {
			$labels[] = sprintf( '#%d %s', $id, get_the_title( $id ) );
		}

		return array(
			'post_id' => 0,
			'error'   => 'Ambiguous URL match. Candidates: ' . implode( ', ', $labels ),
		);
	}

	return array(
		'post_id' => $matches ? (int) reset( $matches ) : 0,
		'error'   => null,
	);
}

/**
 * Builds one results row.
 *
 * @param int    $row     Row number in the spreadsheet.
 * @param string $url     URL as given.
 * @param string $status  Updated, Dry run, Unchanged or Skipped.
 * @param string $message Details.
 * @param string $title   Title from the file.
 * @param string $desc    Description from the file.
 * @param string $matched Matched relative URL.
 * @param array  $extras  Robots (noindex or index) and canonical from the file, and the per-field details.
 * @return array
 */
function lcp_yeast_seo_ie_result_row( $row, $url, $status, $message, $title = '', $desc = '', $matched = '', array $extras = array() ) {
	return array(
		'row'              => $row,
		'url'              => $url,
		'status'           => $status,
		'message'          => $message,
		'meta_title'       => $title,
		'meta_description' => $desc,
		'matched_url'      => $matched,
		'noindex'          => (string) ( $extras['robots'] ?? '' ),
		'canonical'        => (string) ( $extras['canonical'] ?? '' ),
		'details'          => (array) ( $extras['details'] ?? array() ),
	);
}

/**
 * The site-relative URL of a post ("/about/").
 *
 * @param int $post_id Post ID.
 * @return string
 */
function lcp_yeast_seo_ie_relative_url( $post_id ) {
	$url = wp_make_link_relative( (string) get_permalink( $post_id ) );

	return '' === $url ? '/' : $url;
}

/**
 * Stores the outcome for the next page load and goes back to the tab.
 *
 * @param bool    $success Whether the action worked.
 * @param string  $message Summary.
 * @param array[] $rows    Result rows.
 * @param array   $counts  Counts by status.
 * @return void
 */
function lcp_yeast_seo_ie_redirect( $success, $message, array $rows = array(), array $counts = array(), $dry_run = false ) {
	set_transient(
		'lcp_yeast_seo_ie_result_' . get_current_user_id(),
		array(
			'success' => $success,
			'message' => $message,
			'rows'    => $rows,
			'counts'  => $counts,
			'dry_run' => (bool) $dry_run,
		),
		10 * MINUTE_IN_SECONDS
	);

	wp_safe_redirect( add_query_arg( array( 'page' => 'lcp-yeast-seo', 'tab' => 'import-export' ), admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Shortens a value for display in the results.
 *
 * @param string $text Text.
 * @param int    $max  Maximum characters.
 * @return string
 */
function lcp_yeast_seo_ie_excerpt( $text, $max = 90 ) {
	return mb_strimwidth( trim( preg_replace( '/\s+/u', ' ', (string) $text ) ), 0, $max, '...' );
}

/**
 * Applies a title, description, canonical and/or robots value to a post, or
 * reports what it would do.
 *
 * Each value is compared with what the page has now, and the message says
 * which it is: matching, new, changing, or kept. A value that matches is not
 * written again.
 *
 * "noindex" is always applied unless the page already has it, including to a
 * page saved as "index" (which is only the default); a noindex that gets lost
 * is the costly mistake. "index" can only undo a noindex, and only when
 * overwriting is allowed. Canonicals follow the same overwrite rule as titles
 * and descriptions.
 *
 * @param int    $post_id   Post ID.
 * @param string $title     New title ('' to leave alone).
 * @param string $desc      New description ('' to leave alone).
 * @param bool   $dry_run   Only report.
 * @param bool   $overwrite Replace existing values.
 * @param array  $extras    Optional canonical (string) and robots (noindex or index).
 * @return array status, message (one line per field) and details (field, state, current and new value for each).
 */
function lcp_yeast_seo_ie_apply( $post_id, $title, $desc, $dry_run, $overwrite, array $extras = array() ) {
	$lines   = array();
	$details = array();
	$writes  = array();
	$fields  = array(
		array( LCP_YEAST_SEO_TITLE_META_KEY, $title, 'Title', 'sanitize_text_field' ),
		array( LCP_YEAST_SEO_DESCRIPTION_META_KEY, $desc, 'Description', 'sanitize_textarea_field' ),
		array( LCP_YEAST_SEO_CANONICAL_META_KEY, (string) ( $extras['canonical'] ?? '' ), 'Canonical', 'esc_url_raw' ),
	);

	foreach ( $fields as $field ) {
		list( $key, $value, $label, $sanitize ) = $field;

		if ( '' === $value ) {
			continue;
		}

		$existing = trim( (string) get_post_meta( $post_id, $key, true ) );
		$clean    = call_user_func( $sanitize, $value );
		$detail   = array(
			'field'   => $label,
			'state'   => 'new',
			'current' => $existing,
			'new'     => $clean,
		);

		if ( $existing === $clean ) {
			$detail['state'] = 'matches';
			$lines[]         = $label . ': matches the current value.';
		} elseif ( ! $overwrite && '' !== $existing ) {
			$detail['state'] = 'kept';
			$lines[]         = $label . ': kept "' . lcp_yeast_seo_ie_excerpt( $existing ) . '" (the file has "' . lcp_yeast_seo_ie_excerpt( $value ) . '"; overwriting is off).';
		} else {
			$writes[ $key ]  = $clean;
			$detail['state'] = '' === $existing ? 'new' : 'changes';
			$lines[]         = '' === $existing
				? $label . ': new "' . lcp_yeast_seo_ie_excerpt( $value ) . '".'
				: $label . ': "' . lcp_yeast_seo_ie_excerpt( $existing ) . '" => "' . lcp_yeast_seo_ie_excerpt( $value ) . '".';

			if ( lcp_yeast_seo_ie_has_variables( $value ) ) {
				$detail['note'] = 'Still contains a template variable.';
				$lines[]        = $label . ' still contains a template variable.';
			}
		}

		$details[] = $detail;
	}

	$robots = (string) ( $extras['robots'] ?? '' );

	if ( '' !== $robots ) {
		$was_noindex = 'noindex' === trim( (string) get_post_meta( $post_id, LCP_YEAST_SEO_ROBOTS_META_KEY, true ) );
		$detail      = array(
			'field'   => 'Noindex',
			'state'   => 'matches',
			'current' => $was_noindex ? 'Noindex' : 'Indexed',
			'new'     => 'noindex' === $robots ? 'Noindex' : 'Indexed',
		);

		if ( 'noindex' === $robots && $was_noindex ) {
			$lines[] = 'Noindex: matches, the page is already noindex.';
		} elseif ( 'noindex' === $robots ) {
			$writes[ LCP_YEAST_SEO_ROBOTS_META_KEY ] = 'noindex';
			$detail['state']                         = 'on';
			$lines[]                                 = 'Noindex: set (the page is currently indexed).';
		} elseif ( ! $was_noindex ) {
			$lines[] = 'Noindex: matches, the page is already indexed.';
		} elseif ( $overwrite ) {
			$writes[ LCP_YEAST_SEO_ROBOTS_META_KEY ] = 'index';
			$detail['state']                         = 'off';
			$lines[]                                 = 'Noindex: removed (the page is currently noindex).';
		} else {
			$detail['state'] = 'kept';
			$lines[]         = 'Noindex: kept (the file says index; overwriting is off).';
		}

		$details[] = $detail;
	}

	if ( ! $writes ) {
		// Values the page already has are kept on purpose, which isn't a failure to match.
		return array( $lines ? 'Unchanged' : 'Skipped', implode( "\n", $lines ) ?: 'Nothing to update.', $details );
	}

	if ( ! $dry_run ) {
		foreach ( $writes as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		clean_post_cache( $post_id );
	}

	return array( $dry_run ? 'Dry run' : 'Updated', implode( "\n", $lines ), $details );
}

/**
 * Tallies result rows by status.
 *
 * @param array[] $rows Result rows.
 * @return int[]
 */
function lcp_yeast_seo_ie_count_statuses( array $rows ) {
	$counts = array(
		'Updated'   => 0,
		'Dry run'   => 0,
		'Unchanged' => 0,
		'Skipped'   => 0,
	);

	foreach ( $rows as $row ) {
		$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
	}

	return $counts;
}

/**
 * Handles the CSV/XLSX import.
 *
 * @return void
 */
function lcp_yeast_seo_ie_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( LCP_YEAST_SEO_IE_NONCE );

	$update_title = ! empty( $_POST['update_title'] );
	$update_desc  = ! empty( $_POST['update_description'] );
	$update_index = ! empty( $_POST['update_noindex'] );
	$update_canon = ! empty( $_POST['update_canonical'] );
	$dry_run      = ! empty( $_POST['dry_run'] );
	$overwrite    = ! empty( $_POST['overwrite'] );

	if ( ! $update_title && ! $update_desc && ! $update_index && ! $update_canon ) {
		lcp_yeast_seo_ie_redirect( false, 'Select at least one field to update.' );
	}

	$file = isset( $_FILES['lcp_metadata_file'] ) ? $_FILES['lcp_metadata_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		lcp_yeast_seo_ie_redirect( false, 'Upload a valid CSV or XLSX file.' );
	}

	$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

	if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
		lcp_yeast_seo_ie_redirect( false, 'Only CSV and XLSX files are supported.' );
	}

	$parsed = 'csv' === $extension ? lcp_yeast_seo_ie_parse_csv( $file['tmp_name'] ) : lcp_yeast_seo_ie_parse_xlsx( $file['tmp_name'] );

	if ( is_wp_error( $parsed ) ) {
		lcp_yeast_seo_ie_redirect( false, $parsed->get_error_message() );
	}

	$rows = array();

	foreach ( $parsed as $index => $row ) {
		$number = $index + 2;
		$url    = trim( $row['url'] );
		$title  = $update_title ? trim( $row['meta_title'] ) : '';
		$desc   = $update_desc ? trim( $row['meta_description'] ) : '';
		$robots = $update_index ? lcp_yeast_seo_ie_parse_robots_cell( $row['noindex'] ) : '';
		$canon  = $update_canon ? trim( $row['canonical'] ) : '';

		if ( '' === $url ) {
			$rows[] = lcp_yeast_seo_ie_result_row( $number, $url, 'Skipped', 'Missing URL.', $title, $desc, '', array( 'robots' => $robots, 'canonical' => $canon ) );
			continue;
		}

		$match = lcp_yeast_seo_ie_resolve_post( $url );

		if ( $match['error'] || ! $match['post_id'] ) {
			$rows[] = lcp_yeast_seo_ie_result_row( $number, $url, 'Skipped', $match['error'] ?: 'No matching page or post found for this URL.', $title, $desc, '', array( 'robots' => $robots, 'canonical' => $canon ) );
			continue;
		}

		$extras = array(
			'robots'    => $robots,
			'canonical' => lcp_yeast_seo_ie_clean_canonical( $canon, (string) get_permalink( $match['post_id'] ) ),
		);

		list( $status, $message, $details ) = lcp_yeast_seo_ie_apply( $match['post_id'], $title, $desc, $dry_run, $overwrite, $extras );

		$rows[] = lcp_yeast_seo_ie_result_row( $number, $url, $status, $message, $title, $desc, lcp_yeast_seo_ie_relative_url( $match['post_id'] ), array( 'robots' => $robots, 'canonical' => $canon, 'details' => $details ) );
	}

	$counts = lcp_yeast_seo_ie_count_statuses( $rows );

	lcp_yeast_seo_ie_redirect(
		true,
		$dry_run ? sprintf( 'Dry run complete. Reviewed %d row(s); %d would change, %d already match or would be kept.', count( $rows ), $counts['Dry run'], $counts['Unchanged'] ) : sprintf( 'Import complete. Updated %d of %d row(s).', $counts['Updated'], count( $rows ) ),
		$rows,
		$counts,
		$dry_run
	);
}
add_action( 'admin_post_lcp_yeast_seo_import', 'lcp_yeast_seo_ie_handle_import' );

/**
 * Selected post types from a submitted form, limited to the ones this plugin manages.
 *
 * @return string[]
 */
function lcp_yeast_seo_ie_posted_types() {
	$raw = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce.

	return array_values( array_intersect( array_map( 'sanitize_key', $raw ), lcp_yeast_seo_post_types() ) );
}

/**
 * Published posts of the given types.
 *
 * @param string[] $types Post types.
 * @return WP_Post[]
 */
function lcp_yeast_seo_ie_get_posts( array $types ) {
	return get_posts(
		array(
			'post_type'   => $types,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);
}

/**
 * Handles the CSV export.
 *
 * @return void
 */
function lcp_yeast_seo_ie_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( LCP_YEAST_SEO_IE_NONCE );

	$engines = lcp_yeast_seo_ie_engines();
	$engine  = isset( $_POST['engine'], $engines[ $_POST['engine'] ] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : 'yeast';
	$types   = lcp_yeast_seo_ie_posted_types();
	$only    = ! empty( $_POST['only_with_meta'] );

	if ( ! $types ) {
		lcp_yeast_seo_ie_redirect( false, 'Select at least one post type to export.' );
	}

	$lines = array();

	foreach ( lcp_yeast_seo_ie_get_posts( $types ) as $post ) {
		list( $title, $desc ) = lcp_yeast_seo_ie_read_post( $post, $engine );
		$extras               = lcp_yeast_seo_ie_read_extras( $post, $engine );

		if ( $only && '' === $title && '' === $desc && ! $extras['noindex'] && '' === $extras['canonical'] ) {
			continue;
		}

		$lines[] = array( lcp_yeast_seo_ie_relative_url( $post->ID ), $title, $desc, $extras['noindex'] ? 'noindex' : '', $extras['canonical'] );
	}

	if ( ! $lines ) {
		lcp_yeast_seo_ie_redirect( false, 'No titles, descriptions, noindex or canonical settings found to export for the selected post types.' );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seo-export-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fputcsv( $out, array( 'URL', 'Meta Title', 'Meta Description', 'Noindex', 'Canonical' ), ',', '"', '\\' );

	foreach ( $lines as $line ) {
		fputcsv( $out, $line, ',', '"', '\\' );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_export', 'lcp_yeast_seo_ie_handle_export' );

/**
 * Downloads the skipped rows of the last import, ready to fix and re-upload.
 *
 * @return void
 */
function lcp_yeast_seo_ie_handle_export_skipped() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( LCP_YEAST_SEO_IE_NONCE );

	$result  = get_transient( 'lcp_yeast_seo_ie_result_' . get_current_user_id() );
	$skipped = array_filter(
		is_array( $result ) && ! empty( $result['rows'] ) ? $result['rows'] : array(),
		static function ( $row ) {
			return 'Skipped' === $row['status'];
		}
	);

	if ( ! $skipped ) {
		lcp_yeast_seo_ie_redirect( false, 'No skipped rows to export. Run an import first.' );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seo-skipped-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fputcsv( $out, array( 'URL', 'Meta Title', 'Meta Description', 'Noindex', 'Canonical' ), ',', '"', '\\' );

	foreach ( $skipped as $row ) {
		fputcsv( $out, array( $row['url'], $row['meta_title'], $row['meta_description'], $row['noindex'] ?? '', $row['canonical'] ?? '' ), ',', '"', '\\' );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_export_skipped', 'lcp_yeast_seo_ie_handle_export_skipped' );

/**
 * Which meta keys a "clear" covers for an engine.
 *
 * "values" is just the four fields this plugin can import. "all" is every
 * per-page value the other plugin stored, found by its key prefix.
 *
 * @param array  $engine The engine's meta keys, from lcp_yeast_seo_ie_engines().
 * @param string $scope  values or all.
 * @return array{keys:string[],prefix:string}
 */
function lcp_yeast_seo_ie_legacy_scope( array $engine, $scope ) {
	if ( 'all' === $scope && ! empty( $engine['prefix'] ) ) {
		return array(
			'keys'   => array(),
			'prefix' => (string) $engine['prefix'],
		);
	}

	return array(
		'keys'   => array_values( array_filter( array( $engine['title'] ?? '', $engine['description'] ?? '', $engine['robots'] ?? '', $engine['canonical'] ?? '' ) ) ),
		'prefix' => '',
	);
}

/**
 * The SQL condition and its arguments for a clear.
 *
 * @param string $engine Engine slug.
 * @param string $scope  values or all.
 * @return array{0:string,1:array} Condition on meta_key, and its arguments.
 */
function lcp_yeast_seo_ie_legacy_where( $engine, $scope ) {
	global $wpdb;

	$covered = lcp_yeast_seo_ie_legacy_scope( lcp_yeast_seo_ie_engines()[ $engine ], $scope );

	if ( '' !== $covered['prefix'] ) {
		return array( 'meta_key LIKE %s', array( $wpdb->esc_like( $covered['prefix'] ) . '%' ) );
	}

	return array( 'meta_key IN (' . implode( ', ', array_fill( 0, count( $covered['keys'] ), '%s' ) ) . ')', $covered['keys'] );
}

/**
 * How many stored values and pages a clear would touch.
 *
 * @param string $engine Engine slug.
 * @param string $scope  values or all.
 * @return array{values:int,pages:int}
 */
function lcp_yeast_seo_ie_count_legacy( $engine, $scope ) {
	global $wpdb;

	list( $where, $args ) = lcp_yeast_seo_ie_legacy_where( $engine, $scope );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*), COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE {$where}", $args ), ARRAY_N );
	// phpcs:enable

	return array(
		'values' => (int) ( $row[0] ?? 0 ),
		'pages'  => (int) ( $row[1] ?? 0 ),
	);
}

/**
 * Reads the engine and scope of a clear or backup request.
 *
 * @return array{0:string,1:string} Engine slug (or '') and scope.
 */
function lcp_yeast_seo_ie_posted_legacy() {
	$engines = lcp_yeast_seo_ie_engines();
	$engine  = isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce.
	$scope   = isset( $_POST['scope'] ) && 'values' === $_POST['scope'] ? 'values' : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	return array( isset( $engines[ $engine ] ) && 'yeast' !== $engine ? $engine : '', $scope );
}

/**
 * Downloads what a clear would remove, as a backup.
 *
 * Built from the database on request, so nothing is stored in between.
 *
 * @return void
 */
function lcp_yeast_seo_ie_handle_export_backup() {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( LCP_YEAST_SEO_IE_NONCE );

	list( $engine, $scope ) = lcp_yeast_seo_ie_posted_legacy();

	if ( '' === $engine || ! lcp_yeast_seo_ie_count_legacy( $engine, $scope )['values'] ) {
		lcp_yeast_seo_ie_redirect( false, 'There is nothing to back up for that choice.' );
	}

	list( $where, $args ) = lcp_yeast_seo_ie_legacy_where( $engine, $scope );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seo-backup-' . $engine . '-' . gmdate( 'Y-m-d-His' ) . '.csv' );

	$out   = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$urls  = array();
	$after = 0;

	fputcsv( $out, array( 'URL', 'Post ID', 'Meta key', 'Value' ), ',', '"', '\\' );

	do {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$chunk = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE {$where} AND meta_id > %d ORDER BY meta_id LIMIT 1000", array_merge( $args, array( $after ) ) ), ARRAY_A );
		// phpcs:enable

		foreach ( $chunk as $meta ) {
			$id = (int) $meta['post_id'];

			if ( ! isset( $urls[ $id ] ) ) {
				$urls[ $id ] = lcp_yeast_seo_ie_relative_url( $id );
			}

			fputcsv( $out, array( $urls[ $id ], $id, $meta['meta_key'], $meta['meta_value'] ), ',', '"', '\\' );
			$after = (int) $meta['meta_id'];
		}
	} while ( count( $chunk ) === 1000 );

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_export_backup', 'lcp_yeast_seo_ie_handle_export_backup' );

/**
 * Removes the values another SEO plugin left on pages.
 *
 * @return void
 */
function lcp_yeast_seo_ie_handle_clear_legacy() {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( LCP_YEAST_SEO_IE_NONCE );

	list( $engine, $scope ) = lcp_yeast_seo_ie_posted_legacy();

	if ( '' === $engine ) {
		lcp_yeast_seo_ie_redirect( false, 'Choose which plugin to clear.' );
	}

	if ( empty( $_POST['confirm'] ) ) {
		lcp_yeast_seo_ie_redirect( false, 'Tick the box to confirm. Removing this data cannot be undone, so download the backup first.' );
	}

	$counts = lcp_yeast_seo_ie_count_legacy( $engine, $scope );
	$label  = lcp_yeast_seo_ie_engines()[ $engine ]['label'];

	if ( ! $counts['values'] ) {
		lcp_yeast_seo_ie_redirect( false, sprintf( 'No %s data was found on pages.', $label ) );
	}

	list( $where, $args ) = lcp_yeast_seo_ie_legacy_where( $engine, $scope );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE {$where}", $args ) );
	// phpcs:enable

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	} else {
		wp_cache_flush();
	}

	lcp_yeast_seo_ie_redirect(
		true,
		sprintf(
			'Removed %d value(s) on %d page(s) from %s. Its own settings and database tables were not touched.',
			$counts['values'],
			$counts['pages'],
			$label
		)
	);
}
add_action( 'admin_post_lcp_yeast_seo_clear_legacy', 'lcp_yeast_seo_ie_handle_clear_legacy' );

/**
 * Copies titles and descriptions from another SEO plugin's post meta into this one.
 *
 * @return void
 */
function lcp_yeast_seo_ie_handle_migrate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( LCP_YEAST_SEO_IE_NONCE );

	$engines = lcp_yeast_seo_ie_engines();
	$engine  = isset( $_POST['engine'], $engines[ $_POST['engine'] ] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : '';
	$types   = lcp_yeast_seo_ie_posted_types();
	$dry_run = ! empty( $_POST['dry_run'] );

	if ( '' === $engine || 'yeast' === $engine ) {
		lcp_yeast_seo_ie_redirect( false, 'Choose an SEO plugin to migrate from.' );
	}

	$copy = isset( $_POST['copy'] ) ? array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['copy'] ) ), array( 'text', 'noindex', 'canonical' ) ) : array();

	if ( ! $copy ) {
		lcp_yeast_seo_ie_redirect( false, 'Choose at least one thing to copy.' );
	}

	if ( ! $types ) {
		lcp_yeast_seo_ie_redirect( false, 'Select at least one post type.' );
	}

	$rows = array();

	foreach ( lcp_yeast_seo_ie_get_posts( $types ) as $post ) {
		list( $title, $desc ) = in_array( 'text', $copy, true ) ? lcp_yeast_seo_ie_read_post( $post, $engine ) : array( '', '' );
		$extras               = lcp_yeast_seo_ie_read_extras( $post, $engine );
		$extras               = array(
			'robots'    => in_array( 'noindex', $copy, true ) && $extras['noindex'] ? 'noindex' : '',
			'canonical' => in_array( 'canonical', $copy, true ) ? $extras['canonical'] : '',
		);

		if ( '' === $title && '' === $desc && '' === $extras['robots'] && '' === $extras['canonical'] ) {
			continue;
		}

		list( $status, $message, $details ) = lcp_yeast_seo_ie_apply( $post->ID, $title, $desc, $dry_run, ! empty( $_POST['overwrite'] ), $extras );

		$rows[] = lcp_yeast_seo_ie_result_row( count( $rows ) + 1, lcp_yeast_seo_ie_relative_url( $post->ID ), $status, $message, $title, $desc, lcp_yeast_seo_ie_relative_url( $post->ID ), array( 'details' => $details ) );
	}

	$counts = lcp_yeast_seo_ie_count_statuses( $rows );

	lcp_yeast_seo_ie_redirect(
		true,
		sprintf(
			'%s: %d page(s) with %s data. %s',
			$dry_run ? 'Dry run' : 'Migration complete',
			count( $rows ),
			$engines[ $engine ]['label'],
			$dry_run ? sprintf( '%d would change, %d already match or would be kept.', $counts['Dry run'], $counts['Unchanged'] ) : sprintf( '%d updated.', $counts['Updated'] )
		),
		$rows,
		$counts,
		$dry_run
	);
}
add_action( 'admin_post_lcp_yeast_seo_migrate', 'lcp_yeast_seo_ie_handle_migrate' );

/**
 * Number of posts that hold a title or description in an engine.
 *
 * @param string $engine Engine slug.
 * @return int
 */
function lcp_yeast_seo_ie_count_posts_with_data( $engine ) {
	global $wpdb;

	$keys   = lcp_yeast_seo_ie_engines()[ $engine ];
	$robots = array(
		'yoast'    => array( "meta_value = '1'", array() ),
		'seopress' => array( "meta_value = 'yes'", array() ),
		'rankmath' => array( 'meta_value LIKE %s', array( '%' . $wpdb->esc_like( '"noindex"' ) . '%' ) ),
	);
	$robots = $robots[ $engine ] ?? array( "meta_value = 'noindex'", array() );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE ( meta_key IN (%s, %s, %s) AND meta_value <> '' ) OR ( meta_key = %s AND {$robots[0]} )",
			array_merge( array( $keys['title'], $keys['description'], $keys['canonical'], $keys['robots'] ), $robots[1] )
		)
	);
	// phpcs:enable
}

/**
 * Prints the post type checkboxes.
 *
 * @return void
 */
function lcp_yeast_seo_ie_render_type_checkboxes() {
	foreach ( lcp_yeast_seo_post_types() as $slug ) {
		$object = get_post_type_object( $slug );
		?>
		<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $slug ); ?>" checked> <?php echo esc_html( ( $object ? $object->labels->singular_name : $slug ) . ' (' . $slug . ')' ); ?></label>
		<?php
	}
}

/**
 * Chip label and style for a result row's status.
 *
 * @param string $status Dry run, Updated, Unchanged or Skipped.
 * @return string[] Label, chip modifier and filter bucket.
 */
function lcp_yeast_seo_ie_status_chip( $status ) {
	$map = array(
		'Dry run'   => array( 'Would change', 'warn', 'change' ),
		'Updated'   => array( 'Updated', 'ok', 'change' ),
		'Unchanged' => array( 'Unchanged', 'neutral', 'unchanged' ),
		'Skipped'   => array( 'Skipped', 'error', 'skipped' ),
	);

	return $map[ $status ] ?? array( $status, 'neutral', 'unchanged' );
}

/**
 * Chip label and style for one field's state.
 *
 * @param string $state new, changes, matches, kept, on or off.
 * @return string[] Label and chip modifier.
 */
function lcp_yeast_seo_ie_state_chip( $state ) {
	$map = array(
		'new'     => array( 'New', 'ok' ),
		'changes' => array( 'Changes', 'warn' ),
		'matches' => array( 'Matches', 'neutral' ),
		'kept'    => array( 'Kept', 'neutral' ),
		'on'      => array( 'Turned on', 'warn' ),
		'off'     => array( 'Turned off', 'warn' ),
	);

	return $map[ $state ] ?? array( $state, 'neutral' );
}

/**
 * One value in the results: shortened for reading, in full on hover.
 *
 * @param string $text  Value.
 * @param string $class Modifier: old, new or same.
 * @param string $label Small caption before the value.
 * @return void
 */
function lcp_yeast_seo_ie_render_value( $text, $class, $label ) {
	?>
	<span class="lcp-ys-val lcp-ys-val--<?php echo esc_attr( $class ); ?>" title="<?php echo esc_attr( $text ); ?>"><?php echo '' !== $label ? '<i>' . esc_html( $label ) . '</i> ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?><?php echo esc_html( lcp_yeast_seo_ie_excerpt( $text, 160 ) ); ?></span>
	<?php
}

/**
 * One page's block in the results: its status, URL and what happens to each field.
 *
 * @param array $row Result row.
 * @return void
 */
function lcp_yeast_seo_ie_render_result_row( array $row ) {
	$status  = lcp_yeast_seo_ie_status_chip( $row['status'] );
	$details = (array) ( $row['details'] ?? array() );
	?>
	<article class="lcp-ys-result" data-bucket="<?php echo esc_attr( $status[2] ); ?>">
		<header class="lcp-ys-result__head">
			<span class="lcp-ys-chip lcp-ys-chip--<?php echo esc_attr( $status[1] ); ?>"><?php echo esc_html( $status[0] ); ?></span>
			<span class="lcp-ys-result__url"><?php echo esc_html( '' !== $row['matched_url'] ? $row['matched_url'] : $row['url'] ); ?></span>
			<?php if ( '' !== $row['matched_url'] && $row['matched_url'] !== $row['url'] ) : ?>
				<span class="lcp-ys-result__note">from <?php echo esc_html( $row['url'] ); ?></span>
			<?php endif; ?>
			<span class="lcp-ys-result__row">Row <?php echo esc_html( (string) $row['row'] ); ?></span>
		</header>
		<?php if ( $details ) : ?>
			<ul class="lcp-ys-fields">
				<?php foreach ( $details as $detail ) : ?>
					<?php $chip = lcp_yeast_seo_ie_state_chip( $detail['state'] ); ?>
					<li class="lcp-ys-field">
						<span class="lcp-ys-field__name"><?php echo esc_html( $detail['field'] ); ?></span>
						<span class="lcp-ys-chip lcp-ys-chip--<?php echo esc_attr( $chip[1] ); ?>"><?php echo esc_html( $chip[0] ); ?></span>
						<span class="lcp-ys-field__vals">
							<?php
							switch ( $detail['state'] ) {
								case 'changes':
								case 'on':
								case 'off':
									lcp_yeast_seo_ie_render_value( $detail['current'], 'old', 'Now' );
									lcp_yeast_seo_ie_render_value( $detail['new'], 'new', 'To' );
									break;
								case 'kept':
									lcp_yeast_seo_ie_render_value( $detail['current'], 'same', 'Keeping' );
									lcp_yeast_seo_ie_render_value( $detail['new'], 'old', 'File has' );
									break;
								case 'matches':
									lcp_yeast_seo_ie_render_value( $detail['new'], 'same', '' );
									break;
								default:
									lcp_yeast_seo_ie_render_value( $detail['new'], 'new', '' );
							}
							?>
							<?php if ( ! empty( $detail['note'] ) ) : ?>
								<span class="lcp-ys-field__warn"><?php echo esc_html( $detail['note'] ); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="lcp-ys-result__reason"><?php echo esc_html( $row['message'] ); ?></p>
		<?php endif; ?>
	</article>
	<?php
}

/**
 * Shows the result of the last import or migration, once.
 *
 * @return void
 */
function lcp_yeast_seo_ie_render_result() {
	$key    = 'lcp_yeast_seo_ie_result_' . get_current_user_id();
	$result = get_transient( $key );

	if ( ! is_array( $result ) ) {
		return;
	}

	delete_transient( $key );

	$counts = $result['counts'] ?? array();
	$rows   = $result['rows'] ?? array();
	$dry    = ! empty( $result['dry_run'] );

	if ( ! $rows ) {
		?>
		<div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $result['message'] ); ?></p></div>
		<?php
		return;
	}

	$change  = (int) ( $counts['Dry run'] ?? 0 ) + (int) ( $counts['Updated'] ?? 0 );
	$same    = (int) ( $counts['Unchanged'] ?? 0 );
	$skipped = (int) ( $counts['Skipped'] ?? 0 );
	?>
	<section class="lcp-ys-card lcp-ys-results" data-results data-filter="all">
		<div class="lcp-ys-card__head">
			<h2><?php echo $dry ? 'Dry run: nothing has been saved' : 'Results'; ?></h2>
			<div class="lcp-ys-card__intro"><p><?php echo $dry ? 'This is what would happen. To save these changes, untick "Dry run" and run it again.' : esc_html( $result['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static text or escaped. ?></p></div>
		</div>
		<div class="lcp-ys-results__bar">
			<ul class="lcp-ys-tally">
				<li class="lcp-ys-chip lcp-ys-chip--<?php echo $change ? ( $dry ? 'warn' : 'ok' ) : 'neutral'; ?>"><?php echo esc_html( $change . ( $dry ? ' would change' : ' updated' ) ); ?></li>
				<li class="lcp-ys-chip lcp-ys-chip--neutral"><?php echo esc_html( $same . ' unchanged' ); ?></li>
				<li class="lcp-ys-chip lcp-ys-chip--<?php echo $skipped ? 'error' : 'neutral'; ?>"><?php echo esc_html( $skipped . ' skipped' ); ?></li>
			</ul>
			<div class="lcp-ys-filter" role="group" aria-label="Filter results">
				<button type="button" class="lcp-ys-filter__btn is-active" data-result-filter="all">All</button>
				<button type="button" class="lcp-ys-filter__btn" data-result-filter="change"><?php echo $dry ? 'Would change' : 'Updated'; ?></button>
				<button type="button" class="lcp-ys-filter__btn" data-result-filter="unchanged">Unchanged</button>
				<button type="button" class="lcp-ys-filter__btn" data-result-filter="skipped">Skipped</button>
			</div>
		</div>
		<div class="lcp-ys-results__list">
			<?php
			foreach ( array_slice( $rows, 0, LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN ) as $row ) {
				lcp_yeast_seo_ie_render_result_row( $row );
			}
			?>
			<p class="lcp-ys-empty" data-result-empty hidden>No rows match this filter.</p>
		</div>
		<?php if ( count( $rows ) > LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN ) : ?>
			<p class="lcp-ys-results__foot"><?php echo esc_html( sprintf( 'Showing the first %d of %d rows.', LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN, count( $rows ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( $skipped ) : ?>
			<form class="lcp-ys-results__foot" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lcp_yeast_seo_export_skipped">
				<?php wp_nonce_field( LCP_YEAST_SEO_IE_NONCE ); ?>
				<?php submit_button( sprintf( 'Download %d skipped row(s) as CSV', $skipped ), 'secondary', 'submit', false ); ?>
				<span class="lcp-ys-savebar__hint">Fix the URLs and upload it again. Same columns as import.</span>
			</form>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Renders the Import / Export tab.
 *
 * @return void
 */
function lcp_yeast_seo_ie_render_tab() {
	$engines = lcp_yeast_seo_ie_engines();
	$default = lcp_yeast_seo_ie_default_engine();
	$found   = array();

	foreach ( array_keys( $engines ) as $slug ) {
		if ( 'yeast' !== $slug ) {
			$found[ $slug ] = lcp_yeast_seo_ie_count_posts_with_data( $slug );
		}
	}

	$legacy = array();

	foreach ( array_keys( $found ) as $slug ) {
		$legacy[ $slug ] = array(
			'all'    => lcp_yeast_seo_ie_count_legacy( $slug, 'all' ),
			'values' => lcp_yeast_seo_ie_count_legacy( $slug, 'values' ),
		);
	}

	// Pre-select the active plugin if it has data, otherwise the first source that does.
	$preselect = ! empty( $found[ $default ] ) ? $default : (string) array_key_first( array_filter( $found ) );

	// The same for the clear panel, which also covers data an import would not read.
	$preselect_legacy = ! empty( $legacy[ $default ]['all']['values'] ) ? $default : (string) array_key_first( array_filter( array_map( static function ( $counts ) { return $counts['all']['values']; }, $legacy ) ) );

	lcp_yeast_seo_ie_render_result();
	?>
	<section class="lcp-ys-card">
		<div class="lcp-ys-card__head">
			<h2>Export</h2>
			<div class="lcp-ys-card__intro"><p>Download a CSV of titles, descriptions, noindex settings and canonical URLs. It uses the same <code>URL</code>, <code>Meta Title</code>, <code>Meta Description</code>, <code>Noindex</code> and <code>Canonical</code> columns as import, with site-relative URLs, so the file works on any copy of the site. Template variables such as <code>%%title%%</code> are filled in.</p></div>
		</div>
		<div class="lcp-ys-body">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lcp_yeast_seo_export">
		<?php wp_nonce_field( LCP_YEAST_SEO_IE_NONCE ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lcp-ie-export-engine">Export from</label></th>
				<td>
					<select id="lcp-ie-export-engine" name="engine">
						<?php foreach ( $engines as $slug => $engine ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $default ); ?>><?php echo esc_html( $engine['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">Post types</th>
				<td><?php lcp_yeast_seo_ie_render_type_checkboxes(); ?><p class="description">Only published content is exported.</p></td>
			</tr>
			<tr>
				<th scope="row">Options</th>
				<td><label><input type="checkbox" name="only_with_meta" value="1" checked> Only include rows with a title or description</label></td>
			</tr>
		</table>
		<?php submit_button( 'Download CSV', 'primary', 'submit', false ); ?>
	</form>
		</div>
	</section>

	<section class="lcp-ys-card">
		<div class="lcp-ys-card__head">
			<h2>Import</h2>
			<div class="lcp-ys-card__intro"><p>Upload a CSV or XLSX with a header row of <code>URL</code> and any of <code>Meta Title</code>, <code>Meta Description</code>, <code>Noindex</code> and <code>Canonical</code>. Rows are matched to pages by URL, and ambiguous matches are skipped rather than guessed. In the Noindex column, <code>noindex</code> (or yes / true / 1) turns noindex on, and <code>index</code> (or no / false / 0) turns it off when overwriting is allowed. A blank cell leaves the page alone.</p></div>
		</div>
		<div class="lcp-ys-body">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="lcp_yeast_seo_import">
		<?php wp_nonce_field( LCP_YEAST_SEO_IE_NONCE ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lcp-ie-file">Spreadsheet</label></th>
				<td><input type="file" id="lcp-ie-file" name="lcp_metadata_file" accept=".csv,.xlsx" required></td>
			</tr>
			<tr>
				<th scope="row">Fields to update</th>
				<td>
					<label><input type="checkbox" name="update_title" value="1" checked> Meta Title</label><br>
					<label><input type="checkbox" name="update_description" value="1" checked> Meta Description</label><br>
					<label><input type="checkbox" name="update_noindex" value="1" checked> Noindex</label><br>
					<label><input type="checkbox" name="update_canonical" value="1" checked> Canonical</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Options</th>
				<td>
					<label><input type="checkbox" name="overwrite" value="1" checked> Overwrite values a page already has</label><br>
					<label><input type="checkbox" name="dry_run" value="1" checked> Dry run: show what would change without saving</label>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Process file', 'primary', 'submit', false ); ?>
	</form>
		</div>
	</section>

	<?php if ( array_sum( $found ) > 0 ) : ?>
	<section class="lcp-ys-card">
		<div class="lcp-ys-card__head">
			<h2>Migrate from another SEO plugin</h2>
			<div class="lcp-ys-card__intro"><p>Copies titles, descriptions, noindex settings and canonical URLs straight from another plugin's data on this site, with template variables filled in. Nothing is deleted from the other plugin.</p></div>
		</div>
		<div class="lcp-ys-body">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lcp_yeast_seo_migrate">
		<?php wp_nonce_field( LCP_YEAST_SEO_IE_NONCE ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Migrate from</th>
				<td>
					<?php foreach ( $engines as $slug => $engine ) : ?>
						<?php
						if ( 'yeast' === $slug ) {
							continue;
						}
						$count = $found[ $slug ];
						?>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="engine" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $slug, $preselect ); ?> <?php disabled( 0 === $count ); ?>>
							<?php echo esc_html( $engine['label'] ); ?>
							<span class="description">&mdash; <?php echo esc_html( $count ? sprintf( '%d page(s) with data', $count ) : 'no data found' ); ?></span>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Copy</th>
				<td>
					<label><input type="checkbox" name="copy[]" value="text" checked> Titles and descriptions</label><br>
					<label><input type="checkbox" name="copy[]" value="noindex" checked> Noindex settings</label><br>
					<label><input type="checkbox" name="copy[]" value="canonical" checked> Canonical URLs <span class="description">(only ones that point away from the page itself)</span></label>
				</td>
			</tr>
			<tr>
				<th scope="row">Post types</th>
				<td><?php lcp_yeast_seo_ie_render_type_checkboxes(); ?></td>
			</tr>
			<tr>
				<th scope="row">Options</th>
				<td>
					<label><input type="checkbox" name="overwrite" value="1"> Overwrite titles, descriptions and canonicals a page already has</label><br>
					<label><input type="checkbox" name="dry_run" value="1" checked> Dry run: show what would change without saving</label>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Migrate', 'primary', 'submit', false ); ?>
	</form>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( array_sum( array_map( static function ( $counts ) { return $counts['all']['values']; }, $legacy ) ) > 0 ) : ?>
	<section class="lcp-ys-card">
		<div class="lcp-ys-card__head">
			<h2>Clear data left by another SEO plugin</h2>
			<div class="lcp-ys-card__intro"><p>When you have migrated what you want, remove what the other plugin stored on your pages, so none of it lingers. This is separate from migrating, and it cannot be undone, so download the backup first. The plugin's own settings and database tables are not touched. When nothing is left, this panel disappears.</p></div>
		</div>
		<div class="lcp-ys-body">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( LCP_YEAST_SEO_IE_NONCE ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Clear data from</th>
				<td>
					<?php foreach ( $legacy as $slug => $counts ) : ?>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="engine" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $slug, $preselect_legacy ); ?> <?php disabled( 0 === $counts['all']['values'] ); ?>>
							<?php echo esc_html( $engines[ $slug ]['label'] ); ?>
							<span class="description">&mdash; <?php echo esc_html( $counts['all']['values'] ? sprintf( '%d value(s) on %d page(s); %d of them are a title, description, noindex or canonical', $counts['all']['values'], $counts['all']['pages'], $counts['values']['values'] ) : 'nothing found' ); ?></span>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">What to remove</th>
				<td>
					<label style="display:block;margin-bottom:4px;"><input type="radio" name="scope" value="all" checked> Everything it stored on pages <span class="description">&mdash; including focus keyphrases, social settings and the rest</span></label>
					<label style="display:block;"><input type="radio" name="scope" value="values"> Only the title, description, noindex and canonical</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Confirm</th>
				<td><label><input type="checkbox" name="confirm" value="1" required> I have downloaded the backup and understand this cannot be undone</label></td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" class="button" name="action" value="lcp_yeast_seo_export_backup" formnovalidate>Download backup (CSV)</button>
			<button type="submit" class="button button-primary lcp-ys-danger" name="action" value="lcp_yeast_seo_clear_legacy">Remove the data</button>
		</p>
	</form>
		</div>
	</section>
	<?php endif; ?>
	<?php
}
