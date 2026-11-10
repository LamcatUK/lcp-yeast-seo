<?php
namespace Lcp\YeastSeo\Tests;

use Brain\Monkey\Functions;

class ImportExportTest extends TestCase {

	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file ); // phpcs:ignore
		}

		parent::tearDown();
	}

	private function temp_file( $contents, $suffix = '.csv' ) {
		$path = tempnam( sys_get_temp_dir(), 'lcp' ) . $suffix;

		file_put_contents( $path, $contents );
		$this->files[] = $path;

		return $path;
	}

	public function test_yoast_variables_are_resolved() {
		$vars = array(
			'title'    => 'About',
			'sitename' => 'Tidy',
			'sep'      => '-',
			'page'     => '',
		);

		$this->assertSame( 'About - Tidy', lcp_yeast_seo_ie_resolve_variables( '%%title%% %%page%% %%sep%% %%sitename%%', $vars ) );
	}

	public function test_rank_math_variables_are_resolved() {
		$this->assertSame( 'About | Tidy', lcp_yeast_seo_ie_resolve_variables( '%title% %sep% %sitename%', array( 'title' => 'About', 'sep' => '|', 'sitename' => 'Tidy' ) ) );
	}

	public function test_unknown_variables_and_plain_percentages_are_kept() {
		$this->assertSame( '%%mystery%% 50% off today', lcp_yeast_seo_ie_resolve_variables( '%%mystery%% 50% off today', array( 'title' => 'x' ) ) );
	}

	public function test_variable_detection() {
		$this->assertTrue( lcp_yeast_seo_ie_has_variables( 'Hello %%title%%' ) );
		$this->assertTrue( lcp_yeast_seo_ie_has_variables( 'Hello %sitename%' ) );
		$this->assertFalse( lcp_yeast_seo_ie_has_variables( 'Save 50% on everything' ) );
	}

	public function test_headers_accept_aliases_and_a_bom() {
		$headers = lcp_yeast_seo_ie_normalize_headers( array( "\xEF\xBB\xBFURL", ' Title ', 'Meta Desc' ) );

		$this->assertSame( array( 'url', 'title', 'meta desc' ), $headers );
		$this->assertSame(
			array( 'url' => 0, 'meta_title' => 1, 'meta_description' => 2 ),
			lcp_yeast_seo_ie_map_headers( $headers )
		);
	}

	public function test_headers_require_a_url_and_a_meta_column() {
		$this->assertInstanceOf( 'WP_Error', lcp_yeast_seo_ie_map_headers( array( 'title', 'description' ) ) );
		$this->assertInstanceOf( 'WP_Error', lcp_yeast_seo_ie_map_headers( array( 'url', 'notes' ) ) );
	}

	public function test_extract_row_tolerates_short_rows() {
		$row = lcp_yeast_seo_ie_extract_row( array( 'url' => 0, 'meta_title' => 1, 'meta_description' => 2 ), array( '/a/', 'Title' ) );

		$this->assertSame( array( 'url' => '/a/', 'meta_title' => 'Title', 'meta_description' => '', 'noindex' => '', 'canonical' => '' ), $row );
	}

	public function test_csv_with_bom_quotes_and_blank_lines() {
		$csv  = "\xEF\xBB\xBFURL,Meta Title,Meta Description\n";
		$csv .= "/about/,\"About, us\",\"Line one\"\n";
		$csv .= "\n";
		$csv .= "/contact/,Contact,\n";

		$rows = lcp_yeast_seo_ie_parse_csv( $this->temp_file( $csv ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'About, us', $rows[0]['meta_title'] );
		$this->assertSame( '/contact/', $rows[1]['url'] );
		$this->assertSame( '', $rows[1]['meta_description'] );
	}

	public function test_csv_without_a_url_column_is_rejected() {
		$result = lcp_yeast_seo_ie_parse_csv( $this->temp_file( "Title,Description\nA,B\n" ) );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_excel_column_letters() {
		$this->assertSame( 0, lcp_yeast_seo_ie_column_index( 'A1' ) );
		$this->assertSame( 25, lcp_yeast_seo_ie_column_index( 'Z9' ) );
		$this->assertSame( 26, lcp_yeast_seo_ie_column_index( 'AA2' ) );
	}

	public function test_xlsx_with_shared_and_inline_strings() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$path = $this->temp_file( '', '.xlsx' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::OVERWRITE );
		$zip->addFromString(
			'xl/sharedStrings.xml',
			'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>URL</t></si><si><t>Meta Title</t></si><si><t>/about/</t></si><si><t>About us</t></si></sst>'
		);
		$zip->addFromString(
			'xl/worksheets/sheet1.xml',
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
			. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
			. '<row r="3"><c r="A3" t="inlineStr"><is><t>/contact/</t></is></c><c r="B3" t="inlineStr"><is><t>Contact</t></is></c></row>'
			. '</sheetData></worksheet>'
		);
		$zip->close();

		$rows = lcp_yeast_seo_ie_parse_xlsx( $path );

		$this->assertSame(
			array(
				array( 'url' => '/about/', 'meta_title' => 'About us', 'meta_description' => '', 'noindex' => '', 'canonical' => '' ),
				array( 'url' => '/contact/', 'meta_title' => 'Contact', 'meta_description' => '', 'noindex' => '', 'canonical' => '' ),
			),
			$rows
		);
	}

	public function test_paths_are_normalised_for_matching() {
		$this->assertSame( '/about/', lcp_yeast_seo_ie_normalized_path( 'about/' ) );
		$this->assertSame( '/about/', lcp_yeast_seo_ie_normalized_path( 'https://example.test//about//?x=1' ) );
		$this->assertSame( '/', lcp_yeast_seo_ie_normalized_path( 'https://example.test' ) );
		$this->assertNull( lcp_yeast_seo_ie_normalized_path( '   ' ) );
	}

	public function test_candidate_paths_cover_with_and_without_a_trailing_slash() {
		$this->assertSame( array( '/a/b', '/a/b/' ), lcp_yeast_seo_ie_candidate_paths( '/a/b' ) );
		$this->assertSame( array( '/' ), lcp_yeast_seo_ie_candidate_paths( '/' ) );
	}

	public function test_status_counts() {
		$rows = array(
			array( 'status' => 'Updated' ),
			array( 'status' => 'Updated' ),
			array( 'status' => 'Skipped' ),
			array( 'status' => 'Unchanged' ),
		);

		$this->assertSame(
			array( 'Updated' => 2, 'Dry run' => 0, 'Unchanged' => 1, 'Skipped' => 1 ),
			lcp_yeast_seo_ie_count_statuses( $rows )
		);
	}

	public function test_each_plugins_noindex_value_is_recognised() {
		$this->assertTrue( lcp_yeast_seo_ie_is_noindex( 'yoast', '1' ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'yoast', '2' ), 'Yoast 2 means a forced index' );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'yoast', '0' ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'yoast', '' ) );

		$this->assertTrue( lcp_yeast_seo_ie_is_noindex( 'seopress', 'yes' ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'seopress', '' ) );

		$this->assertTrue( lcp_yeast_seo_ie_is_noindex( 'rankmath', array( 'index', 'noindex' ) ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'rankmath', array( 'index', 'nofollow' ) ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'rankmath', '' ) );

		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'something-else', '1' ) );
	}

	public function test_a_self_pointing_or_empty_canonical_is_not_copied() {
		$page = 'https://example.test/a/';

		$this->assertSame( 'https://other.test/x/', lcp_yeast_seo_ie_clean_canonical( ' https://other.test/x/ ', $page ) );
		$this->assertSame( '', lcp_yeast_seo_ie_clean_canonical( 'https://example.test/a', $page ), 'same page, trailing slash aside' );
		$this->assertSame( '', lcp_yeast_seo_ie_clean_canonical( 'https://example.test/a/', $page ) );
		$this->assertSame( '', lcp_yeast_seo_ie_clean_canonical( '  ', $page ) );
	}

	public function test_every_engine_names_a_robots_and_canonical_key() {
		foreach ( lcp_yeast_seo_ie_engines() as $slug => $engine ) {
			$this->assertNotSame( '', $engine['robots'] ?? '', "$slug robots key" );
			$this->assertNotSame( '', $engine['canonical'] ?? '', "$slug canonical key" );
		}
	}

	private function fake_meta( array $stored ) {
		$writes = new \ArrayObject();

		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( $stored ) {
				return $stored[ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( $writes ) {
				$writes[ $key ] = $value;
			}
		);
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		return $writes;
	}

	public function test_noindex_is_added_even_to_a_page_saved_as_index() {
		foreach ( array( 'index', '' ) as $existing ) {
			$writes = $this->fake_meta( array( LCP_YEAST_SEO_ROBOTS_META_KEY => $existing ) );

			list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, '', '', false, false, array( 'robots' => 'noindex' ) );

			$this->assertSame( 'Updated', $status );
			$this->assertSame( 'noindex', $writes[ LCP_YEAST_SEO_ROBOTS_META_KEY ] );
			$this->assertStringContainsString( 'Noindex', $message );
		}
	}

	public function test_an_existing_noindex_is_left_alone() {
		$writes = $this->fake_meta( array( LCP_YEAST_SEO_ROBOTS_META_KEY => 'noindex' ) );

		list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, '', '', false, true, array( 'robots' => 'noindex' ) );

		$this->assertSame( 'Unchanged', $status );
		$this->assertCount( 0, $writes );
		$this->assertStringContainsString( 'matches', $message );
	}

	public function test_noindex_is_never_written_unless_the_source_had_it() {
		$writes = $this->fake_meta( array() );

		list( $status ) = lcp_yeast_seo_ie_apply( 5, '', '', false, true, array( 'robots' => '', 'canonical' => '' ) );

		$this->assertSame( 'Skipped', $status );
		$this->assertCount( 0, $writes );
	}

	public function test_a_canonical_respects_the_overwrite_choice() {
		$writes = $this->fake_meta( array( LCP_YEAST_SEO_CANONICAL_META_KEY => 'https://mine.test/' ) );

		list( $kept ) = lcp_yeast_seo_ie_apply( 5, '', '', false, false, array( 'canonical' => 'https://yoast.test/' ) );
		$this->assertSame( 'Unchanged', $kept );
		$this->assertCount( 0, $writes );

		list( $replaced ) = lcp_yeast_seo_ie_apply( 5, '', '', false, true, array( 'canonical' => 'https://yoast.test/' ) );
		$this->assertSame( 'Updated', $replaced );
		$this->assertSame( 'https://yoast.test/', $writes[ LCP_YEAST_SEO_CANONICAL_META_KEY ] );
	}

	public function test_a_dry_run_reports_noindex_and_canonical_without_writing() {
		$writes = $this->fake_meta( array() );

		list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, 'T', '', true, false, array( 'robots' => 'noindex', 'canonical' => 'https://yoast.test/' ) );

		$this->assertSame( 'Dry run', $status );
		$this->assertCount( 0, $writes );
		$this->assertStringContainsString( 'Noindex', $message );
		$this->assertStringContainsString( 'Canonical: new "https://yoast.test/"', $message );
	}

	public function test_a_csv_import_with_no_extras_behaves_as_before() {
		$writes = $this->fake_meta( array() );

		list( $status ) = lcp_yeast_seo_ie_apply( 5, 'Title', 'Desc', false, false );

		$this->assertSame( 'Updated', $status );
		$this->assertSame( array( LCP_YEAST_SEO_TITLE_META_KEY, LCP_YEAST_SEO_DESCRIPTION_META_KEY ), array_keys( $writes->getArrayCopy() ) );
	}

	public function test_noindex_and_canonical_columns_are_recognised_by_their_aliases() {
		$mapped = lcp_yeast_seo_ie_map_headers( array( 'url', 'robots', 'canonical url' ) );

		$this->assertSame( array( 'url' => 0, 'noindex' => 1, 'canonical' => 2 ), $mapped );
		$this->assertSame( array( 'url' => 0, 'noindex' => 1 ), lcp_yeast_seo_ie_map_headers( array( 'url', 'noindex' ) ) );
		$this->assertInstanceOf( 'WP_Error', lcp_yeast_seo_ie_map_headers( array( 'url', 'notes' ) ), 'a URL alone is not enough' );
	}

	public function test_a_noindex_cell_is_read_as_noindex_index_or_no_instruction() {
		foreach ( array( 'noindex', 'NOINDEX', 'noindex, follow', ' yes ', 'TRUE', '1', 'x' ) as $cell ) {
			$this->assertSame( 'noindex', lcp_yeast_seo_ie_parse_robots_cell( $cell ), $cell );
		}

		foreach ( array( 'index', 'index, follow', 'no', 'false', '0' ) as $cell ) {
			$this->assertSame( 'index', lcp_yeast_seo_ie_parse_robots_cell( $cell ), $cell );
		}

		foreach ( array( '', '  ', 'maybe', 'follow' ) as $cell ) {
			$this->assertSame( '', lcp_yeast_seo_ie_parse_robots_cell( $cell ), "[$cell]" );
		}
	}

	public function test_yeast_noindex_is_recognised_for_export() {
		$this->assertTrue( lcp_yeast_seo_ie_is_noindex( 'yeast', 'noindex' ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'yeast', 'index' ) );
		$this->assertFalse( lcp_yeast_seo_ie_is_noindex( 'yeast', '' ) );
	}

	public function test_index_removes_a_noindex_only_when_overwriting() {
		$writes = $this->fake_meta( array( LCP_YEAST_SEO_ROBOTS_META_KEY => 'noindex' ) );

		list( $kept, $message ) = lcp_yeast_seo_ie_apply( 5, '', '', false, false, array( 'robots' => 'index' ) );
		$this->assertSame( 'Unchanged', $kept );
		$this->assertCount( 0, $writes );
		$this->assertStringContainsString( 'Noindex: kept', $message );

		list( $removed ) = lcp_yeast_seo_ie_apply( 5, '', '', false, true, array( 'robots' => 'index' ) );
		$this->assertSame( 'Updated', $removed );
		$this->assertSame( 'index', $writes[ LCP_YEAST_SEO_ROBOTS_META_KEY ] );
	}

	public function test_index_on_a_page_that_is_not_noindex_changes_nothing() {
		$writes = $this->fake_meta( array( LCP_YEAST_SEO_ROBOTS_META_KEY => 'index' ) );

		list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, '', '', false, true, array( 'robots' => 'index' ) );

		$this->assertSame( 'Unchanged', $status );
		$this->assertCount( 0, $writes );
		$this->assertStringContainsString( 'already indexed', $message );
	}

	public function test_a_skipped_row_carries_its_noindex_and_canonical_for_the_download() {
		$row = lcp_yeast_seo_ie_result_row( 3, '/x/', 'Skipped', 'No match', 'T', 'D', '', array( 'robots' => 'noindex', 'canonical' => 'https://a.test/' ) );

		$this->assertSame( 'noindex', $row['noindex'] );
		$this->assertSame( 'https://a.test/', $row['canonical'] );
		$this->assertSame( '', lcp_yeast_seo_ie_result_row( 1, '/y/', 'Skipped', '' )['noindex'] );
	}

	public function test_the_message_says_how_each_value_compares_with_the_current_one() {
		$this->fake_meta(
			array(
				LCP_YEAST_SEO_TITLE_META_KEY       => 'Same title',
				LCP_YEAST_SEO_DESCRIPTION_META_KEY => 'Old description',
			)
		);

		list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, 'Same title', 'New description', true, true, array( 'canonical' => 'https://c.test/' ) );

		$this->assertSame( 'Dry run', $status );
		$this->assertStringContainsString( 'Title: matches the current value.', $message );
		$this->assertStringContainsString( 'Description: "Old description" => "New description".', $message );
		$this->assertStringContainsString( 'Canonical: new "https://c.test/".', $message );
		$this->assertSame( 3, count( explode( "\n", $message ) ), 'one line per field' );
	}

	public function test_a_value_that_already_matches_is_not_written_again() {
		$writes = $this->fake_meta( array( LCP_YEAST_SEO_TITLE_META_KEY => 'Same title' ) );

		list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, 'Same title', '', false, true );

		$this->assertSame( 'Unchanged', $status );
		$this->assertCount( 0, $writes );
		$this->assertSame( 'Title: matches the current value.', $message );
	}

	public function test_a_kept_value_shows_both_the_current_and_the_file_value() {
		$this->fake_meta( array( LCP_YEAST_SEO_TITLE_META_KEY => 'Mine' ) );

		list( $status, $message ) = lcp_yeast_seo_ie_apply( 5, 'Theirs', '', true, false );

		$this->assertSame( 'Unchanged', $status );
		$this->assertStringContainsString( 'kept "Mine"', $message );
		$this->assertStringContainsString( 'the file has "Theirs"', $message );
	}

	public function test_long_values_are_shortened_in_the_message() {
		$long = str_repeat( 'word ', 60 );
		$this->fake_meta( array() );

		list( , $message ) = lcp_yeast_seo_ie_apply( 5, $long, '', true, false );

		$this->assertLessThan( 140, mb_strlen( $message ) );
		$this->assertStringContainsString( '...', $message );
	}

	public function test_apply_returns_a_structured_detail_for_each_field() {
		$this->fake_meta(
			array(
				LCP_YEAST_SEO_TITLE_META_KEY       => 'Same',
				LCP_YEAST_SEO_DESCRIPTION_META_KEY => 'Old description',
				LCP_YEAST_SEO_ROBOTS_META_KEY      => 'index',
			)
		);

		list( , , $details ) = lcp_yeast_seo_ie_apply( 5, 'Same', 'New description', true, true, array( 'canonical' => 'https://c.test/', 'robots' => 'noindex' ) );
		$by_field = array_column( $details, null, 'field' );

		$this->assertSame( array( 'Title', 'Description', 'Canonical', 'Noindex' ), array_keys( $by_field ) );
		$this->assertSame( 'matches', $by_field['Title']['state'] );
		$this->assertSame( 'changes', $by_field['Description']['state'] );
		$this->assertSame( 'Old description', $by_field['Description']['current'] );
		$this->assertSame( 'New description', $by_field['Description']['new'] );
		$this->assertSame( 'new', $by_field['Canonical']['state'] );
		$this->assertSame( 'on', $by_field['Noindex']['state'] );
		$this->assertSame( 'Indexed', $by_field['Noindex']['current'] );
		$this->assertSame( 'Noindex', $by_field['Noindex']['new'] );
	}

	public function test_a_kept_value_and_a_template_variable_are_flagged_in_the_details() {
		$this->fake_meta( array( LCP_YEAST_SEO_TITLE_META_KEY => 'Mine' ) );

		list( , , $kept ) = lcp_yeast_seo_ie_apply( 5, 'Theirs', '', true, false );
		$this->assertSame( 'kept', $kept[0]['state'] );

		list( , , $var ) = lcp_yeast_seo_ie_apply( 5, '', 'Hello %%unknown%%', true, true );
		$this->assertArrayHasKey( 'note', $var[0] );
	}

	public function test_status_and_state_chips_have_a_label_and_a_style() {
		foreach ( array( 'Dry run', 'Updated', 'Unchanged', 'Skipped' ) as $status ) {
			$chip = lcp_yeast_seo_ie_status_chip( $status );
			$this->assertNotSame( '', $chip[0] );
			$this->assertContains( $chip[1], array( 'ok', 'warn', 'error', 'neutral' ) );
			$this->assertContains( $chip[2], array( 'change', 'unchanged', 'skipped' ) );
		}

		foreach ( array( 'new', 'changes', 'matches', 'kept', 'on', 'off' ) as $state ) {
			$this->assertContains( lcp_yeast_seo_ie_state_chip( $state )[1], array( 'ok', 'warn', 'neutral' ) );
		}

		$this->assertSame( 'change', lcp_yeast_seo_ie_status_chip( 'Dry run' )[2], 'a dry run and an update are both changes' );
	}

	public function test_a_clear_covers_the_four_values_or_everything_by_prefix() {
		$yoast = lcp_yeast_seo_ie_engines()['yoast'];

		$values = lcp_yeast_seo_ie_legacy_scope( $yoast, 'values' );
		$this->assertSame( array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_canonical' ), $values['keys'] );
		$this->assertSame( '', $values['prefix'] );

		$all = lcp_yeast_seo_ie_legacy_scope( $yoast, 'all' );
		$this->assertSame( array(), $all['keys'] );
		$this->assertSame( '_yoast_wpseo_', $all['prefix'] );
	}

	public function test_every_other_plugin_has_its_own_prefix_and_this_plugin_has_none() {
		$engines = lcp_yeast_seo_ie_engines();

		$this->assertSame( 'rank_math_', lcp_yeast_seo_ie_legacy_scope( $engines['rankmath'], 'all' )['prefix'] );
		$this->assertSame( '_seopress_', lcp_yeast_seo_ie_legacy_scope( $engines['seopress'], 'all' )['prefix'] );
		$this->assertArrayNotHasKey( 'prefix', $engines['yeast'], 'this plugin is never offered for clearing' );
		$this->assertSame( '', lcp_yeast_seo_ie_legacy_scope( $engines['yeast'], 'all' )['prefix'], 'without a prefix, "all" never widens to a wildcard' );
	}

	public function test_a_prefix_never_matches_this_plugins_own_keys() {
		foreach ( array( 'yoast', 'rankmath', 'seopress' ) as $slug ) {
			$prefix = lcp_yeast_seo_ie_engines()[ $slug ]['prefix'];

			foreach ( array( LCP_YEAST_SEO_TITLE_META_KEY, LCP_YEAST_SEO_DESCRIPTION_META_KEY, LCP_YEAST_SEO_ROBOTS_META_KEY, LCP_YEAST_SEO_CANONICAL_META_KEY, LCP_YEAST_SEO_OG_IMAGE_META_KEY, LCP_YEAST_SEO_SCHEMA_META_KEY ) as $own ) {
				$this->assertStringStartsNotWith( $prefix, $own, "$slug prefix would delete $own" );
			}
		}
	}
}
