<?php
/**
 * Plugin Name: Yeast SEO
 * Description: Per-post SEO controls for title, meta description, and JSON-LD schema, with Yoast-aware overrides where needed.
 * Version: 0.4.0
 * Requires PHP: 7.4
 * Author: Lamcat - DS
 * Text Domain: lcp-yeast-seo
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCP_YEAST_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'LCP_YEAST_SEO_VERSION', '0.4.0' );

require_once LCP_YEAST_SEO_PATH . 'inc/settings.php';
require_once LCP_YEAST_SEO_PATH . 'inc/post-meta.php';
require_once LCP_YEAST_SEO_PATH . 'inc/admin-columns.php';
require_once LCP_YEAST_SEO_PATH . 'inc/sitemaps.php';
require_once LCP_YEAST_SEO_PATH . 'inc/schema.php';

register_activation_hook( __FILE__, 'lcp_yeast_seo_activate' );
register_deactivation_hook( __FILE__, 'lcp_yeast_seo_deactivate' );
