# Comparison

HOF vs the other guys. Honest takes only.

> [!NOTE]
> This is a positioning document by the HOF team. We try to be fair, but we're not unbiased. Verify claims for yourself.

## the landscape

The WordPress facet-filter space has been dominated by two paid plugins for years:

- **FacetWP** — the workhorse. Mature, stable, expensive.
- **WP Grid Builder** — newer, prettier UI, also paid.
- **Search & Filter Pro** — solid for simple sites.

HOF enters with a specific bet: **modern stack, open core, dev-friendly, fair pricing.**

## quick comparison

*Competitor pricing verified July 2026.*

| | **hooked on facets** | FacetWP | WP Grid Builder |
|---|---|---|---|
| Frontend stack | Vanilla JS — zero runtime dependencies | jQuery | jQuery + custom |
| Admin UI | React 18 SPA | Custom PHP/jQuery | React (partial) |
| Architecture | PSR-4 + Composer | Procedural-heavy | OOP, mixed |
| **Ask** — AI natural-language facet | ✅ Pro | ❌ | ❌ |
| **Visual DNA** — perceptual color matching | ✅ Pro | ❌ | ❌ |
| Swipe deck facet | ✅ Pro | ❌ | ❌ |
| Spin-the-wheel facet | ✅ Pro | ❌ | ❌ |
| Intersection matrix facet | ✅ Pro | ❌ | ❌ |
| Comparison bin facet | ✅ Pro | ❌ | ❌ |
| Open source core | ✅ | ❌ | ❌ |
| Free version on WordPress.org | ✅ (planned listing) | ❌ | ❌ |
| Pricing | Free core · Pro $99–$399/yr, renewals 50% off | $99–$499/yr, renewals 20% off, limited free trial | $49–$249/yr |
| Dev environment included | ✅ docker-compose | ❌ | ❌ |
| Live URL state | ✅ | ✅ | ✅ |
| Indexed queries | ✅ | ✅ | ✅ |
| WooCommerce focus | First-class | Strong | Strong |
| Page builder support | Gutenberg, Bricks, Elementor, Divi, Breakdance | All major | All major |
| Multilingual | Planned | ✅ | ✅ |
| Last major rewrite | Built fresh 2025+ | 2017 era | 2020 era |

## where HOF wins (or aims to)

- **Zero-framework frontend.** The public runtime is dependency-free vanilla JS (~9kb gzipped) — no jQuery, no framework, nothing to conflict with your theme's stack.
- **Facets nobody else ships.** Ask (natural language → filters) and Visual DNA (perceptual CIELAB color matching) don't exist anywhere else in this space — plus swipe deck, spin-the-wheel, intersection matrix, and the comparison bin.
- **Developer experience.** PSR-4, Composer, real namespaces, Vite, HMR, Docker dev env.
- **Theme-ability.** CSS custom properties everywhere. No `!important` arms race.
- **Openness.** Core is open source. You can read the indexer, audit it, fix it.

## where the others win (today)

- **FacetWP: maturity.** Years of edge cases handled. A vast user base. Bulletproof.
- **WPGB: visual polish.** The admin UI is genuinely gorgeous. Their card builder is strong.
- **Both: integrations.** Years of integration work with every page builder, theme, CPT plugin.

HOF is new. We don't pretend to match FacetWP's edge-case coverage on day one. We're betting that *modern stack + open core + good defaults* gets us competitive faster than they can modernize.

## who should pick what

| If you... | Pick |
|---|---|
| Need it working in production *today* | FacetWP |
| Want the slickest admin UI | WP Grid Builder |
| Are building a new project and want modern code | hooked on facets |
| Want open source you can audit and extend | hooked on facets |
| Run a dev shop and value DX | hooked on facets |
| Need WPML / Polylang on day one | FacetWP or WPGB *(HOF planned)* |

## migration

Automated migration tools are on the post-1.0 roadmap. The planned scope:

- [Migrating from FacetWP](Migration#from-facetwp)
- [Migrating from WP Grid Builder](Migration#from-wp-grid-builder)
