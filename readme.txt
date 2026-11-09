=== Yeast SEO ===
Tags: seo, schema, sitemap, meta description, open graph
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0

Per-post SEO controls for titles, descriptions, social tags, canonical URLs and schema, with XML sitemaps. Built to replace Yoast one feature at a time.

== Description ==

* Per-post title, meta description, indexing, canonical URL, Open Graph and Twitter/X fields, edited in a modal in the block editor.
* Title templates and an automatic description for pages that have none of their own.
* Automatic structured data (WebSite, WebPage, Article, BreadcrumbList), plus hand-written JSON-LD per page and an Organization node.
* XML sitemaps with images, per-post-type inclusion, and a `robots.txt` Sitemap line.
* Import / Export tab: bulk titles and descriptions by CSV or XLSX, and one-step migration from Yoast, Rank Math or SEOPress.

== Installation ==

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate it.
3. Configure it under **Yeast SEO** in the admin menu.

== Testing ==

Unit tests need no WordPress or database:

    composer install
    composer test

After every deploy, run the smoke test against the live site. It checks the sitemaps are served directly (no redirects), are valid, and only use tags search engines accept:

    LCP_SMOKE_URL=https://example.com composer test:smoke

== Changelog ==

= 0.7.0 =
* New: title templates per post type (tags: %title%, %sitename%, %tagline%, %sep%) and a configurable separator. Empty templates keep WordPress's normal titles.
* New: automatic meta description from the excerpt or the start of the content, when a page has none. Open Graph and Twitter descriptions fall back to it.
* New: automatic structured data for pages and posts (WebSite, WebPage or CollectionPage, Article, ImageObject, BreadcrumbList). Types a page's own schema field already declares are not generated. Off when Yoast is active; switch off under Schema. Filter: `lcp_yeast_seo_auto_schema_nodes`.
* New: sitemaps list each page's lead image and content images (`image:image`, up to 10 per page; filter `lcp_yeast_seo_sitemap_max_images`).
* New: `robots.txt` gets a `Sitemap:` line for the index.
* New: Import / Export tab, ported from cbp-update-metadata. CSV and XLSX import by URL with dry run, CSV export from Yeast, Yoast, Rank Math or SEOPress with template variables resolved, and direct migration from those plugins.
* New: the editor modal quietly notes when another page uses the same title or description, and when no social image is set anywhere.
* Fix: the `<meta name="description">` tag no longer takes its value from the Open Graph description override.
* Dev: PHPUnit unit tests and a post-deploy sitemap smoke test.

= 0.6.0 =
* Settings page split into General and Schema tabs.
* Organization schema is raw JSON-LD only; the field editor is hidden behind the `lcp_yeast_seo_org_fields_ui` filter.
* Schema column in admin lists.
* Hide Yoast wording and Yoast-only checks when Yoast is not active.

= 0.5.2 =
* Fix: removed the custom `lcp:count` tag from the sitemap index, which Search Console rejected.

= 0.5.1 =
* Fix: sitemap files are no longer redirected to a trailing-slash URL, and a slashed request now redirects to the slashless one.

= 0.5.0 =
* Per-post canonical URL override.
* Sitemaps show a readable HTML view in browsers, replacing the XSL stylesheet.
* og:type is `website` on the front page; article pages output published and modified times.
* og:image falls back to the featured image, then the first block image.
* Over-long titles and descriptions are flagged in the list columns and the editor modal.

= 0.4.0 =
* Per-post fields apply to the posts page when it is shown as the blog index.

= 0.3.0 =
* Canonical tag for non-singular views (posts page and archives).

= 0.2.0 =
* Initial release: per-post title, description, robots, Open Graph and schema, in a block-editor modal.
