<?php
namespace Lcp\YeastSeo\Tests;

class ArchivesTest extends TestCase {

	public function test_template_names_give_away_what_the_theme_renders() {
		$signals = lcp_yeast_seo_signals_from_templates( array( 'category.php', 'tag-news.php', 'taxonomy-area.php', 'archive-testimonial.php', 'author.html', 'date.php', 'search.php', 'single.php', 'index.php' ) );

		$this->assertEqualsCanonicalizing( array( 'tax:category', 'tax:post_tag', 'tax:area', 'pt:testimonial', 'author', 'date', 'search' ), $signals );
	}

	public function test_a_general_archive_template_is_a_generic_signal() {
		$this->assertSame( array( 'generic' ), lcp_yeast_seo_signals_from_templates( array( 'archive.php' ) ) );
		$this->assertSame( array( 'generic' ), lcp_yeast_seo_signals_from_templates( array( 'taxonomy.html' ) ) );
	}

	public function test_a_theme_with_only_the_basics_has_no_signals() {
		$this->assertSame( array(), lcp_yeast_seo_signals_from_templates( array( 'index.php', 'single.php', 'page.php', '404.php', 'header.php', 'footer.php', 'functions.php' ) ) );
	}

	public function test_links_to_categories_and_tags_are_recognised_in_source() {
		$this->assertContains( 'tax:category', lcp_yeast_seo_signals_from_source( '<?php the_category( ", " ); ?>' ) );
		$this->assertContains( 'tax:category', lcp_yeast_seo_signals_from_source( '<!-- wp:post-terms {"term":"category"} /-->' ) );
		$this->assertContains( 'tax:post_tag', lcp_yeast_seo_signals_from_source( '<?php echo get_the_tag_list(); ?>' ) );
		$this->assertContains( 'tax:post_tag', lcp_yeast_seo_signals_from_source( '<!-- wp:tag-cloud /-->' ) );
		$this->assertSame( array(), lcp_yeast_seo_signals_from_source( '<?php the_title(); the_content(); ?>' ) );
	}

	public function test_a_custom_taxonomy_needs_a_term_link_function_and_its_name() {
		$tax = array( 'category', 'area', 'service' );

		$this->assertContains( 'tax:area', lcp_yeast_seo_signals_from_source( "<?php echo get_the_term_list( get_the_ID(), 'area' ); ?>", $tax ) );
		$this->assertContains( 'tax:area', lcp_yeast_seo_signals_from_source( '<!-- wp:post-terms {"term":"area"} /-->', $tax ) );
		$this->assertNotContains( 'tax:service', lcp_yeast_seo_signals_from_source( "<?php echo get_the_term_list( get_the_ID(), 'area' ); ?>", $tax ), 'a different taxonomy is not implied' );
		$this->assertNotContains( 'tax:area', lcp_yeast_seo_signals_from_source( "<?php \$x = 'area'; ?>", $tax ), 'a name on its own is not a link' );
	}

	public function test_author_date_and_search_signals_in_source() {
		$this->assertContains( 'author', lcp_yeast_seo_signals_from_source( '<?php the_author_posts_link(); ?>' ) );
		$this->assertContains( 'author', lcp_yeast_seo_signals_from_source( '<!-- wp:post-author {"isLink":true} /-->' ) );
		$this->assertNotContains( 'author', lcp_yeast_seo_signals_from_source( '<!-- wp:post-author {"showAvatar":true} /-->' ), 'an author name that is not a link does not use the archive' );
		$this->assertContains( 'date', lcp_yeast_seo_signals_from_source( '<?php wp_get_archives(); ?>' ) );
		$this->assertContains( 'date', lcp_yeast_seo_signals_from_source( '<!-- wp:archives /-->' ) );
		$this->assertContains( 'search', lcp_yeast_seo_signals_from_source( '<?php get_search_form(); ?>' ) );
	}

	public function test_post_type_archive_links_name_their_post_type() {
		$this->assertContains( 'pt:testimonial', lcp_yeast_seo_signals_from_source( "<a href=\"<?php echo get_post_type_archive_link( 'testimonial' ); ?>\">" ) );
	}

