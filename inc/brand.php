<?php
/**
 * The brand mark: the starter jar, as inline SVG for the admin header and as a
 * one-colour icon for the WordPress admin menu.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The jar's shapes in the 160 x 172 grid the brand files use.
 *
 * @return array{lid:string,wall:string,liquid:string,bubbles:string[]}
 */
function lcp_yeast_seo_brand_shapes() {
	return array(
		'lid'     => 'M41 12H119A7 7 0 0 1 126 19A7 7 0 0 1 119 26H41A7 7 0 0 1 34 19A7 7 0 0 1 41 12Z',
		// One cut-out shape: the outer wall and the inside, drawn with the even-odd rule.
		'wall'    => 'M50 34H110A30 30 0 0 1 140 64V130A30 30 0 0 1 110 160H50A30 30 0 0 1 20 130V64A30 30 0 0 1 50 34ZM52 44A22 22 0 0 0 30 66V128A22 22 0 0 0 52 150H108A22 22 0 0 0 130 128V66A22 22 0 0 0 108 44Z',
		'liquid'  => 'M30 101C45 92 58 93 72 99C88 106 103 94 116 96C122 97 127 99 130 101V128Q130 150 108 150H52Q30 150 30 128Z',
		'bubbles' => array( 'M75 77.8A5.2 5.2 0 1 1 75 88.2A5.2 5.2 0 1 1 75 77.8Z', 'M91 64.8A4.2 4.2 0 1 1 91 73.2A4.2 4.2 0 1 1 91 64.8Z' ),
	);
}

/**
 * The mark as inline SVG.
 *
 * @param string $liquid Colour of the starter and bubbles.
 * @param string $body   Colour of the lid and jar wall.
 * @param string $class  Class for the svg element.
 * @return string
 */
function lcp_yeast_seo_brand_mark( $liquid = '#d9a21b', $body = '#ffffff', $class = 'lcp-ys-mark' ) {
	$shape = lcp_yeast_seo_brand_shapes();

	return sprintf(
		'<svg class="%1$s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 172" aria-hidden="true" focusable="false"><path fill="%3$s" d="%4$s"/><path fill="%3$s" fill-rule="evenodd" d="%5$s"/><path fill="%2$s" d="%6$s"/><path fill="%2$s" d="%7$s"/><path fill="%2$s" d="%8$s"/></svg>',
		esc_attr( $class ),
		esc_attr( $liquid ),
		esc_attr( $body ),
		esc_attr( $shape['lid'] ),
		esc_attr( $shape['wall'] ),
		esc_attr( $shape['liquid'] ),
		esc_attr( $shape['bubbles'][0] ),
		esc_attr( $shape['bubbles'][1] )
	);
}

/**
 * The admin menu icon: fills only and a single colour, so WordPress can repaint it
 * for the user's admin colour scheme.
 *
 * @return string A data URI for add_menu_page().
 */
function lcp_yeast_seo_menu_icon() {
	$shape = lcp_yeast_seo_brand_shapes();
	$svg   = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="-6 0 172 172"><g fill="black"><path d="%1$s"/><path fill-rule="evenodd" d="%2$s"/><path d="%3$s"/><path d="%4$s"/><path d="%5$s"/></g></svg>',
		$shape['lid'],
		$shape['wall'],
		$shape['liquid'],
		$shape['bubbles'][0],
		$shape['bubbles'][1]
	);

	return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the documented way to pass an SVG menu icon.
}
