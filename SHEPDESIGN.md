# SHEPDESIGN.md

This file provides guidance to shepdesign code (shepdesign.ai/code) when working with code in this repository.

## What this is

Hooked on Facets (HOF) is a WordPress + WooCommerce faceted search and filtering plugin. The brand promise is sub-50ms filter queries on 100k+ product catalogs paired with UI inventions that legacy competitors (FacetWP, WP Grid Builder) don't ship: swipe-deck facets, 2D slider grids, gamified "spin the wheel" picker, fluid swatch tiles, a drag-and-drop saved comparison bin.

The Venn matrix and UpSet matrix shipped briefly in Phase 2 but were retired — the OR-within-facet semantic mismatched the AND-intersect visual, and users couldn't tell what they had selected. The fix landed as the active filters chip strip ([hof_active_filters]) which the simpler checkbox list pairs with. Matrix visualizations are off-deck until the resolver supports real AND-within-facet.

See `plan.md` for what is in scope per phase. The "wow kit" is Phase 2; Phase 1 is performance foundations plus three baseline facet types.

## Common commands

```bash
# Bootstrap (once after clone)
composer install
npm install

# Dev loop
docker compose up -d        # WP @ http://localhost:8080 (admin / admin), MySQL 8, auto-installs WC
npm run dev                 # Vite HMR server on :5173
npm run build               # Production bundles → assets/dist/

# After adding/renaming/removing files in includes/
composer dump-autoload      # Only the classmap half needs this; src/ (PSR-4) is automatic

# WP-CLI inside the stack
docker compose exec wp-cli wp <command>
```

## Architecture

### Two-namespace dual-load pattern

Composer is wired to load *both* a classmap and PSR-4 under the same plugin:

- `includes/class-hof-*.php` — WordPress-convention filenames, classmap-loaded. Reserved for the core engine classes named in the original architectural blueprint: `Activator`, `Indexer`, `QueryHook`. All under namespace `HookedOnFacets\`.
- `src/**/*.php` — PSR-4 under `HookedOnFacets\`. Everything else lives here: the `Plugin` service container, facet types, REST controllers, admin services, future CLI commands.

When you add or rename a file in `includes/`, run `composer dump-autoload`. Adding to `src/` requires no Composer command.

### The data path

1. **Activator** (`includes/class-hof-activator.php`) installs `wp_hof_index` on plugin activation — narrow EAV (one row per object × facet × value). Indexes are designed around two hot paths: `(facet_name, facet_value)` for set-intersect filters and `(facet_name, facet_numeric)` for range filters.
2. **Indexer** populates rows on `save_post` / `set_object_terms` and on full rebuilds. The `facet_numeric DECIMAL(20,6)` column is the hot path for range filters; never parse strings at query time.
3. **QueryHook** (the Auto-Hook Engine) intercepts WP_Query loops it detects on a page, joins through `wp_hof_index`, and substitutes the result IDs back via `post__in`. No template surgery, no per-builder configuration.
4. Frontend JS in `public/src/` POSTs to `/wp-json/hof/v1/filter` and re-renders the result region in place.

### Bootstrap defensiveness

`hooked-on-facets.php` is intentionally paranoid: missing `vendor/`, wrong PHP, wrong WP all surface as admin notices and `return` early. Never let a missing dependency fatal a customer site. The `HOF_*` constants are the single source of truth for paths — no `plugin_dir_path(__FILE__)` calls anywhere else.

### Vite multi-entry

`vite.config.js` builds two independent bundles:

- `admin/src/main.jsx` — React 18 SPA for the facet builder, mounted on a top-level WP admin page.
- `public/src/main.js` — Frontend runtime that hydrates facets on the live site.

Output goes to `assets/dist/<name>/` with hashed filenames + `manifest.json`. PHP reads the manifest at enqueue time to resolve current asset URLs.

## Where things go

| Need to add… | Goes in |
|---|---|
| Core engine class named in the blueprint (Activator, Indexer, QueryHook) | `includes/class-hof-*.php`, run `composer dump-autoload` |
| New facet type (checkbox, range, search, etc.) | `src/Facets/` |
| REST endpoint | `src/Api/` |
| Page builder bridge — service class | `src/Integrations/{Builder}.php` (Bootable, guards on builder presence) |
| Page builder bridge — assets (widget files, templates) | `integrations/{builder}/` (require_once'd just-in-time) |
| Gutenberg block | `integrations/gutenberg/blocks/{name}/` |
| Admin SPA component / screen | `admin/src/` |
| Frontend facet runtime component | `public/src/components/` |
| WP-CLI command | `src/Cli/` |

## Performance is the brand

Don't ship code on the filter hot path that:

- Does N+1 queries (facet counts must be one grouped query per facet)
- Casts strings to numbers at query time (use `facet_numeric`)
- Calls `get_post()` / `get_terms()` inside a loop without batching
- Bypasses the index table — if a facet can't be served from `wp_hof_index`, fix the indexer, don't fall back to live WP queries

**Achieved Phase 1 baseline** (100k products, 500k index rows, 5-facet intersect, Docker MySQL 8):

- `resolve_ids()` p95: **54.5 ms** (the gate is 50 ms; remaining gap is bounded by the benchmark's non-selective price leg)
- `resolve()` full p95: **308 ms** (1 ids query + 5 count queries, same SQL shape)
- Reindex 100k: **19.4 s** with bulk-rebuild (Phase 1.5 — was 300 s on the per-object path; 60 s gate now passes by 3×)

### The SQL shape is load-bearing — don't accidentally regress it

The resolver builds an **`INTERSECT` chain** of per-facet SELECTs (MySQL 8.0.31+). Each leg carries a **`USE INDEX` hint**. The two indexes the legs target — `facet_lookup` and `facet_numeric_range` — both end in `object_id` so leg scans are **covering** (index-only, no heap fetch).

Three things make the 7× speedup work; do not change any of them lightly:

1. **The trailing `object_id` column on `facet_lookup` and `facet_numeric_range`.** Without it, leg scans fetch every matching row from the heap. Worth 1.5× alone.
2. **The `USE INDEX (facet_numeric_range)` hint on range legs.** Without it, MySQL picks `facet_lookup` for `BETWEEN` predicates (both indexes start with `facet_name`, cost estimates come out close) and then post-filters on `facet_numeric`, which forces a heap fetch per row. Worth ~70ms.
3. **`INTERSECT` instead of `UNION ALL` + `GROUP BY HAVING COUNT(DISTINCT)`.** UNION ALL materializes the full union (~150k rows on a 5-facet filter), then sorts and aggregates. INTERSECT runs set semantics natively. Worth ~35ms.

All three are reproducible via `bin/benchmark.sh` on a seeded 100k dataset.

## Theming — CSS variable contract

Every visible chrome value on every facet — colors, radii, typography, focus rings, input padding, label/count styling, named shadow scale — is driven by a `--hof-*` CSS custom property. Defaults preserve the Shepdesign voice; consumers override either via raw CSS or via the `hof_public_css_tokens` PHP filter.

### Naming convention

- **Brand / palette**: `--hof-primary`, `--hof-on-primary`, `--hof-accent`, `--hof-surface`, `--hof-bg`, `--hof-border`, `--hof-text`, `--hof-muted`, `--hof-danger`
- **Scales**: `--hof-radius-{xs|sm|md|lg|xl|pill}`, `--hof-font-size-{xs|sm|body|eyebrow}`, `--hof-shadow-{xs|sm|md|thumb}`, `--hof-line-height-{tight|body}`
- **Named chrome (cross-facet)**: `--hof-input-{bg|border|border-w|radius|color|pad-x|pad-y}`, `--hof-label-{color|font-size|font-weight|letter-spacing|transform}`, `--hof-count-{color|font-size|font-weight}`, `--hof-option-{gap|pad-y|accent}`
- **Focus**: `--hof-focus-ring`, `--hof-focus-ring-w`, `--hof-focus-ring-offset`, `--hof-focus-ring-soft-w`, `--hof-focus-ring-soft-alpha` (the soft glow used by dropdown/date/ask)
- **Per-facet** (only where a facet has chrome the cross-facet tokens can't address): `--hof-swatch-*` (13), `--hof-swiper-*` (3), `--hof-toggle-{track|thumb}-*`, `--hof-hierarchy-{indent|guide-color|guide-style}`, `--hof-chip-{bg|bg-hover|border|radius}`

### Two override paths

```php
// 1. PHP filter — the ~30 curated tokens are pre-registered. Override any
// of them by returning a new value; add tokens to extend the surface.
add_filter( 'hof_public_css_tokens', function ( $tokens ) {
    $tokens['--hof-primary']   = '#0ea5e9';
    $tokens['--hof-radius-md'] = '12px';
    $tokens['--hof-shadow-sm'] = '0 0 0 1px rgba(0,0,0,0.05)';
    return $tokens;
} );
```

```css
/* 2. Raw CSS — every token defined in the bundle is overridable here.
   Scope to .hof-facet so the override beats the bundle's defaults. */