	public function test_an_archive_is_in_use_when_the_theme_links_to_it_or_a_menu_does() {
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'tax:category', array( 'tax:category' ), array() ) );
		$this->assertStringContainsString( 'menu', lcp_yeast_seo_archive_reason( 'tax:area', array(), array( 'tax:area' ) ) );
		$this->assertSame( '', lcp_yeast_seo_archive_reason( 'tax:category', array( 'tax:post_tag', 'author' ), array() ), 'a different archive does not count' );
		$this->assertSame( '', lcp_yeast_seo_archive_reason( 'author', array(), array() ) );
	}

	public function test_a_general_archive_template_covers_archives_but_not_search() {
		$signals = array( 'generic' );

		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'tax:category', $signals, array() ) );
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'author', $signals, array() ) );
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'date', $signals, array() ) );
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'pt:event', $signals, array() ) );
		$this->assertSame( '', lcp_yeast_seo_archive_reason( 'search', $signals, array() ) );
	}

	public function test_a_term_specific_template_still_means_the_taxonomy_is_in_use() {
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'tax:product_cat', array( 'tax:product_cat-shoes' ), array() ) );
		$this->assertSame( '', lcp_yeast_seo_archive_reason( 'tax:product', array( 'tax:product_cat-shoes' ), array() ), 'only a hyphen boundary counts' );
	}

	public function test_woocommerce_themes_use_the_product_taxonomies() {
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'tax:product_cat', array(), array(), true ) );
		$this->assertNotSame( '', lcp_yeast_seo_archive_reason( 'tax:pa_colour', array(), array(), true ) );
		$this->assertSame( '', lcp_yeast_seo_archive_reason( 'tax:product_cat', array(), array(), false ) );
		$this->assertSame( '', lcp_yeast_seo_archive_reason( 'tax:category', array(), array(), true ), 'WooCommerce does not imply blog categories' );
	}

	public function test_an_archive_robots_decision_follows_its_own_choice_then_the_settings() {
		$this->assertSame( 'noindex', lcp_yeast_seo_decide_archive_robots( 'noindex', false, false, true ) );
		$this->assertSame( 'index', lcp_yeast_seo_decide_archive_robots( 'index', true, true, false ), 'a term that asks to be indexed overrides both settings' );
		$this->assertSame( 'noindex', lcp_yeast_seo_decide_archive_robots( '', true, false, true ), 'the archive setting' );
		$this->assertSame( 'noindex', lcp_yeast_seo_decide_archive_robots( '', false, true, false ), 'unused archives, when asked' );
		$this->assertSame( '', lcp_yeast_seo_decide_archive_robots( '', false, true, true ), 'a used archive is left alone' );
		$this->assertSame( '', lcp_yeast_seo_decide_archive_robots( '', false, false, false ), 'no opinion by default' );
		$this->assertSame( '', lcp_yeast_seo_decide_archive_robots( 'whatever', false, false, true ) );
	}

	public function test_an_archive_title_gets_its_page_number_on_later_pages() {
		$vars = array( 'term' => 'News', 'sitename' => 'Site', 'sep' => '|', 'tagline' => '' );

		$this->assertSame( 'News | Site', lcp_yeast_seo_archive_title_text( '%term% %sep% %sitename%', $vars, 1 ) );
		$this->assertSame( 'News | Site | Page 3', lcp_yeast_seo_archive_title_text( '%term% %sep% %sitename%', $vars, 3 ) );
		$this->assertSame( 'News Site Page 2', lcp_yeast_seo_archive_title_text( '%term% %sitename%', array( 'term' => 'News', 'sitename' => 'Site', 'sep' => '' ), 2 ) );
		$this->assertSame( '', lcp_yeast_seo_archive_title_text( '', $vars, 2 ), 'no template, no title, no page number' );
	}

	public function test_a_term_or_author_robots_choice_is_index_noindex_or_the_default() {
		$this->assertSame( 'index', lcp_yeast_seo_sanitize_archive_robots( 'index' ) );
		$this->assertSame( 'noindex', lcp_yeast_seo_sanitize_archive_robots( 'noindex' ) );
		$this->assertSame( '', lcp_yeast_seo_sanitize_archive_robots( '' ) );
		$this->assertSame( '', lcp_yeast_seo_sanitize_archive_robots( 'nofollow' ) );
	}

	public function test_archive_settings_are_cleaned_and_unlisted_archives_are_kept() {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'archives' => array( 'tax:old' => array( 'noindex' => '1', 'title' => 'Kept', 'description' => '' ) ) ) );
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		\Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );

		$out = lcp_yeast_seo_sanitize_archives(
			array(
				'tax:category'    => array( 'noindex' => '1', 'title' => ' %term% %sep% %sitename% ', 'description' => '' ),
				'date'            => array( 'noindex' => '0', 'title' => '', 'description' => '' ),
				'bad id'          => array( 'noindex' => '1' ),
				'tax:UPPER'       => array( 'noindex' => '1' ),
				'pt:event'        => 'not an array',
			)
		);

		$this->assertSame( array( 'tax:old', 'tax:category' ), array_keys( $out ), 'an archive the form did not list keeps its saved settings; invalid ids are dropped' );
		$this->assertSame( '%term% %sep% %sitename%', $out['tax:category']['title'] );
		$this->assertSame( '1', $out['tax:category']['noindex'] );
		$this->assertArrayNotHasKey( 'date', $out, 'an archive left at its defaults is not stored' );
	}

	public function test_a_taxonomy_is_left_out_of_the_sitemap_until_it_is_ticked() {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		$this->assertFalse( lcp_yeast_seo_sitemap_taxonomy_enabled( 'work_type' ), 'off by default, so an update never adds one' );

		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'sitemap_taxonomies' => array( 'work_type' => '1', 'category' => '0' ) ) );
		$this->assertTrue( lcp_yeast_seo_sitemap_taxonomy_enabled( 'work_type' ) );
		$this->assertFalse( lcp_yeast_seo_sitemap_taxonomy_enabled( 'category' ) );
		$this->assertFalse( lcp_yeast_seo_sitemap_taxonomy_enabled( 'other' ) );
	}

	public function test_the_sitemap_taxonomy_choice_keeps_taxonomies_the_form_did_not_list() {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'sitemap_taxonomies' => array( 'work_type' => '1' ) ) );

		$out = lcp_yeast_seo_sanitize_sitemap_taxonomies( array( 'category' => '1', 'work_type' => '0', 'bad name!' => '1' ) );

		$this->assertSame( array( 'work_type' => '0', 'category' => '1' ), $out );
	}

	public function test_clearing_an_archive_removes_its_saved_settings() {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'archives' => array( 'date' => array( 'noindex' => '1', 'title' => '', 'description' => '' ) ) ) );
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		\Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );

		$this->assertSame( array(), lcp_yeast_seo_sanitize_archives( array( 'date' => array( 'noindex' => '0', 'title' => '', 'description' => '' ) ) ) );
	}
}
