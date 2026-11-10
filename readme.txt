=== Yeast SEO ===
Tags: seo, schema, sitemap, meta description, open graph
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.20.1

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

= 0.20.1 =
* Fix: taxonomy sitemaps (such as `work_type-sitemap.xml`) were added automatically for every taxonomy the theme uses, with no way to switch one off. They are now opt-in: each taxonomy the theme uses appears as an "archive pages" tick under XML sitemaps, Included content, and is left out of the sitemap until ticked. A taxonomy whose archive is set to noindex is still never listed. Sites that had a taxonomy sitemap since 0.17.0 will lose it until it is ticked.

= 0.20.0 =
* New: an Organization wizard on the Schema tab. It works through six steps (business, brand, contact, where you work, hours, review), with every answer prefilled from your current Organization (from the form fields or the pasted JSON-LD), so you change only what you want to. You can change or clear values, tick "I work from home" to drop the street address and map position, and choose to keep, replace or remove the opening hours. The last step previews the JSON-LD and the score before and after, and nothing is saved until you press Save. A saved result goes into the JSON-LD source (carrying your form details across if you used the form fields) and an Undo link restores what you had. Anything you did not change is left exactly as it was, including a logo's size and a contact point's extra details. Enter in a field moves on rather than saving.

= 0.19.0 =
* New: a live JSON-LD checker under the schema box in the page modal, the classic editor box and the Organization box on the Schema tab. It reports problems as you type, with the line and column for broken JSON: curly quotes, trailing commas, a pasted `<script>` wrapper, single quotes and comments are explained in plain words. It checks the structure, not the whole vocabulary: a missing `@context` or `@type`, misspelt types ("Did you mean LocalBusiness?"), required properties for common types (Service, LocalBusiness, FAQPage, articles, events and more), ids defined twice, references to ids not in the block (advice only), empty values, addresses that aren't full web addresses, unreadable dates and placeholder text.
* Change: the organisation score no longer penalises a service-area business for having no street address (a country is enough, and map coordinates aren't scored), two profiles are enough for the profiles check, and the legal name is no longer scored.

= 0.18.0 =
* New: Organization schema score on the Schema tab. It reads your Organization (from the form or the pasted JSON-LD, following references inside a graph), scores it out of 100 across Identity, Brand, Contact, Local details and Trust, lists what is missing with the points each is worth, and gives advice that fits the source you use, with a snippet to paste for JSON-LD. Local-business checks (opening hours, map position, area served, price range) only count for local business types.
* New: the Health scan now also checks image alt text (an image with no alt attribute, or an alt that is only a file name; empty alt is accepted as decorative), broken links (checked after the page scan, cached, from a stored list only), and orphan pages (no other scanned page links to them). Each page row gets a third "Content" mark, and there is a "Re-check all links" button. Filter: `lcp_yeast_seo_health_max_links`.
* Groundwork (no screen yet): the merge logic for a question-and-answer that will fill in the missing Organization details.

= 0.17.0 =
* New: SEO for category, tag and other taxonomy pages, author pages, date pages, search results and post-type archives. Only the archives the active theme actually uses are offered. A theme counts as using one if its templates link to it (the_category, get_the_term_list, archive and author blocks and so on), it has a template for it, it is in a menu, or the theme has a general archive template; WooCommerce themes use the product taxonomies. The Archives card on the General tab lists what was found and why, tucks the rest into a collapsed section, and has a "Scan the theme again" link. Filter: `lcp_yeast_seo_archive_in_use`.
* New: per-archive title templates (with `%term%`, `%author%`, `%date%`, `%search%` or `%archive%`, plus `%sitename%`, `%tagline%` and `%sep%`, a live preview, and the page number added on later pages), a noindex choice, and a meta description for post-type archives.
* New: title, description, noindex / index, canonical and social image fields on term edit screens and on author profiles, shown only for taxonomies and authors the theme uses. Empty fields fall back to the archive settings, then to the term's own description or the author's biography.
* New: "Ask search engines not to index archives this theme doesn't use", for pages that exist at their addresses but are never linked to. Off by default; recommended.
* New: taxonomy sitemaps (`category-sitemap.xml` and so on) for the taxonomies the theme uses, listing terms that have posts and are not noindex. Marking an archive noindex removes its sitemap.
* Archive pages now print a meta description and Open Graph and Twitter tags, like posts do.

= 0.16.1 =
* New: a separate "Clear data left by another SEO plugin" panel on the Import / Export tab, for when you have migrated what you want and want the rest gone. Choose the plugin (Yoast SEO, Rank Math or SEOPress) and either everything it stored on pages (found by its key prefix, so focus keyphrases and social settings go too) or just the title, description, noindex and canonical. "Download backup (CSV)" gives you every value that would be removed, and removal needs a confirming tick, because it cannot be undone. Yeast SEO's own data can never be matched, and the other plugin's own settings and database tables are not touched. When nothing is left the panel hides.
* Change: the tick-box that 0.16.0 added to the Migrate form is removed. It only removed values that were already copied, which left anything you chose not to migrate behind.

= 0.15.2 =
* Change: the results of an import, migration or dry run are redesigned. Each page is its own block with a status chip, one URL, and a line per field showing New, Changes, Matches, Kept, Turned on or Turned off. A change shows the current value and the new one stacked, so you can see exactly what would happen. A summary shows how many would change, are unchanged or were skipped, with filter buttons to look at just one group. Skipped rows show their reason and can still be downloaded to fix and upload again.

= 0.15.1 =
* Change: import and migration results now compare every value with what the page has now. Each field gets its own line: it matches the current value, is new (nothing there yet), changes from "old" to "new", or is kept because overwriting is off. A value that already matches is no longer rewritten, so those rows show as Unchanged and the dry-run summary counts how many already match or would be kept.

= 0.15.0 =
* New: migrating from Yoast SEO, Rank Math or SEOPress now also copies each page's noindex setting and canonical URL, not just the title and description. A "Copy" choice lets you pick which. A canonical that only points back at the page itself is skipped. Noindex is only ever added (index is the default), and a page that was saved as "index" still receives it, so a noindex is not lost in the move. The dry run reports both. Nothing is deleted from the other plugin.
* New: the CSV / XLSX import and export now carry `Noindex` and `Canonical` columns as well as title and description. In the Noindex column, `noindex` (or yes / true / 1) turns noindex on; `index` (or no / false / 0) turns it off, but only when "Overwrite" is ticked. A blank cell leaves the page alone, so a canonical cannot be cleared from a spreadsheet. A canonical that points at the page itself is skipped. The skipped-rows download includes the new columns.
* Change: the Migrate panel on the Import / Export tab is hidden unless Yoast SEO, Rank Math or SEOPress data is found on the site, and the first source that has data is pre-selected.

= 0.14.0 =
* Change: the General tab is reorganised and tidied. Settings rows now share one left edge with the card headings, with the label on the left and the control and its help on the right (stacked on a phone). Sections are renamed by what they do: Search listings, Social sharing and XML sitemaps.
* New: title templates show a live preview of the title they produce, and the tags are buttons that insert themselves at the cursor with spacing. The separator has quick-pick buttons for the common characters.
* New: sitemap post types are pills showing how many items each contains, the sitemap link sits beside the on/off switch, and the options under a switched-off sitemap dim.
* No setting names or saved values change; saving the form writes exactly the same options.

= 0.13.3 =
* Change: the per-page Open Graph and Twitter/X images in the Yeast SEO modal are now picker-first: a preview with Choose / Change image and Remove, instead of a URL box. The classic-editor meta box gets the same media-library picker.
* New: when a page has no image of its own, the field shows the image that will actually be used (the featured image, or the site default) and says so, and the modal's link preview now uses the featured image too.
* Fix: the help text said the social image falls back straight to the site default. The real order is the page's own image, the featured image, the first image in the content, then the site default.

= 0.13.2 =
* Change: the default social image is now chosen from the media library (with upload) instead of typing a URL. It shows a preview and has Change and Remove buttons. An image already saved as a URL is kept and shown.

= 0.13.1 =
* New: "Re-check pages with warnings" beside "Re-check pages with errors" on the Health tab. Each button shows how many pages it covers and hides itself when there are none.

= 0.13.0 =
* New: bulk actions on the Posts, Pages and other list tables. Select items and choose "Yeast SEO: set to noindex" or "Yeast SEO: set to index" from Bulk actions. It sets the same Robots value as the Yeast SEO modal, so sitemaps, the robots meta tag and the Health scan follow it. Items that already have that value are left alone, items you can't edit are skipped, and a notice reports the counts. If the whole site is set to discourage search engines, setting items to index says they will still output noindex.

= 0.12.6 =
* New: click an entry under "Fix these first" to list just the pages with that problem, with a bar saying what is shown and a button to show all pages again.
* New: an "Errors" filter beside All, Needs attention and Healthy on the Health tab.

= 0.12.5 =
* Fix: the Health scan no longer follows a page's redirect to another site. A page that redirects off-site (a Shop page sent to a booking service, for example) is reported as "redirects to ...", with a note that it shouldn't be in the sitemap, instead of failing with a connection or TLS error from the other site. Redirects within the site are still followed.

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
