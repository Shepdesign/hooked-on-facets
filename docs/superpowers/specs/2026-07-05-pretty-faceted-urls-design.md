# Pretty Faceted URLs — Design

> Status: design approved in principle (Approach A + component split). Awaiting
> final spec review before an implementation plan is written.
> Target: Hooked on Facets `v1.1.0` (feature-complete `v1.0.0` baseline).

## Summary

Give HOF filtered pages clean, crawlable, shareable URLs — turning
`/shop/?hof[brand]=nike&hof[color][]=blue` into
`/shop/filter/brand/nike/color/blue/` — so faceted pages become an SEO asset
instead of a query-string liability. This is the feature FacetWP charges a
premium for and HOF's single biggest strategic gap at 1.0.

The whole system already keys off one entry point: `$_GET['hof']`, parsed by
`Resolver::parse_request_filters()` into a filter-state array. This feature adds
a translation layer — path ⇄ that same array shape — so the resolver, query
hook, and all 16 facet types stay **untouched**. New code, not changed routing.

## Decisions (settled with the user)

- **Format:** namespaced key/value — `/shop/filter/brand/nike/color/blue/`. The
  reserved `filter` segment guarantees no collision with WooCommerce attribute
  archives (`/brand/nike/`) or other permalinks.
- **Scope:** WooCommerce storefront surfaces — the shop archive plus product
  category / tag / attribute archives. WP already knows these base paths, so
  rewrite rules are reliable. (Arbitrary shortcode/block pages are out of scope.)
- **Multi-value:** repeated key — `/filter/brand/nike/brand/adidas/`. Immune to
  delimiter collisions; AND-vs-OR stays a facet setting, not encoded in the URL.
- **Tail:** ranges (`hof[price][min]/[max]`), free-text `hof[search]`, and the
  per-user reserved keys `_bin_ids` / `_visual_ids` stay on a short `?hof[*]`
  query tail — they're infinite-cardinality or session-specific and don't belong
  in a crawlable path.
- **Crawlable links:** yes — the server renderer emits a real `<a href>` pretty
  link per discrete value (alongside the existing input), so bots discover the
  URLs and no-JS works. JS intercepts clicks.
- **Opt-in:** off by default; enabling registers rewrite rules and requires a
  one-time permalink flush.

## Non-goals (YAGNI)

- Value-only URLs (`/shop/nike/blue/`) — ambiguous, collision-prone.
- Arbitrary-page scope (shortcode/block on a random page) — needs a configurable
  base list; revisit only on demand.
- Ranges / search in the path — deliberately query-tail.
- XML-sitemap emission of facet URLs — a later enhancement, not this spec.

## Architecture

New namespace `HookedOnFacets\Routing` (`src/Routing/`). Services are `Bootable`
and registered in `src/Plugin.php` like the rest.

```
Request:  /shop/filter/brand/nike/color/blue/?hof[price][min]=10
   │
   ▼  WP rewrite rule (RewriteManager)  →  query var hof_path="brand/nike/color/blue"
   ▼  FilterState::current()            →  UrlCodec::decode(hof_path) ⊕ $_GET['hof'] tail
   ▼  Resolver / QueryHook (UNCHANGED)  →  reads the merged filter-state array
   ▼  Renderer                          →  facet inputs + crawlable <a> pretty links
   ▼  SeoManager                        →  canonical (ordered) + noindex + 301 legacy→pretty
```

### Units

| Unit | File | Responsibility |
|---|---|---|
| `UrlCodec` | `src/Routing/UrlCodec.php` | Pure, WP-free. `encode(state) → {path, tail}`, `decode(path) → state`. The unit-tested heart. |
| `SlugMapper` | `src/Routing/SlugMapper.php` | Value ⇄ slug. Taxonomy = identity (value already a slug). Meta = `sanitize_title()` + cached reverse lookup, deterministic collision suffixes. |
| `RewriteManager` | `src/Routing/RewriteManager.php` | Registers `hof_path` query var + rewrite rules on storefront bases; deferred flush on toggle/activation. |
| `FilterState` | `src/Routing/FilterState.php` | Single provider `current()`; `Resolver` + `QueryHook` call it instead of reading `$_GET['hof']` directly. |
| Link gen (server) | `src/Facets/Renderer.php` (extend) | Crawlable `<a href>` per discrete value via `UrlCodec::encode`. |
| Link gen (client) | `public/src/state.js` (extend) | `buildUrl` / `hydrateFromUrl` learn the pretty path, using a localized slug map. |
| SEO | `src/Seo/SeoManager.php` (extend) | Canonical → ordered pretty URL; 301 legacy/non-canonical → canonical; existing noindex-after-N unchanged. |
| Settings | option `hof_pretty_urls` + admin | Opt-in toggle + base segment; permalink-structure guard. |

