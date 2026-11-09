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
		),
		'yoast'    => array(
			'label'       => 'Yoast SEO',
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
		),
		'rankmath' => array(
			'label'       => 'Rank Math',
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
		),
		'seopress' => array(
			'label'       => 'SEOPress',
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
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

	if ( ! isset( $mapped['meta_title'] ) && ! isset( $mapped['meta_description'] ) ) {
		return new WP_Error( 'lcp_missing_meta_headers', 'Include at least one Meta Title or Meta Description column.' );
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

	foreach ( array( 'url', 'meta_title', 'meta_description' ) as $field ) {
		$out[ $field ] = isset( $mapped[ $field ], $row[ $mapped[ $field ] ] ) ? (string) $row[ $mapped[ $field ] ] : '';
	}

	return $out;
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
 * @return array
 */
function lcp_yeast_seo_ie_result_row( $row, $url, $status, $message, $title = '', $desc = '', $matched = '' ) {
	return array(
		'row'              => $row,
		'url'              => $url,
		'status'           => $status,
		'message'          => $message,
		'meta_title'       => $title,
		'meta_description' => $desc,
		'matched_url'      => $matched,
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
function lcp_yeast_seo_ie_redirect( $success, $message, array $rows = array(), array $counts = array() ) {
	set_transient(
		'lcp_yeast_seo_ie_result_' . get_current_user_id(),
		array(
			'success' => $success,
			'message' => $message,
			'rows'    => $rows,
			'counts'  => $counts,
		),
		10 * MINUTE_IN_SECONDS
	);

	wp_safe_redirect( add_query_arg( array( 'page' => 'lcp-yeast-seo', 'tab' => 'import-export' ), admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Applies a title and/or description to a post, or reports what it would do.
 *
 * @param int    $post_id   Post ID.
 * @param string $title     New title ('' to leave alone).
 * @param string $desc      New description ('' to leave alone).
 * @param bool   $dry_run   Only report.
 * @param bool   $overwrite Replace existing values.
 * @return string[] status, message.
 */
function lcp_yeast_seo_ie_apply( $post_id, $title, $desc, $dry_run, $overwrite ) {
	$changes = array();
	$notes   = array();
	$writes  = array();

	foreach ( array(
		array( LCP_YEAST_SEO_TITLE_META_KEY, $title, 'Title', 'sanitize_text_field' ),
		array( LCP_YEAST_SEO_DESCRIPTION_META_KEY, $desc, 'Description', 'sanitize_textarea_field' ),
	) as $field ) {
		list( $key, $value, $label, $sanitize ) = $field;

		if ( '' === $value ) {
			continue;
		}

		if ( ! $overwrite && '' !== trim( (string) get_post_meta( $post_id, $key, true ) ) ) {
			$notes[] = $label . ' already set, kept.';
			continue;
		}

		$writes[ $key ] = call_user_func( $sanitize, $value );
		$changes[]      = $label . ' => "' . $value . '"';

		if ( lcp_yeast_seo_ie_has_variables( $value ) ) {
			$notes[] = $label . ' still contains a template variable.';
		}
	}

	if ( ! $writes ) {
		// Values the page already has are kept on purpose, which isn't a failure to match.
		return array( $notes ? 'Unchanged' : 'Skipped', implode( ' ', $notes ) ?: 'Nothing to update.' );
	}

	if ( ! $dry_run ) {
		foreach ( $writes as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		clean_post_cache( $post_id );
	}

	return array( $dry_run ? 'Dry run' : 'Updated', trim( implode( '; ', $changes ) . ' ' . implode( ' ', $notes ) ) );
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
	$dry_run      = ! empty( $_POST['dry_run'] );
	$overwrite    = ! empty( $_POST['overwrite'] );

	if ( ! $update_title && ! $update_desc ) {
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

		if ( '' === $url ) {
			$rows[] = lcp_yeast_seo_ie_result_row( $number, $url, 'Skipped', 'Missing URL.', $title, $desc );
			continue;
		}

		$match = lcp_yeast_seo_ie_resolve_post( $url );

		if ( $match['error'] || ! $match['post_id'] ) {
			$rows[] = lcp_yeast_seo_ie_result_row( $number, $url, 'Skipped', $match['error'] ?: 'No matching page or post found for this URL.', $title, $desc );
			continue;
		}

		list( $status, $message ) = lcp_yeast_seo_ie_apply( $match['post_id'], $title, $desc, $dry_run, $overwrite );

		$rows[] = lcp_yeast_seo_ie_result_row( $number, $url, $status, $message, $title, $desc, lcp_yeast_seo_ie_relative_url( $match['post_id'] ) );
	}

	$counts = lcp_yeast_seo_ie_count_statuses( $rows );

	lcp_yeast_seo_ie_redirect(
		true,
		$dry_run ? sprintf( 'Dry run complete. Reviewed %d row(s); %d would change.', count( $rows ), $counts['Dry run'] ) : sprintf( 'Import complete. Updated %d of %d row(s).', $counts['Updated'], count( $rows ) ),
		$rows,
		$counts
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

		if ( $only && '' === $title && '' === $desc ) {
			continue;
		}

		$lines[] = array( lcp_yeast_seo_ie_relative_url( $post->ID ), $title, $desc );
	}

	if ( ! $lines ) {
		lcp_yeast_seo_ie_redirect( false, 'No titles or descriptions found to export for the selected post types.' );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seo-export-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fputcsv( $out, array( 'URL', 'Meta Title', 'Meta Description' ), ',', '"', '\\' );

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
	fputcsv( $out, array( 'URL', 'Meta Title', 'Meta Description' ), ',', '"', '\\' );

	foreach ( $skipped as $row ) {
		fputcsv( $out, array( $row['url'], $row['meta_title'], $row['meta_description'] ), ',', '"', '\\' );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_export_skipped', 'lcp_yeast_seo_ie_handle_export_skipped' );

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

	if ( ! $types ) {
		lcp_yeast_seo_ie_redirect( false, 'Select at least one post type.' );
	}

	$rows = array();

	foreach ( lcp_yeast_seo_ie_get_posts( $types ) as $post ) {
		list( $title, $desc ) = lcp_yeast_seo_ie_read_post( $post, $engine );

		if ( '' === $title && '' === $desc ) {
			continue;
		}

		list( $status, $message ) = lcp_yeast_seo_ie_apply( $post->ID, $title, $desc, $dry_run, ! empty( $_POST['overwrite'] ) );

		$rows[] = lcp_yeast_seo_ie_result_row( count( $rows ) + 1, lcp_yeast_seo_ie_relative_url( $post->ID ), $status, $message, $title, $desc, lcp_yeast_seo_ie_relative_url( $post->ID ) );
	}

	$counts = lcp_yeast_seo_ie_count_statuses( $rows );

	lcp_yeast_seo_ie_redirect(
		true,
		sprintf(
			'%s: %d page(s) with %s data. %s',
			$dry_run ? 'Dry run' : 'Migration complete',
			count( $rows ),
			$engines[ $engine ]['label'],
			$dry_run ? sprintf( '%d would change.', $counts['Dry run'] ) : sprintf( '%d updated.', $counts['Updated'] )
		),
		$rows,
		$counts
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

	$keys = lcp_yeast_seo_ie_engines()[ $engine ];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value <> ''",
			$keys['title'],
			$keys['description']
		)
	);
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
 * Renders the last operation's outcome.
 *
 * @return void
 */
function lcp_yeast_seo_ie_render_result() {
	$key    = 'lcp_yeast_seo_ie_result_' . get_current_user_id();
	$result = get_transient( $key );

	if ( ! is_array( $result ) ) {
		return;
	}

	$counts  = $result['counts'] ?? array();
	$skipped = (int) ( $counts['Skipped'] ?? 0 );
	$rows    = $result['rows'] ?? array();
	?>
	<div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?>">
		<p><?php echo esc_html( $result['message'] ); ?></p>
		<?php if ( $skipped ) : ?>
			<p style="color:#d63638;font-weight:600;"><?php echo esc_html( sprintf( '%d row(s) skipped. See the flagged rows below.', $skipped ) ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( $rows ) : ?>
		<table class="widefat striped" style="max-width:1200px;margin:1rem 0;">
			<thead>
				<tr><th>Row</th><th>Source URL</th><th>Matched URL</th><th>Status</th><th>Details</th></tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $rows, 0, LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN ) as $row ) : ?>
					<tr<?php echo 'Skipped' === $row['status'] ? ' style="background:#fcf0f1;"' : ''; ?>>
						<td><?php echo esc_html( (string) $row['row'] ); ?></td>
						<td style="word-break:break-all;"><?php echo esc_html( $row['url'] ); ?></td>
						<td style="word-break:break-all;"><?php echo '' !== $row['matched_url'] ? esc_html( $row['matched_url'] ) : '&mdash;'; ?></td>
						<td><?php echo 'Skipped' === $row['status'] ? '<strong style="color:#d63638;">Skipped</strong>' : esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( $row['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $rows ) > LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN ) : ?>
			<p class="description"><?php echo esc_html( sprintf( 'Showing the first %d of %d rows.', LCP_YEAST_SEO_IE_MAX_ROWS_SHOWN, count( $rows ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( $skipped ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lcp_yeast_seo_export_skipped">
				<?php wp_nonce_field( LCP_YEAST_SEO_IE_NONCE ); ?>
				<?php submit_button( sprintf( 'Download %d skipped row(s) as CSV', $skipped ), 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:8px;">Fix the URLs and re-upload. Same columns as import.</span>
			</form>
		<?php endif; ?>
	<?php endif; ?>
	<hr>
	<?php
	delete_transient( $key );
}

/**
 * Renders the Import / Export tab.
 *
 * @return void
 */
function lcp_yeast_seo_ie_render_tab() {
	$engines = lcp_yeast_seo_ie_engines();
	$default = lcp_yeast_seo_ie_default_engine();

	lcp_yeast_seo_ie_render_result();
	?>
	<h2>Export</h2>
	<p>Download a CSV of titles and descriptions. It uses the same <code>URL</code>, <code>Meta Title</code> and <code>Meta Description</code> columns as import, with site-relative URLs, so the file works on any copy of the site. Template variables such as <code>%%title%%</code> are filled in.</p>
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

	<hr>

	<h2>Import</h2>
	<p>Upload a CSV or XLSX with a header row of <code>URL</code>, <code>Meta Title</code> and/or <code>Meta Description</code>. Rows are matched to pages by URL, and ambiguous matches are skipped rather than guessed.</p>
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
					<label><input type="checkbox" name="update_description" value="1" checked> Meta Description</label>
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

	<hr>

	<h2>Migrate from another SEO plugin</h2>
	<p>Copies titles and descriptions straight from another plugin's data on this site, with its template variables filled in. Nothing is deleted from the other plugin.</p>
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
						$found = lcp_yeast_seo_ie_count_posts_with_data( $slug );
						?>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="engine" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $slug, $default ); ?> <?php disabled( 0 === $found ); ?>>
							<?php echo esc_html( $engine['label'] ); ?>
							<span class="description">&mdash; <?php echo esc_html( $found ? sprintf( '%d page(s) with data', $found ) : 'no data found' ); ?></span>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Post types</th>
				<td><?php lcp_yeast_seo_ie_render_type_checkboxes(); ?></td>
			</tr>
			<tr>
				<th scope="row">Options</th>
				<td>
					<label><input type="checkbox" name="overwrite" value="1"> Overwrite values a page already has</label><br>
					<label><input type="checkbox" name="dry_run" value="1" checked> Dry run: show what would change without saving</label>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Migrate', 'primary', 'submit', false ); ?>
	</form>
	<?php
}
