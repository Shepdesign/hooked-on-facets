# Architecture

How Hooked on Facets delivers sub-50ms filters on 100k+ products. This is a public
overview; the authoritative engineering decisions live in [plan.md](../plan.md).

## The data path

1. **Activator** installs `wp_hof_index` — a narrow EAV table (one row per
   object × facet × value). Two hot-path indexes back it: `facet_lookup`
   `(facet_name, facet_value, object_id)` for set-intersect filters and
   `facet_numeric_range` `(facet_name, facet_numeric, object_id)` for range filters.
2. **Indexer** populates rows on `save_post` / `set_object_terms` and on full
   rebuilds. Numeric values are pre-cast into `facet_numeric DECIMAL(20,6)` so the
   query path never parses strings. Full rebuilds run as a chunked, self-chaining
   background job (Action Scheduler preferred, `wp_cron` fallback).
3. **QueryHook** (the Auto-Hook Engine) intercepts the WP_Query loops it detects,
   joins through `wp_hof_index`, and substitutes the result IDs back via `post__in`.
   No template surgery.
4. **Frontend** POSTs filter state to `/wp-json/hof/v1/filter` and re-renders the
   results region in place.

## The resolver SQL is load-bearing

The resolver builds an **`INTERSECT` chain** of per-facet `SELECT`s
(MySQL 8.0.31+). Three things make the 7× speedup work:

1. **A trailing `object_id` column** on both indexes, so each leg scan is covering
   (index-only, no heap fetch).
2. **A `USE INDEX (facet_numeric_range)` hint** on range legs, so `BETWEEN`
   predicates don't fall back to a post-filtered heap fetch.
3. **`INTERSECT`** instead of `UNION ALL` + `GROUP BY HAVING COUNT(DISTINCT)` —
   native set semantics instead of materialize-then-aggregate.

A version-invalidated result-set cache (keyed by index version + filter state)
collapses repeat hits to sub-millisecond; the Indexer bumps the version on every
write for O(1) invalidation.

## Measured baseline

100k products, 500k index rows, 5-facet intersect (Docker MySQL 8):

- `resolve_ids()` p95: **~54.5 ms**
- `resolve()` full (IDs + counts) p95: **~63 ms** uncached
- Full reindex of 100k: **~19.4 s**

## Code layout

- `includes/class-hof-*.php` — core engine classes (`Activator`, `Indexer`,
  `QueryHook`, `Deactivator`), classmap-loaded, WordPress filename convention.
- `src/**` — everything else under PSR-4 `HookedOnFacets\`: the service container,
  facet types, REST controllers, admin services, integrations, licensing, CLI.

Performance rules on the filter hot path: no N+1 queries (counts are one grouped
query per facet), no string→number casts at query time (use `facet_numeric`), and
nothing bypasses the index table — if a facet can't be served from `wp_hof_index`,
the fix is in the indexer.