## UrlCodec (the core)

Pure functions, no WP calls except through the injected `SlugMapper`. Fully
unit-tested like `SeoManagerTest`.

**`encode(array $state): array{path: string, tail: array}`**

1. Walk configured facets in **canonical order** (their saved order in
   `hof_facets`) — deterministic ordering is what makes canonical URLs stable.
2. For each facet present in `$state`:
   - **Discrete** (taxonomy, or meta whose display isn't range/search): for each
     value (sorted by slug for stability), append `/{facetName}/{valueSlug}`.
     Repeated key for multi-value.
   - **Range / search / reserved**: collect into `tail` as
     `hof[name][min]=…` / `hof[search]=…` / untouched reserved keys.
3. `path = '/' . baseSegment . '/' . implode('/', segments)` (baseSegment
   default `filter`). Return `{path, tail}`.

**`decode(string $hofPath): array`**

1. Split on `/`, pair `(facetName, valueSlug)` left-to-right; repeated keys
   accumulate into arrays.
2. `facetName` unknown to the configured set, or `valueSlug` unresolvable via
   `SlugMapper` → the whole request is a **hard 404** (canonical hygiene: no
   soft-404 duplicate pages). See Error handling.
3. Resolve each `valueSlug → value` (taxonomy: identity; meta: reverse map).
4. Return the state array, ready to merge with the `$_GET['hof']` tail.

Round-trip invariant: `decode(encode(state).path)` equals the discrete part of
`state` for any valid state. This is the primary test.

## SlugMapper

- **Taxonomy facet:** `facet_value` is already `term->slug` (verified in the
  indexer, incremental + bulk paths). Forward and reverse are identity — no DB
  work. This covers the common case for free.
- **Meta facet:** `facet_value` is raw meta text. Forward = `sanitize_title()`.
  Reverse builds a per-facet map from `SELECT DISTINCT facet_value FROM
  {prefix}hof_index WHERE facet_name = %s`, cached in the object cache under the
  current `hof_index_version` (same versioned-invalidation trick the resolver
  cache uses — a reindex bumps the version and orphans the map).
- **Collisions** (two values slugify to the same token, e.g. `12"` and `12in` →
  `12`): the cached map assigns deterministic suffixes by sort order
  (`12`, `12-2`), so encode/decode stay bijective.

## RewriteManager

- Registers `hof_path` as a public query var (`query_vars` filter).
- Adds rewrite rules (`add_rewrite_rule(..., 'top')`) for each storefront base,
  reading each object's **registered rewrite base** rather than hardcoding:
  - **Shop archive** — the `woocommerce_shop_page_id` page URI.
  - **`product_cat` / `product_tag`** — the taxonomy's rewrite slug.
  - **Attribute taxonomies** (`pa_*`) — each attribute archive's base.
- Each base gets two rules: plain (`…/filter/(.+?)/?$`) and paginated
  (`…/filter/(.+?)/page/(\d+)/?$`) so pagination survives.
- **Flush discipline:** never flush on every load. On the opt-in toggle (and on
  plugin activation when enabled) set a `hof_flush_rewrites` flag; flush once on
  the next `admin_init`. Deactivation already flushes.

## FilterState provider (small refactor)

Today `Resolver::parse_request_filters()` and `QueryHook`'s active-filter gate
each read `$_GET['hof']` directly. Introduce `FilterState::current(): array`:

```
if ( '' !== get_query_var('hof_path') )
    return UrlCodec::decode( get_query_var('hof_path') )  ⊕  sanitize( $_GET['hof'] ?? [] );  // tail
return sanitize( $_GET['hof'] ?? [] );
```

The `⊕` merge is a union in which the **path wins** for any discrete facet: with
a pretty path present the tail should carry only range/search/reserved keys, and
the 301 layer redirects legacy `?hof[discrete]` to the path form, so in steady
state the two never overlap. `Resolver` and `QueryHook` call
`FilterState::current()`. One source of truth, no `$_GET` mutation, and legacy
`?hof[*]` keeps working unchanged.

## Link generation

- **Server (Renderer):** for each discrete value, render the existing `<input>`
  **and** a sibling `<a href>` whose target is `UrlCodec::encode(state with this
  value toggled)`. JS calls `preventDefault()` and runs the AJAX + `pushState`;
  bots and no-JS users follow the link. Applies to the discrete displays
  (checkbox, radio, dropdown, swatch, hierarchy). Range/search/bin keep today's
  JS-only behavior.
- **Client (`state.js`):** extend `buildUrl(state)` and `hydrateFromUrl()` to
  emit/parse the pretty path. Taxonomy slugs need no map (value == slug); the
  meta value→slug map is shipped to the page via a localized JSON blob
  (`wp_localize_script`) keyed under the index version, so the client builds
  pretty URLs with no server round-trip. `main.js` already `pushState`s the
  output of `buildUrl` — it just becomes pretty.

## SEO (SeoManager extension)

- **Canonical:** filtered pages emit `<link rel="canonical">` to the
  deterministically-ordered pretty URL (facets in configured order, values
  sorted). Legacy `?hof[*]` and any non-canonical segment order canonicalize to
  the same target — no duplicate-content dilution. (Existing deference to Yoast /
  Rank Math / AIOSEO / SEOPress is preserved.)
- **301 redirects** (`template_redirect`, GET only, when opt-in is on): legacy
  `?hof[*]` → pretty; non-canonical ordering → canonical ordering. Loop-guarded;
  preserves pagination and non-hof query args.
- **noindex:** unchanged — the existing "noindex,follow once ≥ N facets stacked"
  (default 2) still applies, so single-facet pretty pages stay indexable (the SEO
  win) and deep combos don't bloat the crawl.

## Settings & rollout

- A dedicated option `hof_pretty_urls`: `{ enabled: bool (default false), base:
  'filter' }`, surfaced on the existing **SEO** admin screen (its natural home)
  and saved through the SEO settings route (`POST /seo-settings`, extended to
  carry the two fields). Kept separate from `hof_seo` so a rewrite flush is
  triggered only when these fields change.
- **Permalink guard:** pretty URLs require non-plain permalinks. If the site is
  on plain (`?p=123`) permalinks, the toggle is disabled with an admin notice
  explaining why.
- **Multisite:** rules + option + flush are per-blog (HOF is already
  multisite-aware).

## Error handling / edge cases

- **Unresolvable segment** (unknown facet or value) → hard 404, not a
  partial-resolve soft-404.
- **Reserved keys** (`_bin_ids`, `_visual_ids`) never appear in a path; always
  the query tail; already noindex-eligible.
- **Real term literally named the base segment** — the base is configurable
  (`filter` → e.g. `f`) for the rare store with a `/filter/` taxonomy term.
- **Page caching** — pretty URLs are real URLs, so full-page caches key on them
  cleanly (a strict improvement over query-string variants).
- **Trailing-slash / case** — normalize to the site's trailing-slash setting and
  lowercase slugs during encode; decode is tolerant.

## Testing

- `UrlCodecTest` (pure, no WP): round-trip for single / multi (repeated key) /
  taxonomy vs meta / range+search→tail / reserved excluded / deterministic
  ordering / collision suffixes / unknown facet or value.
- `SlugMapperTest` (mocked `$wpdb`): meta forward/reverse, collision suffixing,
  version-scoped cache.
- `SeoManagerTest` (extend): canonical target ordering, legacy→pretty and
  non-canonical→canonical redirect decisions, noindex threshold interplay.
- `state.test.js` (Vitest, extend): pretty `buildUrl` / `hydrateFromUrl`
  round-trips against a fixture slug map.
- `RewriteManager`: rule-generation logic unit-tested where pure; full rewrite
  resolution is integration territory (manual against the 100k sandbox).

## Assumptions to confirm during implementation

- Attribute-archive rewrite bases resolve cleanly from `wc_get_attribute_taxonomies()`
  registration (expected).
- The localized meta slug-map size is acceptable for large meta facets; if a
  facet has thousands of distinct values, fall back to a tiny REST lookup for the
  client instead of shipping the full map.

## Rough build order (for the implementation plan)

1. `UrlCodec` + `SlugMapper` (pure/DB, TDD first — the isolated heart).
2. `FilterState` provider + wire `Resolver` / `QueryHook`.
3. `RewriteManager` (rules + flush) + opt-in setting + permalink guard.
4. Server crawlable links (Renderer) + `state.js` client encode/decode.
5. `SeoManager` canonical + 301.
6. Docs (`docs/`) + `plan.md` update + CHANGELOG.
