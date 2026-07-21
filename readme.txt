=== Hooked on Facets ===
Contributors: shepdesign
Plugin URI: https://hookedonfacets.com
Tags: facets, faceted search, filters, woocommerce, product filter
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultra-modern faceted search and filtering for WordPress + WooCommerce — sub-50ms filters on 100k+ products, with a no-code facet builder.

== Description ==

Hooked on Facets (HOF) is a faceted search and filtering plugin for WordPress and
WooCommerce, built for speed at scale and a builder-first admin experience. The
promise: **sub-50ms filter queries on 100k+ product catalogs**, paired with a
no-code admin where a non-developer can define and place facets without touching
template files.

**Performance is the brand.** Filters resolve through a narrow-EAV index served by
a MySQL `INTERSECT` engine with covering indexes — not live `WP_Query` meta lookups.
On a seeded 100k-product / 500k-row dataset, `resolve_ids()` runs at ~54ms p95 and a
full reindex completes in ~19s.

= Facet types (16) =

Checkbox, radio, dropdown, toggle, hierarchy, range slider, date range, search box,
fluid swatches, swipe deck, spin-the-wheel, intersection (Venn/UpSet) matrix, saved
comparison bin, AI natural-language ask, visual (color) DNA, and numbered pagination
— plus an active-filters chip strip.

= Resolver =

A MySQL `INTERSECT` engine with AND-across-facets and per-facet OR or AND
(`any` / `all`) matching, a version-invalidated result-set cache, and one grouped
counts query per facet.

= Custom-field sources =

One-click facet suggestions from WooCommerce, Advanced Custom Fields, Meta Box, and
Pods — only fields backed by real data are offered.

= Page-builder bridges =

Gutenberg, Elementor (Query ID), Bricks (CSS class), Breakdance (Array Query recipe),
and Divi.

= Also included =

SEO handling for filtered URLs (canonical + conditional noindex), an analytics
dashboard (facet usage, zero-result combinations, latency percentiles), a full CSS
custom-property theming UI, and multisite support.

== Installation ==

1. Upload the `hooked-on-facets` folder to `/wp-content/plugins/`, or install the ZIP
   via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Open **Hooked on Facets** in the admin menu, define your facets in the builder,
   and place them with the `[hof_facet name="..."]` shortcode, the Gutenberg block,
   or your page builder's binding.
4. Run the initial index from the **Indexer** screen (or `wp hof reindex`).

= Requirements =

* WordPress 6.4 or newer
* PHP 8.2 or newer
* MySQL 8.0.31 or newer (the resolver uses the `INTERSECT` operator)

== Frequently Asked Questions ==

= Why does it require MySQL 8.0.31+? =

The resolver builds an `INTERSECT` chain of per-facet index scans, which is what
delivers the sub-50ms performance at scale. The `INTERSECT` operator was added in
MySQL 8.0.31.

= Does the AI natural-language facet require a third-party service? =

Yes. The optional "ask" facet turns free text like "red shoes under $50" into filter
chips by calling the Anthropic API. It requires your own Anthropic API key, entered
on the AI Settings screen; each query counts against your Anthropic account credits.
Your customers' products and personal data are not sent — only the typed query and
your facet schema. Every other facet type works with no external service.

= Does it work without WooCommerce? =

Yes. WooCommerce unlocks the product-aware suggestions, but facets work against any
indexed post type, taxonomy, or custom field.

= How do I place a facet? =

Use the `[hof_facet name="your-facet"]` shortcode, the **Hooked on Facets** Gutenberg
block, or your page builder's binding (Elementor Query ID, Bricks CSS class, etc.).

== Changelog ==

= 1.0.1 =
* Fixed: elements toggled with the HTML `hidden` attribute (Visual DNA result row
  and palette, eyedropper button, swiper done-card) could stay visible because
  author `display` rules beat the browser's `[hidden]` default. The public
  stylesheet now ships a scoped guard.
* Fixed: the Visual DNA color map read the legacy `swatch_color` term-meta key,
  so palettes saved through the swatch fields UI never matched.

= 1.0.0 =
* First stable release. Promotes the feature-complete 0.13.x line to general
  availability with a committed public API and index schema. No functional changes
  from 0.13.1-alpha.

= 0.13.1-alpha =
* Counts query optimization — drill-down counts now `GROUP BY facet_value` alone
  (display via `MIN(facet_display)`); `resolve()` p95 dropped ~465ms → ~63ms on 100k
  products, uncached.

= 0.13.0-alpha =
* Added: SEO for filtered pages (canonical, conditional noindex, title suffix).
* Added: analytics dashboard — facet/value usage, zero-result combinations, p50/p95/p99
  resolver latency, dead-facet callout.
* Changed: resolver result-set cache keyed by index version + filter state, with O(1)
  invalidation.

= 0.12.0-alpha =
* Feature-complete milestone: spin-the-wheel, saved comparison bin, and the
  intersection matrix facets; AND-within-facet resolver mode; multisite support.

= 0.11.0-alpha =
* Custom-field sources: ACF, Meta Box, and Pods. Indexer ID→label resolution for
  post / user / term references. Divi bridge. Action Scheduler-backed background reindex.

= 0.10.0-alpha =
* Breakdance bridge (Array Query recipe).

= 0.9.0-alpha =
* Bricks bridge (query binding by CSS class).

= 0.8.0-alpha =
* Theming foundation (CSS variable tokens) and a numbered pagination facet.

= 0.7.0-alpha =
* Elementor bridge (Query ID binding).

= 0.6.0-alpha =
* Customer-ready foundation.

= 0.5.0-alpha =
* Premium licensing scaffold.

= 0.1.0-alpha =
* First public alpha.

== Upgrade Notice ==

= 1.0.0 =
First stable release. The public API and index schema are now stable. Re-run the
indexer after upgrading.
</content>
</invoke>
