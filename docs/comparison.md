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

| | **hooked on facets** | FacetWP | WP Grid Builder |
|---|---|---|---|
| Frontend stack | Preact + Signals | jQuery | jQuery + custom |
| Admin UI | React + `@wp/components` | Custom PHP/jQuery | React (partial) |
| Architecture | PSR-4 + Composer | Procedural-heavy | OOP, mixed |
| Open source core | ✅ | ❌ | ❌ |
| WP.org listing | Planned | No | No |
| Pricing | Open core + TBD pro tier | $99–$249/yr | $69–$249/yr |
| Dev environment included | ✅ docker-compose | ❌ | ❌ |
| Live URL state | ✅ | ✅ | ✅ |
| Indexed queries | ✅ | ✅ | ✅ |
| WooCommerce focus | First-class | Strong | Strong |
| Page builder support | Gutenberg, Bricks, Elementor, Oxygen *(planned)* | All major | All major |
| Multilingual | Planned | ✅ | ✅ |
| Last major rewrite | Built fresh 2025+ | 2017 era | 2020 era |

## where HOF wins (or aims to)

- **Bundle size.** Preact's ~4kb runtime vs jQuery's ~30kb plus the plugins' own UI code.
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

We're building migration tools (planned for 1.0):

- [Migrating from FacetWP](Migration#from-facetwp)
- [Migrating from WP Grid Builder](Migration#from-wp-grid-builder)
