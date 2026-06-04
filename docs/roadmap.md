# Roadmap

Where HOF is going. Dates are aspirational, not promises. Status is updated as commits land — the [Changelog](Changelog) has the granular history.

## shipped (pre-alpha)

- [x] Brand system locked
- [x] Architecture decided + documented
- [x] Plugin scaffold (PSR-4, Composer, Vite, PHP 8.2+)
- [x] Docker dev environment (WP + Woo + MySQL 8, bound to localhost)
- [x] React 18 admin SPA on `@wordpress/components`
- [x] Vanilla JS public runtime + URL-synced facet store
- [x] Renderer + REST endpoints under `/wp-json/hof/v1/`
- [x] Facet types: **Checkbox**, **Radio**, **Dropdown**, **Toggle**, **Hierarchy**, **Range Slider**, **Date Range**, **Search**, **Color Swatch**, **Swipe Deck**
- [x] View facets: **2D Slider**, **Ask** (conversational, BYOK Anthropic key), **Visual DNA** (drop/URL/eyedrop → indexed top-N palette + MIN-ΔE76 ranking, with snap-to-term fallback)
- [x] Security & quality scaffold (CodeQL, Dependabot, secret scanning, branch ruleset)
- [x] CI: PHP + JS lint, markdown lint, build
- [x] GitHub Wiki (these pages)

## next up

Goal: land a public alpha. ✅ The alpha is live (six tagged releases so far).

- [x] Indexer foundation: real term / meta indexing
- [x] Date → epoch conversion for Date Range (v0.6.0-alpha)
- [x] First-class WooCommerce integration: auto-detect + one-click add (v0.6.0-alpha)
- [x] Admin UI: full CRUD with drag-reorder + validation (v0.4.0-alpha)
- [x] WP-CLI: `wp hof reindex`, `wp hof status`, `wp hof facets` (v0.6.0-alpha)
- [x] Background reindex via wp_cron chunking (v0.6.0-alpha)
- [x] Licensing scaffold for premium distribution (v0.5.0-alpha)
- [ ] Documentation pass: install, quick start, first facet
- [ ] Page-builder verification: smoke-test the shortcode inside Elementor / Bricks / Divi / Breakdance / Oxygen widgets

## beta · v0.5

Goal: feature parity with the basics of FacetWP.

- [ ] All planned facet types ([catalog](Facet-Types)) finished
- [ ] Listing builder (templates for results)
- [ ] Display templates (per-facet markup overrides)
- [ ] ACF integration
- [ ] Custom post type support
- [ ] Page builder integrations: Gutenberg, Bricks
- [ ] CLI: `wp hof index`, `wp hof export`

## 1.0

Goal: ship it.

- [ ] Performance benchmarks vs FacetWP / WPGB
- [ ] Migration tool: FacetWP → HOF
- [ ] Migration tool: WP Grid Builder → HOF
- [ ] Page builder integrations: Elementor, Oxygen
- [ ] Full hook reference
- [ ] Extension API (custom facet types via PHP)
- [ ] Hardened, audited, ready for production

## post-1.0

- [ ] HOF Pro (premium features TBD)
- [ ] Multilingual: WPML + Polylang adapters
- [ ] Headless mode (REST-first, no WP rendering)
- [ ] Saved searches (per-user)
- [ ] Ask v2 — semantic embeddings over the index, not just structured tool calls
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
