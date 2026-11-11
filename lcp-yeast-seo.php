<?php
/**
 * Plugin Name: Yeast SEO
 * Description: Per-post SEO controls for title, meta description, and JSON-LD schema.
 * Version: 0.23.0
 * Requires PHP: 7.4
 * Author: Lamcat - DS
 * Text Domain: lcp-yeast-seo
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCP_YEAST_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'LCP_YEAST_SEO_VERSION', '0.23.0' );

require_once LCP_YEAST_SEO_PATH . 'inc/brand.php';
require_once LCP_YEAST_SEO_PATH . 'inc/settings.php';
require_once LCP_YEAST_SEO_PATH . 'inc/post-meta.php';
require_once LCP_YEAST_SEO_PATH . 'inc/templates.php';
require_once LCP_YEAST_SEO_PATH . 'inc/admin-columns.php';
require_once LCP_YEAST_SEO_PATH . 'inc/bulk-actions.php';
require_once LCP_YEAST_SEO_PATH . 'inc/archives.php';
require_once LCP_YEAST_SEO_PATH . 'inc/archive-fields.php';
require_once LCP_YEAST_SEO_PATH . 'inc/org-score.php';
require_once LCP_YEAST_SEO_PATH . 'inc/org-qa.php';
require_once LCP_YEAST_SEO_PATH . 'inc/org-wizard.php';
require_once LCP_YEAST_SEO_PATH . 'inc/sitemaps.php';
require_once LCP_YEAST_SEO_PATH . 'inc/schema.php';
require_once LCP_YEAST_SEO_PATH . 'inc/auto-schema.php';
require_once LCP_YEAST_SEO_PATH . 'inc/import-export.php';
require_once LCP_YEAST_SEO_PATH . 'inc/editor-checks.php';
require_once LCP_YEAST_SEO_PATH . 'inc/robots.php';
require_once LCP_YEAST_SEO_PATH . 'inc/health.php';
require_once LCP_YEAST_SEO_PATH . 'inc/health-content.php';
require_once LCP_YEAST_SEO_PATH . 'inc/admin-ui.php';
require_once LCP_YEAST_SEO_PATH . 'inc/dashboard-widget.php';
require_once LCP_YEAST_SEO_PATH . 'inc/about.php';

register_activation_hook( __FILE__, 'lcp_yeast_seo_activate' );
register_deactivation_hook( __FILE__, 'lcp_yeast_seo_deactivate' );
