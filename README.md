# LCP Yeast SEO

A small, theme-agnostic WordPress plugin for per-post SEO controls, built to
sit alongside Yoast SEO rather than fight it. It started as a schema-only
port of `cb-hts2026`'s ACF-based `inc/cb-schema.php`, with ACF replaced by
native post meta, and now also handles page titles and meta descriptions.

The plan is still for this to grow into a fuller Yoast replacement over time,
one feature at a time.

## What it does

- Adds a **Yeast SEO** editor modal to every public post type (minus
  attachments) in the block editor, opened from the editor's More menu.
- Also adds a **Yeast SEO** button in the post/page settings sidebar that
  opens the same modal.
- The modal includes per-post fields for **Page title**,
  **Meta description**, **Index/Noindex**, **Canonical URL**,
  **Open Graph title**, **Open Graph description**, **Open Graph image**,
  **Twitter/X image**, and **Schema (JSON-LD)**, plus live search/social
  previews and simple character guidance.
- Falls back to a classic **Yeast SEO** meta box on non-block-editor screens.
- Accepts a single JSON object, a top-level array of objects, or a
  `@graph`-wrapped document. Leave it blank to fall back to Yoast's defaults
  entirely.
- Outputs the saved schema as a `<script type="application/ld+json">` tag in
  `wp_head`, after Yoast's own graph.
- Overrides the front-end document title when a custom page title is saved.
- Overrides Yoast's meta description output when a custom description is
  saved, and outputs a standard `<meta name="description">` tag itself when
  Yoast is not active.
- Overrides or outputs Open Graph, Twitter card, and robots meta using the
  same per-post fields, while still honouring WordPress's global
  **Discourage search engines from indexing this site** setting.
- Overrides the canonical link tag when a custom **Canonical URL** is saved
  — WordPress core's own `rel_canonical()`, Yoast's `wpseo_canonical`, and
  this plugin's own non-singular canonical fallback all respect it. Leave
  blank to keep the default (the page's own URL).
- Outputs a `<link rel="canonical">` for non-singular views (the posts
  page, taxonomy/post-type/author archives) — WordPress core only ever
  outputs one for singular content, so anything else was left without a
  canonical entirely when Yoast isn't active to fill the gap.
- Generates an XML sitemap index at `sitemap_index.xml`, with separate
  post-type sitemap files including custom post types, settings to
  enable/disable sitemap output per post type, and image entries
  (`image:image`) for each page's lead and content images. Sitemap URLs are
  served without a trailing slash, and the index is added to the virtual
  `robots.txt`.
- **Title templates**: a per-post-type pattern such as
  `%title% %sep% %sitename%` (tags `%title%`, `%sitename%`, `%tagline%`,
  `%sep%`) used where a page has no title of its own. Empty means
  WordPress's normal title. The front page is left alone.
- **Automatic description**: where a page has no meta description, its
  excerpt or the start of its content (about 160 characters, cut at a word)
  is used for the description and as the social description fallback.
- **Automatic structured data** (when Yoast is not active): a `@graph` of
  `WebSite`, `WebPage` (or `CollectionPage` for the posts page), `Article`
  for posts, the lead image and a `BreadcrumbList`. A type is skipped where
  the page's own schema field, or anything else on the page (a theme's
  breadcrumbs block, another plugin), already provides it: the finished page
  is scanned for JSON-LD and microdata before the graph is added to the
  `<head>`. Switch off under
  **Yeast SEO → Schema**. Filter: `lcp_yeast_seo_auto_schema_nodes`;
  `lcp_yeast_seo_auto_schema_article_types` maps post types to Article types
  (posts are `BlogPosting` by default). Posts can be credited to the post's
  author or to the site as an Organization (Schema tab).
- **Import / Export tab**: CSV or XLSX import of titles and descriptions by
  URL (with dry run), CSV export from Yeast, Yoast, Rank Math or SEOPress
  with their template variables resolved, and direct migration from those
  plugins. The CSV format (`URL`, `Meta Title`, `Meta Description`) matches
  the standalone cbp-update-metadata plugin.
- **robots.txt editor** (own tab): rules saved there replace WordPress's
  default file. Saving is blocked if they would hide the whole site from
  Google or Bing, or exceed 500 KiB, unless overridden; other likely mistakes
  are flagged. A physical `robots.txt` in the site root is detected, and
  "Take over" copies it into the editor and renames it (kept, timestamped).
  A live check after saving confirms what the site really serves.
- **Editor checks**: the modal quietly notes, under the field, when another
  page uses the same title or description, and when no social image is set.
  Nothing blocks saving.
- If the saved schema declares an `Organization` node, Yoast's own
  Organization node is suppressed on that page (via the
  `wpseo_schema_needs_organization` filter) so there's no duplicate —
  everywhere else, Yoast's node is left alone as the target for its own
  `publisher`/`about` references.
- Validates the saved value after every save (valid JSON, has `@context`
  and `@type`, an `Organization` node's `@id` ends in `#organization` so
  Yoast's cross-references resolve, no accidental duplication of types
  Yoast already emits) and shows a **non-blocking** admin notice if
  something looks off. Unlike the ACF source, this never blocks the save —
  there's no `acf/validate_value`-equivalent hook for post meta edited
  through the block editor's REST-backed meta box API, so validation here
  is advisory only.

## Settings

**Yeast SEO** in the admin menu has four tabs:

- **General**: title separator and templates, automatic description,
  default social image, `twitter:site` handle, XML sitemaps on/off,
  per-post-type sitemap inclusion and sitemap images.
- **Schema**: schema on/off, automatic structured data, and the
  Organization JSON-LD with its display rules.
- **robots.txt**: the editor above.
- **Import / Export**: see above.

Turning schema off stops schema output and hides the schema field from the
editor UI, but never touches already-saved field content.

## Deliberately not included

- **FAQPage schema** — each theme handles its own FAQ block/accumulator
  differently, and not every theme installing this plugin will have one.
  Left in the theme, not centralised here.
- **Breadcrumb microdata** — breadcrumb *schema* is generated, but the
  plugin does not render visible breadcrumbs.
- **Redirects** — use a dedicated plugin such as Redirection.
- **Category/tag/term sitemaps** — deliberately omitted.

## Requirements

- WordPress with the block editor (native post meta + `add_meta_box()`).
- Runs on its own. If Yoast SEO is active, Yoast's titles, descriptions and
  graph are left in charge (templates, automatic description and automatic
  structured data stand down), and the Organization-suppression filter
  applies.

## Testing

```
composer install
composer test                                   # unit tests, no WordPress or database
LCP_SMOKE_URL=https://example.com LCP_SMOKE_ARCHIVE=/guides/ composer test:smoke   # after every deploy
```

The smoke test fetches the live sitemaps and checks they are served directly
(no redirects), are valid XML, and only use tags search engines accept.

## Versioning

Bump the version in `lcp-yeast-seo.php` (header and `LCP_YEAST_SEO_VERSION`)
and add a `readme.txt` changelog entry with every significant change.
