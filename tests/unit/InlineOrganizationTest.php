<?php
namespace Lcp\YeastSeo\Tests;

class InlineOrganizationTest extends TestCase {

	private const ID = 'https://example.test/#organization';

	private function link( array $node ) {
		return lcp_yeast_seo_link_inline_organization( $node, self::ID, array( 'Tidy Solutions' ), array( 'https://example.test/' ) );
	}

	public function test_an_inline_copy_of_the_business_becomes_a_reference() {
		$page = $this->link(
			array(
				'@type'      => 'ContactPage',
				'@id'        => 'https://example.test/contact/#contactpage',
				'mainEntity' => array( '@type' => 'LocalBusiness', 'name' => 'Tidy Solutions', 'telephone' => '01624 000000' ),
			)
		);

		$this->assertSame( array( '@id' => self::ID ), $page['mainEntity'] );
		$this->assertSame( 'ContactPage', $page['@type'] );
	}

	public function test_it_matches_by_address_ignoring_scheme_www_and_trailing_slash() {
		$page = $this->link( array( '@type' => 'WebPage', 'about' => array( '@type' => 'Organization', 'name' => 'Something else', 'url' => 'http://www.example.test' ) ) );

		$this->assertSame( array( '@id' => self::ID ), $page['about'] );
	}

	public function test_it_finds_copies_inside_lists_and_deeper_levels() {
		$page = $this->link(
			array(
				'@type' => 'WebPage',
				'a'     => array( array( '@type' => 'Thing' ), array( 'inner' => array( '@type' => 'LocalBusiness', 'name' => 'tidy solutions ' ) ) ),
			)
		);

		$this->assertSame( array( '@id' => self::ID ), $page['a'][1]['inner'] );
	}

	public function test_a_different_business_is_left_alone() {
		$other = array( '@type' => 'LocalBusiness', 'name' => 'Somebody Else', 'url' => 'https://other.test/' );

		$this->assertSame( $other, $this->link( array( '@type' => 'WebPage', 'about' => $other ) )['about'] );
	}

	public function test_a_node_that_already_has_an_id_is_left_alone() {
		$own = array( '@type' => 'LocalBusiness', '@id' => 'https://example.test/#branch', 'name' => 'Tidy Solutions' );

		$this->assertSame( $own, $this->link( array( '@type' => 'WebPage', 'about' => $own ) )['about'] );
	}

	public function test_only_business_types_are_replaced() {
		$person = array( '@type' => 'Person', 'name' => 'Tidy Solutions' );

		$this->assertSame( $person, $this->link( array( '@type' => 'WebPage', 'author' => $person ) )['author'] );
	}

	public function test_the_top_level_node_is_never_replaced() {
		$top = array( '@type' => 'LocalBusiness', 'name' => 'Tidy Solutions' );

		$this->assertSame( $top, $this->link( $top ) );
	}
}
