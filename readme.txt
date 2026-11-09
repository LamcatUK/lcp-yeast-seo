=== Yeast SEO ===
Tags: seo, schema, sitemap, meta description, open graph
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.12.4

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

    LCP_SMOKE_URL=https://example.com LCP_SMOKE_ARCHIVE=/guides/ composer test:smoke

== Changelog ==

= 0.12.4 =
* New: "Re-check pages with errors" on the Health tab runs the scan again for just the pages currently showing errors, such as ones that timed out or were fixed since. The button shows how many, and hides itself when there are none.

= 0.12.3 =
* Fix: the Health scan no longer hangs on a site whose server can't connect to its own public address (for example behind a CDN or firewall that blocks the host's IP, which showed as `cURL error 28: Failed to connect … port 443`). When the normal fetch can't connect, the scan retries through the server's own IP while keeping the site's hostname, and remembers the route that worked for an hour.
* Fix: a slow or unresponsive page no longer stalls the scan. Each request stops starting new pages after 20 seconds, the browser times out and retries a slow batch one page at a time, and a page that still won't answer is skipped and reported at the end instead of stopping the scan.
* Filter: `lcp_yeast_seo_health_request_args` changes the scan's request timeout, user agent or headers.

= 0.12.2 =
* Health: references to the site's own Organization or WebSite (`/#organization`, `/#website`) are no longer reported as undefined on pages that don't print them. Those two entities belong on the home page, and other pages pointing at them by `@id` is a normal pattern. Any other undefined reference is still reported.

= 0.12.1 =
* Fix: on a theme that marks up the site as a WebSite itself (microdata such as `itemtype="http://schema.org/WebSite"` on the body), the plugin stood down from printing its own WebSite but still pointed each page's `isPartOf` at `#website`, so the reference matched nothing. It now only adds that reference when it prints the WebSite itself.

= 0.12.0 =
* New: the Health tab now checks each page's search listing as well as its schema. Per page it reports a missing or empty title or meta description (errors), titles over 60 or under 15 characters and descriptions over 160 or under 70 (warnings), a missing canonical link or one that points elsewhere, a missing or repeated H1, and missing `og:title` / `og:image`. The same title or description used on more than one page is an error. Pages marked noindex are only noted, since they aren't meant to appear in results.
* Each page row shows separate Schema and Meta marks, tags every problem with its area, and shows the title and description found with their lengths. The jar counts a page as healthy only when both pass.
* Pages scanned under 0.11.0 show Meta as "Not checked" until they are scanned again.

= 0.11.0 =
* New: Health tab. Scans every page in your sitemaps the way a visitor gets it (so schema printed by theme blocks is included) and reports pages with no structured data, invalid JSON-LD, a missing `@context` or `@type`, duplicate entities, `@id` references that point at nothing, and missing recommended properties on articles, breadcrumbs, FAQs and local businesses. Each problem comes with a plain-language fix, "Fix these first" ranks the problems that affect the most pages, and any page can be checked again on its own. A starter jar shows the share of healthy pages.
* New: refreshed admin design. A header band with live status chips and the tabs, settings sections as cards, a sticky save bar, and consistent styling on every tab.
* Filters: `lcp_yeast_seo_health_max_pages` (default 500).

= 0.10.0 =
* New: robots.txt editor on its own tab. Rules saved there replace WordPress's default robots.txt, and the sitemap index line is still added automatically.
* Safeguards: saving is blocked if the rules would hide the whole site from Google or Bing (`Disallow: /`) or exceed 500 KiB, unless "Save anyway" is ticked. Blocked CSS, JavaScript or images, typos in directives, rules before any User-agent line and relative Sitemap URLs are flagged as warnings.
* A physical robots.txt in the site root is detected (the web server serves it instead of WordPress). "Take over" copies its rules into the editor and renames the file with a timestamp, keeping it.
* Fix: the sitemap line is no longer added to robots.txt on sites set to discourage search engines (WordPress passes a boolean here in current versions, which the check didn't handle).
* Fix: the settings page now shows its save notices (including warnings such as an invalid Organization JSON-LD). WordPress only prints them automatically on its own Settings pages, so they were being silently dropped.
* After saving, a live check compares what the site really serves with the saved rules.

= 0.9.1 =
* The Schema column in admin lists shows a muted grey "A" for pages that have no schema of their own but get it generated automatically (the tooltip lists the types), instead of a red cross. Only exceptions use colour.

= 0.9.0 =
* New: Article author setting on the Schema tab: credit posts to the post's author (a Person, as before) or to the site as an Organization, for sites that publish under one name.
* Change: posts now get `BlogPosting` rather than `Article` by default (filter `lcp_yeast_seo_auto_schema_article_types` to change).
* New: per-kind switches on the Schema tab (WebSite, WebPage, Article/BlogPosting, BreadcrumbList, posts-page list). All on by default; each is still only added where the page doesn't already have it.
* The plugin now covers everything a theme typically prints by hand for posts and the posts-page index, so those can be removed from the theme. Breadcrumbs and FAQ schema printed by theme blocks are detected and left alone.

= 0.8.1 =
* Fix: automatic structured data no longer duplicates schema a theme or another plugin prints in the page body (for example a breadcrumbs block). The page output is scanned for JSON-LD and microdata, and only the types that are missing are added, inside `<head>`. Return false from `lcp_yeast_seo_auto_schema_scan_output` to skip the scan and print in `wp_head` as before.

= 0.8.0 =
* New: the posts page (and a front page that lists latest posts) now carries an `ItemList` of the posts it shows as the `CollectionPage`'s `mainEntity`, with positions that continue across pages, plus `about` pointing at the Organization when there is one. Posts set to noindex are left out. Paginated pages use their own URL.

= 0.7.1 =
* Fix: the canonical tag on archives and the posts page no longer echoes query-string parameters such as `utm_source`, `fbclid` or a cache-busting `v`, which made every tracked URL its own canonical. Only arguments WordPress itself uses (such as `paged`) are kept; filter: `lcp_yeast_seo_canonical_query_args`.

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
