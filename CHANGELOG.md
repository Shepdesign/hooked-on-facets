# Changelog

All notable changes to Hooked on Facets are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
As of 1.0.0 the public API and index schema are stable; breaking changes bump the
major version. Each version links to its full GitHub release notes.

## [Unreleased]

### Added

- **Pretty faceted URLs** (opt-in) — filtered WooCommerce views get clean,
  crawlable paths: `/shop/?hof[brand]=nike` becomes `/shop/filter/brand/nike/`
  on the shop and product category/tag/attribute archives. Includes canonical
  URLs in a deterministic order, 301 redirects from legacy query-string and
  non-canonical URLs, hard 404s for unresolvable filter paths, crawlable
  `<a>` links in the discrete facet displays, and a client-side encoder so
  AJAX filtering pushes the same pretty URLs. Configure on the SEO screen;
  requires non-plain permalinks. New filters: `hof_pretty_urls_bases`,
  `hof_pretty_urls_max_values`, `hof_slugmap_cache_ttl`.

## [1.0.0] - 2026-06-04

First stable release. Promotes the feature-complete 0.13.x line to general
availability with a committed public API and index schema. No functional changes
from 0.13.1-alpha.

## [0.13.1-alpha] - 2026-05-29

### Changed

- **Counts query optimization** — the drill-down counts now `GROUP BY
  facet_value` alone (picking the display via `MIN(facet_display)`) instead of
  `(facet_value, facet_display)`. The value→display mapping is 1:1 so results
  are identical, but dropping the second 191-char column from the GROUP BY
  shrinks the aggregation temp table: `resolve()` p95 dropped from ~465ms to
  ~63ms (7.5×) on 100k products, uncached.

## [0.13.0-alpha] - 2026-05-29

First round of post-feature-complete enhancements — SEO, analytics, and resolver caching.

### Added

- **SEO for filtered pages** (`src/Seo/SeoManager.php`) — `rel=canonical` to the
  filter-stripped URL, `noindex,follow` once a configurable number of facets are
  stacked (default 2), and an active-filters title suffix. Defers canonical to an
  active general SEO plugin (Yoast / Rank Math / AIOSEO / SEOPress). New admin
  **SEO** screen and `GET|POST /seo-settings`.
- **Analytics dashboard** — the Telemetry recorder now captures per-facet/value
  usage and zero-result filter combinations; `snapshot()` adds p50/p95/p99
  resolver latency. The admin **Dashboard** renders most-used facets, "filters
  that find nothing," latency percentiles, and a dead-facets callout.

### Changed

- **Resolver result-set cache** — `resolve()` and `resolve_ids()` cache their
  output in the object cache, keyed by `(index version, filter state)`. The
  Indexer bumps `hof_index_version` on every write for O(1) invalidation.
  Benchmarking showed the IDs+counts `/filter` path was ~465ms on 100k products;
  cached repeat hits are sub-millisecond. Kill switch: `hof_resolver_cache_enabled`.

## [0.12.0-alpha] - 2026-05-29

Feature-complete milestone — the full "wow kit" plus the AND-within-facet resolver.

### Added

- **Spin-the-wheel facet** (`spin_the_wheel`) — a gamified single-select picker:
  a cosmetic conic-gradient dial over a real, accessible radiogroup that
  degrades to a plain single-select with JS off.
- **Saved-bin facet** (`saved_bin`) — a drag-and-drop / click comparison bin.
  Items are pinned to a per-site `localStorage` bin (via the new
  `[hof_bin_button]` shortcode or any `data-hof-bin-add` element) and filtered
  to with a "show only saved" toggle, backed by the reserved `_bin_ids`
  resolver key.
- **Intersection matrix facet** (`matrix`) — the Venn/UpSet matrix returns on
  real AND-within-facet semantics, paired with an explicit selected-state dot,
  per-row count bars, and the active-filters chip strip.
- **AND-within-facet resolver mode** — `settings.match = 'all'` makes a facet
  require objects to carry _every_ selected value (one single-value `INTERSECT`
  leg per value), exposed as an any/all control on multi-value facets.
- **Multisite support** — network-aware activation, new-site table seeding
  (`wp_initialize_site`), the `plugins_loaded` schema auto-heal, and per-site
  opt-in uninstall. Validated on a live `WP_ALLOW_MULTISITE` network.

## [0.11.0-alpha] - 2026-05-29

Custom-field source line — ACF, Meta Box, and Pods.

### Added

- **ACF, Meta Box, and Pods** suggestion providers — one-click facet configs
  from registered custom fields, gated so only in-use fields are offered.
- **Indexer resolve mechanism** — ID→label resolution for `post` / `user` /
  `term` references, serialized-array and comma-separated-ID adapters, and ACF
  `date_picker` (`Ymd`) → epoch normalization.
- **Divi bridge** — query helper plus a native module for placement.
- **Action Scheduler-backed background reindex**, with a `wp_cron` fallback.

## [0.10.0-alpha] - 2026-05-26

- Third page-builder bridge: **Breakdance** (Array Query recipe).

## [0.9.0-alpha] - 2026-05-26

- Second page-builder bridge: **Bricks** (query binding by CSS class).

## [0.8.0-alpha] - 2026-05-24

- Polish pass: theming foundation (CSS variable tokens), admin clarity, and a
  numbered **pagination** facet.

## [0.7.0-alpha] - 2026-05-24

- First page-builder bridge: **Elementor** (Query ID binding).

## [0.6.1-alpha] - 2026-05-24

- Test harness and CI hygiene.

## [0.6.0-alpha] - 2026-05-21

- Customer-ready foundation.

## [0.5.0-alpha] - 2026-05-21

- Premium licensing scaffold.

## [0.4.0-alpha] - 2026-05-21

- Admin SPA expansion ("admin grows up").

## [0.3.0-alpha] - 2026-05-21

- Visual DNA v3 — palette matching.

## [0.2.0-alpha] - 2026-05-21

- Visual DNA lands — color-extraction-driven filtering.

## [0.1.0-alpha] - 2026-05-21

- First public alpha.

[Unreleased]: https://github.com/Shepdesign/hooked-on-facets/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v1.0.0
[0.13.1-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.13.1-alpha
[0.13.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.13.0-alpha
[0.12.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.12.0-alpha
[0.11.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.11.0-alpha
[0.10.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.10.0-alpha
[0.9.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.9.0-alpha
[0.8.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.8.0-alpha
[0.7.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.7.0-alpha
[0.6.1-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.6.1-alpha
[0.6.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.6.0-alpha
[0.5.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.5.0-alpha
[0.4.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.4.0-alpha
[0.3.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.3.0-alpha
[0.2.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.2.0-alpha
[0.1.0-alpha]: https://github.com/Shepdesign/hooked-on-facets/releases/tag/v0.1.0-alpha
