# Roadmap

Where HOF is going. Dates are aspirational, not promises. Status is updated as commits land — the [Changelog](Changelog) has the granular history.

## shipped in 1.0.0

HOF 1.0.0 is feature-complete. Everything below is in the box today.

- [x] Brand system locked
- [x] Architecture decided + documented
- [x] Plugin scaffold (PSR-4, Composer, Vite, PHP 8.2+)
- [x] Docker dev environment (WP + Woo + MySQL 8, bound to localhost)
- [x] React 18 admin SPA on `@wordpress/components`
- [x] Vanilla JS public runtime + URL-synced facet store
- [x] Renderer + REST endpoints under `/wp-json/hof/v1/`
- [x] All 16 facet types ([catalog](Facet-Types)) — checkbox, radio, dropdown, toggle, hierarchy, range slider, date range, search, fluid swatches, swipe deck, spin-the-wheel, intersection matrix, saved bin, Ask, Visual DNA, pagination
- [x] Indexer: real term / meta indexing, date → epoch conversion, background reindex (Action Scheduler + wp_cron fallback)
- [x] First-class WooCommerce integration: auto-detect + one-click add
- [x] Admin UI: full CRUD with drag-reorder + validation
- [x] WP-CLI: `wp hof reindex`, `wp hof status`, `wp hof facets`
- [x] Custom-field sources: ACF, Meta Box, Pods
- [x] Page builder integrations: Gutenberg, Bricks, Elementor, Divi, Breakdance
- [x] Licensing for premium distribution
- [x] Performance: `resolve_ids()` ~54ms p95 and full reindex ~19s on 100k products
- [x] Security & quality scaffold (CodeQL, Dependabot, secret scanning, branch ruleset)
- [x] CI: PHP + JS lint, markdown lint, build
- [x] GitHub Wiki (these pages)

## post-1.0

- [ ] HOF Pro — the six signature facets (Ask, Visual DNA, swipe deck, spin-the-wheel, intersection matrix, comparison bin) as a separate add-on plugin, sold from hookedonfacets.com
- [ ] Migration tool: FacetWP → HOF
- [ ] Migration tool: WP Grid Builder → HOF
- [ ] Multilingual: WPML + Polylang adapters
- [ ] Headless mode (REST-first, no WP rendering)
- [ ] Saved searches (per-user)
- [ ] Ask facet v2 — deeper relevance over the index (see the [Ask facet](Facet-Type-Ask) page)
- [ ] Visual DNA v4 — true visual similarity via image embeddings (shape, pattern, texture), and weight-aware palette ranking (a product whose palette mostly matches outranks a product with a single near-match)

## what we're not building

To keep HOF focused, we're explicitly **not** building:

- A full search engine replacement (use Algolia, Elasticsearch, etc.)
- A page builder (use one of the good ones)
- A theme (HOF is theme-agnostic and proud of it)

## want to influence this?

- 👍 Vote on issues with reactions
- 💡 Open a feature request with a real use case
- 🛠️ Build it and PR it (see [Contributing](Contributing))
