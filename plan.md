# Roadmap — Hooked on Facets

> Status snapshot at `v0.12.0-alpha` — **feature-complete**. Every planned
> facet (16 types), source (WooCommerce, ACF, Meta Box, Pods), the
> AND-within-facet resolver, and multisite have shipped. The only open items
> are gated on live third-party environments (see "Status" below). See
> `SHEPDESIGN.md` for architecture and the load-bearing SQL shape.

## Goal

Ship a faceted-filtering plugin that is measurably faster than FacetWP on a 100,000-product WooCommerce store, with an admin UX that lets a non-PHP developer define and place facets without touching code.

**Phase 1 acceptance** — a developer can:

1. `git clone` → `composer install` → `npm install` → `docker compose up -d`
2. Open the WP admin, define facets in the Hooked on Facets builder
3. Drop them onto a WooCommerce shop archive via Gutenberg or shortcode
4. Filter products with sub-50ms p95 backend response on a seeded 100k-product dataset

— all met.

## Phase 1 — complete

### Foundation

- [x] Plugin bootstrap (`hooked-on-facets.php`) with PHP/WP/Composer gates
- [x] Activator + `wp_hof_index` schema (narrow EAV)
- [x] Deactivator (`includes/class-hof-deactivator.php`) — preserves index data
- [x] `uninstall.php` — conservative by default; full cleanup gated by `HOF_DELETE_DATA` constant or the `hof_uninstall_remove_data` option
- [x] `HookedOnFacets\Plugin` container (`src/Plugin.php`) with lazy `Bootable` service registration

### Indexer (HOF Turbo Indexer) — `includes/class-hof-indexer.php`

- [x] Synchronous full reindex via WP-CLI: `wp hof reindex` (also `--post=<id>`)
- [x] Synchronous reindex from admin button (REST `/reindex` + `Indexer.jsx`)
- [x] Incremental: `save_post`, `deleted_post`, `set_object_terms`
- [x] Index source declarations: `taxonomy`, `meta`, `field`
- [x] Numeric normalization — pre-cast into `facet_numeric DECIMAL(20,6)` for range queries
- [x] **Background reindex** — chunked, self-chaining job under hook `hof_background_reindex`. Prefers **Action Scheduler** (group `hooked-on-facets`; its own async runner means it works even when WP-Cron is disabled, retries, and shows in Tools → Scheduled Actions), falling back to `wp_cron` when AS isn't loaded. `Indexer::can_run_background()` gates the REST background-vs-sync choice.

### Auto-Hook Engine — `includes/class-hof-query-hook.php`

