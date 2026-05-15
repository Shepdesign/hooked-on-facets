# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Hooked on Facets (HOF) is a WordPress + WooCommerce faceted search and filtering plugin. The brand promise is sub-50ms filter queries on 100k+ product catalogs paired with UI inventions that legacy competitors (FacetWP, WP Grid Builder) don't ship: swipe-deck facets, Venn-diagram intersect matrices, 2D slider grids, gamified "spin the wheel" picker, fluid swatch tiles, a drag-and-drop saved comparison bin.

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
| Page builder bridge (Elementor, Divi, Bricks, Breakdance) | `integrations/{builder}/` |
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
- Reindex 100k: **300 s** (Phase 1.5 will replace with bulk-rebuild)

### The SQL shape is load-bearing — don't accidentally regress it

The resolver builds an **`INTERSECT` chain** of per-facet SELECTs (MySQL 8.0.31+). Each leg carries a **`USE INDEX` hint**. The two indexes the legs target — `facet_lookup` and `facet_numeric_range` — both end in `object_id` so leg scans are **covering** (index-only, no heap fetch).

Three things make the 7× speedup work; do not change any of them lightly:

1. **The trailing `object_id` column on `facet_lookup` and `facet_numeric_range`.** Without it, leg scans fetch every matching row from the heap. Worth 1.5× alone.
2. **The `USE INDEX (facet_numeric_range)` hint on range legs.** Without it, MySQL picks `facet_lookup` for `BETWEEN` predicates (both indexes start with `facet_name`, cost estimates come out close) and then post-filters on `facet_numeric`, which forces a heap fetch per row. Worth ~70ms.
3. **`INTERSECT` instead of `UNION ALL` + `GROUP BY HAVING COUNT(DISTINCT)`.** UNION ALL materializes the full union (~150k rows on a 5-facet filter), then sorts and aggregates. INTERSECT runs set semantics natively. Worth ~35ms.

All three are reproducible via `bin/benchmark.sh` on a seeded 100k dataset.

## Tests

PHPUnit lives under `tests/php/`; Vitest under `tests/js/`. Neither is wired up yet — add when the first non-trivial unit appears.
