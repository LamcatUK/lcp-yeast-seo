<?php
namespace Lcp\YeastSeo\Tests;

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

		$this->assertSame( array( 'url' => '/a/', 'meta_title' => 'Title', 'meta_description' => '' ), $row );
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
				array( 'url' => '/about/', 'meta_title' => 'About us', 'meta_description' => '' ),
				array( 'url' => '/contact/', 'meta_title' => 'Contact', 'meta_description' => '' ),
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
}