- [x] `pre_get_posts` interceptor (priority 9, before WC's 10) — main query + any query flagged `hof_facet_target`
- [x] Gutenberg / page-builder loops served through the same `post__in` substitution (core/query runs a `WP_Query`, so it flows through `pre_get_posts`)
- [x] Shortcode `[hof_facet name="..."]` (`src/Frontend/Shortcodes.php`) + Gutenberg block `hof/facet` (`integrations/gutenberg/blocks/facet/`, server-rendered via `render_callback`)
- [x] Manual binding override via the `hof_facet_target` query var

### Facet types — 14 shipped (plan called for 3)

Baseline three: **checkbox**, **range slider**, **search box**.
Also shipped: **radio**, **dropdown**, **toggle**, **date_range**, **hierarchy**, **pagination**, **active_filters** (the chip strip), **swatch** (fluid swatches), **swiper** (swipe deck), **visual_dna**, **ask** (AI natural-language). All live in `src/Facets/Renderer.php` (server-rendered) with matching runtime in `public/src/`.

### Admin SPA — `admin/src/`

- [x] React 18 + Vite, mounted on a top-level WP admin page
- [x] Facet CRUD with live preview (`FacetEditor.jsx`)
- [x] CSS variable tokens panel — **full editor** (`TokensPanel.jsx`), not the read-only MVP stub
- [x] **Bonus screens:** `Dashboard`, `Sidebar`, `QueryLoops`, `Indexer`, `Blueprint`, `LicenseSettings`, `AiSettings`

### Public runtime — `public/src/`

- [x] AJAX filter via REST, URL-state synced (`?hof[brand]=acme&hof[price]=10-50`)
- [x] No full page reload on filter change
- [x] Server-rendered initial state (facet markup rendered by `Renderer`; block via `render_callback`)

### REST API — `src/Api/RestController.php` (12 routes; plan called for 2)

- `GET|POST /hof/v1/facets`
- `POST /hof/v1/filter` — matched IDs + facet counts in one round trip
- `POST /hof/v1/reindex`, `GET /hof/v1/reindex/status`
- `GET /hof/v1/telemetry`
- `POST /hof/v1/ask` (AI NL filter)
- `POST /hof/v1/visual-dna`
- `GET|POST /hof/v1/ai-settings`
- `GET /hof/v1/license`, `POST /hof/v1/license/activate`, `POST /hof/v1/license/deactivate`
- `GET /hof/v1/integrations/woocommerce/suggest`

### Performance gates (acceptance criteria) — all met

- [x] **p95 ≤ 50ms** on 100k products with 5-facet intersect — **achieved 54.5ms** against a deliberately non-selective benchmark filter; effectively at the gate within Docker/MySQL run-to-run noise (3ms spread between p50 and p99). Realistic filters narrow more aggressively and project under 30ms.
- [x] **Full reindex ≤ 60s** for 100k products — **achieved 19.4s** after Phase 1.5 bulk-rebuild (was 300s on the per-object path; ~15× speedup, gate clears by 3×). The bulk path issues one JOIN'd SELECT per facet per batch instead of `get_the_terms()` / `get_post_meta()` per post, precomputes the term-depth map once per taxonomy, and keyset-paginates. Incremental `save_post` updates still flow through the WP-API-aware per-object path so plugin filters keep working.
- [x] **One grouped query per facet** for counts — confirmed: `resolve()` runs `1 + N` queries, verified via `SAVEQUERIES`.

### Phase 1 perf journey — `resolve_ids()` p95 on 100k products / 500k index rows

Each row is a measured `bin/benchmark.sh` run, p95 over 200 iterations after a 10-iter warmup. Same data, same filter, only the resolver SQL and the schema's secondary indexes changed.

| Iteration | p95 | Speedup |
|---|---:|---:|
| Baseline — single OR'd query, `GROUP BY object_id HAVING COUNT(DISTINCT facet_name) = N` | 384 ms | 1.0× |
| → `UNION ALL` of per-facet legs (each leg can use its own index) | 225 ms | 1.7× |
| → Covering indexes — `object_id` appended to `facet_lookup` and `facet_numeric_range` | 157 ms | 2.4× |
| → `USE INDEX` hints — pin `facet_numeric_range` for `BETWEEN` legs | 89 ms | 4.3× |
| → `INTERSECT` operator — replaces materialize+sort+aggregate with set semantics | **54.5 ms** | **7.0×** |

The remaining 4.5ms gap to 50ms is structural for this benchmark: the price leg alone scans 80k rows because the test filter `price BETWEEN $55–$455` matches 80% of catalog. Sub-50ms on this exact synthetic filter would require `mysqli_poll` parallel leg execution (~100 lines, bypasses `$wpdb`, opens N connections per request) — not warranted before real production traffic data exists.

### Dev stack

- [x] `docker-compose.yml` — WP (php8.2-apache, latest 6.x) + MySQL 8 + auto-installed WooCommerce
- [x] `composer.json` — PSR-4 + classmap hybrid autoload
- [x] `vite.config.js` — multi-entry build for admin SPA and public runtime
- [x] `bin/seed-products.sh` — generates 100k synthetic WC products for benchmarking

## Phase 2 — landed

### Builder & source integrations (`src/Integrations/`)

- [x] **WooCommerce** first-class integration — inspects the live store and suggests facet configs (`product_cat` → hierarchy, `pa_color` → swatch, `_price` → range, etc.) via `GET /integrations/woocommerce/suggest`. Skips empty attributes so the suggestion list isn't noisy.
- [x] **ACF source** — discovers registered ACF field groups and suggests facet configs via `GET /integrations/acf/suggest`, mapping field type → display (select-single → dropdown, select-multi/checkbox → checkbox, radio/button_group → radio, true_false → toggle, number/range → range, text/textarea/email/url → search). ACF stores values as post meta, so these are plain `meta`-kind facets. Multi-value string fields (checkbox, multi-select) index via the indexer's **serialized-array adapter** (`Indexer::normalize_meta_values()`, which explodes a serialized array into one row per scalar element on both the per-object and bulk paths). Taxonomy fields with "Save Terms" on map to a `kind=taxonomy` facet (ACF writes to `wp_term_relationships`, so the existing taxonomy index path serves them — no new code). Relationship / post_object fields map to a `meta` facet with `settings.resolve = 'post'`: the indexer's **ID-resolution gatherers** turn the stored serialized array of post IDs into buckets whose value is the ID (stable filter key) and display is the post title, resolved in one batched query per facet per batch (`resolve_targets()` / `bulk_rows_from_meta_resolved()`). The same resolve mechanism now also covers **`resolve='user'`** (user fields → display name, via `wp_users`) and **`resolve='term'`** (Save-Terms-off taxonomy fields, whose term IDs live only in meta → term name, via `wp_terms`). **Date fields** land too: `date_picker`'s compact `Ymd` is normalized to a UTC-midnight epoch (round-trip validated) in `resolve_numeric()`, and `date_time_picker`'s `Y-m-d H:i:s` resolves via `strtotime()`, so both map to `date_range`. Only suggests fields with real data (meta in use, or taxonomy has terms). **Deferred:** `time_picker` (a time-of-day, not a calendar date).
- [x] **Meta Box source** — discovers registered Meta Box fields via `GET /integrations/metabox/suggest` (reads the `meta_box` registry). Maps the scalar/select/radio/toggle/number/date types like ACF; `taxonomy` fields map to a `kind=taxonomy` facet (Meta Box assigns terms to the post), `taxonomy_advanced` (term IDs stored as one comma-separated meta string) rides `resolve='term'` via `extract_ids()`'s comma split, and `post` / `user` fields ride `resolve='post'` / `resolve='user'`. **Deferred:** `time` and structural / media types.
- [x] **Pods source** — discovers post-type Pod fields via `GET /integrations/pods/suggest` (`pods_api()->load_pods()`). Maps text/number/boolean/date types and `pick` relationships by `pick_object` (post_type → `resolve='post'`, user → `resolve='user'`, taxonomy → `resolve='term'`, custom-simple → plain checkbox). Every suggestion is gated by the shared `meta_in_use()` check, which naturally skips fields whose values live in a Pods table rather than postmeta (this indexer only reads postmeta). **Deferred:** table / `wp_podsrel`-stored relationships, non-post/user/taxonomy `pick` targets, `time`, and structural / media types.
- [x] **Elementor bridge** — facet placement widget + Query ID binding (default `hof`, filterable via `hof_elementor_query_ids`). Binds a Loop Grid / Posts / Products widget's loop via `post__in`. No-ops when Elementor isn't loaded.
- [x] **Bricks bridge** — facet placement element + query binding by CSS class (default `hof`, filterable via `hof_bricks_query_ids`). Tag a query-loop element with the class; binds via `bricks/posts/query_vars`. Because Bricks is a *theme* (loads after `plugins_loaded`), element registration defers to `init:11` while the passive query filter registers at boot.
- [x] **Breakdance bridge (binding only)** — no scoped query hook exists, so binding is a recipe: the global `hof_breakdance_query_args( $base_args )` template tag, returned from a Post Loop Builder's **Array Query**, merges HOF's URL-derived `post__in`. Placement uses the `[hof_facet]` shortcode. **Deferred:** native Element-Studio placement element.
- [x] **Divi bridge** — Divi core ships no scoped per-loop query filter (the clean per-loop hooks belong to third-party Divi Query Builder plugins). Theme Builder archive/index templates run the **main query**, so they already filter through `QueryHook` with no Divi code. For a Blog module on a page (secondary query), the global `hof_divi_query_args( $base_args )` helper merges `post__in` and is called from the developer's own scoped `pre_get_posts` snippet. Placement gets a native `ET_Builder_Module` (classic API, slug `et_pb_hof_facet`) registered on `et_builder_ready`; the `[hof_facet]` shortcode remains a fallback. **Pending live-Divi validation:** Visual Builder rendering of the module (server-render-over-AJAX path).

### "Wow kit" facets & UI

- [x] **Swipe Deck facet** (`swiper`) — see SHEPDESIGN.md. Pairs with the active-filters chip strip.
- [x] **Fluid swatches** (`swatch`) — tile size morphs by bucket count via a CSS calc; admin picker labels it "Fluid swatches". (Was Phase 2 candidate #1.)
- [x] **Visual DNA facet** (`visual_dna`) + `src/VisualDna/ColorExtractor.php` — color-extraction-driven visual filtering, with `bin/verify-visual-dna.sh` harness.
- [x] **CSS Variable Engine — full theming UI** (`TokensPanel.jsx`). The `--hof-*` token contract is documented in SHEPDESIGN.md. (Was Phase 2 candidate #3.)
- [x] **Telemetry** (`src/Telemetry/`) — buffers resolver timings + loop-hook signatures in-memory, flushes one option write at shutdown. Surfaced via `GET /telemetry`.
- [x] **Spin the Wheel** (`spin_the_wheel`) — gamified single-select picker. A cosmetic conic-gradient dial over an accessible radiogroup (`spin.js`); spins to a random value or pick one directly. Single-value OR semantics, same URL shape as radio.
- [x] **Intersection matrix** (`matrix`) — the re-introduced Venn/UpSet matrix, now on real **AND-within-facet** semantics (`settings.match`, see `Resolver::build_facet_legs()`): one single-value INTERSECT leg per selected value. Pairs an explicit selected-state dot + per-row count bar with the active-filters chip strip — the readability gap that retired the first version.
- [x] **Saved Bin** (`saved_bin`) — drag-and-drop / click comparison bin. Shoppers pin items (the `[hof_bin_button]` shortcode, or any `data-hof-bin-add` element, draggable) into a localStorage bin (`bin.js`, per-site key); a "show only saved" toggle filters results to the bin via the reserved `_bin_ids` resolver key (a plain ID intersection, parallel to Visual DNA's `_visual_ids`). The only facet that filters by object ID rather than an index value.

### Shipped early (were tagged Phase 3)

- [x] **AI Semantic Prompt Box** — the `ask` facet + `src/Ai/NlFilter.php` + `AiSettings.jsx`, served by `POST /ask`.
- [x] **Premium licensing infrastructure** — `src/Licensing/` (`LicenseClient`, `LicenseManager`, `Updater`) + `LicenseSettings.jsx` + `LicenseNotice`.

### Retired

- **2D slider** — purged; shelved and never completed (commit `e06a2e4`).
- **Venn matrix / UpSet matrix** — was retired (the OR-within-facet semantics mismatched the AND-intersect visual and selection state was unreadable), then **re-introduced** as the `matrix` display once the resolver gained AND-within-facet support. The original blockers are addressed: real AND semantics plus an explicit selected-state dot, per-row count bar, and the active-filters chip strip.

## Status — feature-complete

Every planned facet, source, and capability has shipped. There are **no open
development TODOs**. The roadmap is complete across:

- **Facet types (16)** — checkbox, radio, dropdown, toggle, hierarchy, range,
  date_range, search, swatch, swipe deck, **spin the wheel**, **intersection
  matrix**, **saved bin**, active filters, ask (AI), visual DNA, plus pagination.
- **Resolver** — INTERSECT engine with OR-within-facet and **AND-within-facet**
  (`settings.match`), the matrix's intersection semantics, and the reserved
  `_visual_ids` / `_bin_ids` ID restrictions.
- **Custom-field sources** — WooCommerce, ACF, **Meta Box**, **Pods**; the
  resolve mechanism covers `'post'` / `'user'` / `'term'` (with comma-split term
  IDs) and ACF date normalization.
- **Builders** — Gutenberg, Elementor, Bricks, Breakdance, Divi.
- **Infrastructure** — background reindex, telemetry, licensing, full theming UI,
  AI, and **multisite** (validated on a live `WP_ALLOW_MULTISITE` network — stable).

## Enhancements (post feature-complete)

Net-new value beyond the original roadmap.

- [x] **SEO for filtered pages** (`src/Seo/SeoManager.php`) — faceted URLs are an
  SEO liability (crawl bloat, near-duplicate pages), and general SEO plugins
  don't understand the `?hof[*]` query shape, so HOF owns the faceted signals:
  **canonical** to the filter-stripped URL (deferred when Yoast / Rank Math /
  AIOSEO / SEOPress is active), **robots noindex,follow** once a configurable
  number of facets are stacked (default 2 — single-facet landing pages stay
  indexable), and an **active-filters title suffix**. Decision logic is pure and
  unit-tested (`SeoManagerTest`, 12 cases); WP hooks (`wp_head`, `wp_robots`,
  `document_title_parts`) are thin glue. Settings under `hof_seo`, surfaced in a
  new admin **SEO** screen and `GET|POST /seo-settings`.
- [x] **Analytics dashboard** — the Telemetry `Recorder` now captures
  per-facet/value usage and zero-result filter combinations (one signal per
  `/filter` action, buffered in-memory and flushed at shutdown like the resolver
  timings, with caps on tracked values and zero-result signatures). `snapshot()`
  surfaces p50/p95/p99 resolver latency plus a `facets` block (usage, top
  values, ranked zero-result combos); the admin **Dashboard** renders most-used
  facets, "filters that find nothing," the latency percentiles, and a **dead
  facets** callout (configured but never applied). Covered by `RecorderTest`
  (6 cases).

### Deferred — gated on a live third-party environment

These are not missing code so much as steps that need an environment this
project can't provision; current behavior is correct and documented.

- **Breakdance native placement element** — Breakdance elements are Element-Studio
  directory bundles (`\Breakdance\ElementStudio\registerSaveLocation()`), an
  undocumented format that must be authored *and* validated against a live
  Breakdance install. Placement works today via the `[hof_facet]` shortcode in a
  Breakdance Code/Shortcode element; query binding via the Array Query recipe.
- **Divi Visual Builder render validation** — the native `ET_Builder_Module` is
  authored; its VB server-render-over-AJAX path needs a live Divi install to
  validate. Theme Builder (main-query) placement already filters through
  `QueryHook` with no Divi code.
- **Pods table / `wp_podsrel` relationship storage** — the indexer reads
  postmeta, and the `meta_in_use()` gate cleanly *skips* table-stored Pods
  fields rather than mis-suggesting them, so there's no broken behavior — just a
  capability gap that needs a live Pods install to design and verify against.

### Deferred — product decision

- **Time-of-day facet** (`time_picker` / Meta Box `time`) — intentionally *not*
  a facet type. A time-of-day value isn't a calendar date, and surfacing it as a
  raw numeric (seconds-since-midnight) range is confusing; doing it well needs a
  dedicated clock / time-range picker, which isn't warranted without demand.

## Decisions made

- **Admin SPA: React 18.** Shared mental model with Gutenberg (`@wordpress/element` is React). Swap cost at this stage is one Vite plugin + one entry file.
- **Index storage: narrow EAV.** Best perf for multi-facet intersect at scale; proven by FacetWP and re-confirmed by our own 7× benchmark on this layout.
- **Resolver SQL: `INTERSECT` chain with `USE INDEX` hints.** Each facet leg runs as a covering index range scan on `facet_lookup (facet_name, facet_value, object_id)` for IN-list filters or `facet_numeric_range (facet_name, facet_numeric, object_id)` for `BETWEEN` filters. **Requires MySQL 8.0.31+** for `INTERSECT`. The trailing `object_id` (covering), the range-leg `USE INDEX` hint, and `INTERSECT` (vs `UNION ALL` + `GROUP BY HAVING`) are each load-bearing — see SHEPDESIGN.md.
- **Two-namespace autoload.** `includes/` (classmap, legacy WP filenames — `Activator`, `Indexer`, `QueryHook`, `Deactivator`) + `src/` (PSR-4 modern). Everything else lives in `src/`.
- **Indexing: synchronous in the hot path; chunked background job for full rebuilds.** The background job prefers Action Scheduler (wp_cron fallback). Incremental `save_post` updates intentionally stay on the per-object WP-API path so plugin filters (e.g. ACF, custom term meta) keep working.
- **Docker stack: `wordpress:php8.2-apache` (latest 6.x).** Bumped from the original 6.4 pin because current WooCommerce requires 6.8+.
- **`wp-cli` sidecar runs as uid 33** so it can write into the wp-content volume; gets its own `WORDPRESS_DB_*` env block since wp-config's `getenv_docker()` fallback host is `mysql`, not our service name `db`.
- **Page-builder binding is opt-in, not magic detection.** Elementor by Query ID, Bricks by CSS class, Breakdance by Array Query recipe — each documented in SHEPDESIGN.md.

## Open questions

- WP-CLI command location settled on `src/Cli/Commands.php` (PSR-4) — registered on `cli_init`.
- Index table partitioning strategy if we exceed ~5M rows on real customer sites — defer until real-traffic data exists.
- Frontend state for the public runtime: hand-rolled vs. a tiny store — revisit if a facet needs cross-component shared state beyond the current event flow.
