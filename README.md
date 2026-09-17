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
  **Meta description**, **Index/Noindex**, **Open Graph title**,
  **Open Graph description**, **Open Graph image**, **Twitter/X image**,
  and **Schema (JSON-LD)**, plus live search/social previews and simple
  character guidance.
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
- Outputs a `<link rel="canonical">` for non-singular views (the posts
  page, taxonomy/post-type/author archives) — WordPress core only ever
  outputs one for singular content, so anything else was left without a
  canonical entirely when Yoast isn't active to fill the gap.
- Generates a Yoast-style XML sitemap index at `sitemap_index.xml`, with
  separate post-type sitemap files including custom post types, and settings
  to enable/disable sitemap output per post type.
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

**Settings → LCP Yeast SEO** now covers:

- schema on/off,
- default social image,
- optional `twitter:site` handle,
- XML sitemap on/off,
- and per-post-type sitemap inclusion settings.

Turning schema off stops schema output and hides the schema field from the
editor UI, but never touches already-saved field content.

## Deliberately not included

- **FAQPage schema** — each theme handles its own FAQ block/accumulator
  differently, and not every theme installing this plugin will have one.
  Left in the theme, not centralised here.
- **Breadcrumb microdata** — out of scope for now.
- **Category/tag/term sitemaps** — deliberately omitted.

## Requirements

- WordPress with the block editor (native post meta + `add_meta_box()`).
- Yoast SEO active, for the Organization-suppression filter to have any
  effect — the plugin still registers the field and outputs schema without
  it, the filter hook is just a no-op if Yoast isn't there to call it.