.hof-facet, .hof-results {
    --hof-primary: #0ea5e9;
    --hof-input-radius: var(--hof-radius-lg);
}
```

### Cascade gotcha — fixed in this PR

`hof_public_css_tokens` was effectively dead for any token the bundled CSS also defined, because the inline `<style id="hof-tokens">` block fired at `wp_head:5` — *before* the bundle stylesheet enqueued at `:8`. CSS cascade with equal specificity means later-in-source wins, so the bundle's defaults overrode whatever the filter put down. `AssetLoader::register_hooks` now binds `print_token_block` at `wp_head:20`, after `wp_print_styles`. If you ever change that priority, the filter goes back to being decorative.

### What's *not* tokenized

Animation durations, structural micro-gaps (4–6px), specific rotation degrees on the swipe-stamp, the `[data-hof-swatch-weight]` resize calc, and other "this is the design's bones, not its skin" details. The intent is bounded customization — colors, scales, named chrome — not "every pixel is a knob."

## Page builder integrations

Each page builder gets a single Bootable service in `src/Integrations/{Builder}.php` that no-ops when its builder isn't loaded. Assets the builder needs to load with its own class hierarchy (e.g. widgets extending `\Elementor\Widget_Base`) live in `integrations/{builder}/` and are `require_once`'d just-in-time from the bridge — autoload deliberately doesn't reach them because their parent classes only exist at runtime when the builder is active.

### Elementor — Query ID convention

The Elementor bridge is opt-in by **Query ID**, not magic detection. Users set a Query ID (default `hof`) on their Loop Grid / Posts / Products widget, and the bridge hooks `elementor/query/{id}` to apply `post__in` from the URL's `?hof[*]` state directly. Multiple bound loops or a different naming convention can be configured via the `hof_elementor_query_ids` filter.

The bridge doesn't piggyback on QueryHook's `pre_get_posts` path because Elementor calls `$query->query($args)` *after* the `elementor/query/{id}` action fires, and `WP_Query::query()` resets query_vars from `$args` — so a `$query->set('hof_facet_target', …)` flag would be discarded. Setting `post__in` directly is robust and matches Elementor's own documented mutation pattern.

### Bricks — CSS-class binding

Bricks has no dedicated "Query ID" field, so the bridge opts a query loop in by **CSS class**: tag the loop element (Style → CSS classes) with `hof` and the bridge hooks `bricks/posts/query_vars` to apply `post__in` from the URL's `?hof[*]` state. Unlike Elementor's by-reference `WP_Query` mutation, this Bricks filter passes the query args by value and expects them returned. A class (rather than the element's CSS ID) is the binding key because it's repeatable across loops and doesn't force a unique HTML `id`. The match list is filterable via `hof_bricks_query_ids` — same `_query_ids` naming as Elementor, even though Bricks applies the identifier as a class.

Boot timing differs from Elementor, which matters: **Bricks ships as a theme**, loaded on `after_setup_theme` — *after* HOF's `plugins_loaded:5` boot — so `class_exists('\Bricks\Elements')` is false when `register_hooks()` runs. The bridge splits accordingly: the `bricks/posts/query_vars` filter is passive (inert until Bricks fires it during a render) so it registers at boot with no presence check; element registration genuinely needs `\Bricks\Elements`, so it defers to `init` priority 11 (after Bricks registers its own elements) and gates on presence there. The placement element file (`integrations/bricks/elements/facet.php`, extending `\Bricks\Element`) is included by `\Bricks\Elements::register_element()`, the Bricks-side analog of the just-in-time `require_once` pattern.

## Tests

- **PHP** — PHPUnit 11 + Brain Monkey (no live WordPress; WP funcs are stubbed per test). `composer test`. Tests live under `tests/php/`; WP-class stubs (`WP_Query` and friends) live under `tests/stubs/` so composer's PSR-4 scan doesn't flag them.
- **JS** — Vitest 4 + jsdom. `npm test` / `npm run test:watch`. Tests live under `tests/js/`.

Both suites gate CI (no `|| true`, no `continue-on-error`). Add tests when a unit's behavior is non-trivial or worth pinning against regression — runtime modules that own client-visible state (the swipe deck, refresh reconciliation) earn coverage; thin glue layers don't.

## Final classes need narrow interfaces for testing

Production classes that other services depend on are marked `final` for the usual reasons (sealed surface, no surprise subclass overrides). The flip side: Mockery can't mock final classes. When a consumer only needs a small slice of a final class's API, extract a tiny interface for that slice and have the concrete class `implements` it. Examples: {@see \HookedOnFacets\Filter\IdResolver} (just `resolve_ids`), {@see \HookedOnFacets\Telemetry\LoopHookRecorder} (just `record_loop_hook`). Consumers depend on the interface; the DI container still resolves to the concrete class.
