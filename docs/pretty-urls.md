# Pretty Faceted URLs

Turn `/shop/?hof[brand]=nike&hof[color][]=blue` into `/shop/filter/brand/nike/color/blue/`.

## What it does

By default HOF encodes filter state as a `?hof[*]` query string. That's fast to
implement and perfectly crawlable, but it isn't pretty, and every combination
of filters produces its own URL with its own querystring ordering — a source
of near-duplicate pages a search engine has to sort out.

**Pretty faceted URLs** is an opt-in feature that encodes discrete facet
selections into the URL path instead: `/shop/filter/brand/nike/color/blue/`.
Everything the resolver already does — indexing, counts, AND/OR matching — is
unchanged; this is purely a routing and rendering layer on top.

Turn it on from **Hooked on Facets → SEO**, under "Pretty faceted URLs." The
toggle only takes effect with a **non-plain permalink structure**
(Settings → Permalinks — anything other than "Plain"). WordPress rewrite
rules don't exist under `?p=123`-style plain permalinks, so there's nowhere
for a `/filter/…/` path to route to; the toggle stays effectively off (and the
admin UI says so) until permalinks are configured.

## Where it applies

Pretty URLs are only registered on WooCommerce storefront surfaces that HOF
can generate real rewrite rules for:

- The shop archive (whatever page **WooCommerce → Settings → Products** has
  set as the shop page).
- Product category and product tag archives.
- Product attribute archives (`pa_color`, `pa_size`, etc. — anything
  registered via `wc_get_attribute_taxonomies()`).

Arbitrary pages built from the `[hof_facet]` shortcode, a Gutenberg block, or
a page-builder binding are **not** rewrite-rule-bearing surfaces — there's no
way to register a `/some-random-page/filter/…/` rule that WordPress's routing
can disambiguate from a real page slug. On those pages HOF keeps rendering the
`?hof[*]` query-string form; nothing breaks, they just don't get pretty links.
Likewise, if the shop page is also set as the site's front page there is no
non-root base path to rewrite under, so no rules are registered there either.

A site can register extra bases via the `hof_pretty_urls_bases` filter (see
below), but those extra surfaces still won't get crawlable links or 301/
canonical handling — HOF only recognizes the shop archive and product
taxonomies for those, since it can't otherwise confirm a third-party base
actually has working rewrite rules behind it.

## URL anatomy

```
/shop/filter/brand/nike/color/blue/color/red/?hof[price][min]=10&hof[search]=oak
     └base┘  └───────── path segments ─────────┘  └──────── tail ────────┘
```

- **Base segment.** `filter` by default (configurable — see below). Everything
  after it is `name/slug` pairs.
- **Namespaced `name/slug` pairs.** Each discrete facet selection is its own
  `/name/slug/` pair, so the path stays unambiguous no matter how many facets
  are active or in what order they were clicked.
- **Repeated keys for multi-value.** Selecting two colors produces
  `color/blue/color/red/`, not a combined segment — this is what keeps the
  encoding reversible without a delimiter-escaping scheme.
- **Canonical ordering.** Facets appear in the order they're configured in the
  facet builder; values within a facet are sorted by their slug. Two shoppers
  who clicked "red" then "blue" vs. "blue" then "red" land on the exact same
  URL — see [SEO behavior](#seo-behavior).
- **The query tail.** Not everything can live in the path: range facets
  (`price`), free-text search, the AI `ask` facet, `saved_bin`/`visual_dna`'s
  reserved ID keys, and any facet whose distinct-value count is over the
  slug-map cap (see `hof_pretty_urls_max_values` below) all stay on the
  familiar `?hof[*]` query string, appended after the pretty path.
- **Pagination.** `/shop/filter/brand/nike/page/2/` — WordPress's usual
  `/page/N/` segment sits after the filter path, same as it would on an
  unfiltered archive.

Only **discrete** facet displays are path-eligible in the first place:
checkbox, radio, dropdown, toggle, hierarchy, swatch, swipe deck, spin-the-
wheel, and matrix. Range slider, date range, search, ask, visual DNA, and
saved bin are always query-tail facets — their values aren't a finite,
slug-mappable set.

Of those, five render a crawlable `<a>` next to each option so no-JS
visitors and crawlers get a real link to click: **checkbox**, **radio**,
**dropdown**, **hierarchy**, and **swatch**. Toggle, swipe deck,
spin-the-wheel, and matrix are still path-eligible — a selection there still
produces (and accepts) a pretty URL — they just don't render an extra anchor
per option on top of their own interactive control.

## SEO behavior

- **Ordered canonical.** The `rel=canonical` tag on a filtered page always
  points at the canonically-ordered pretty URL for the active filter state
  (deferred to Yoast/Rank Math/AIOSEO/SEOPress when one of those is active and
  managing canonicals). Because ordering is deterministic, every path that
  encodes the same filter state canonicalizes to the same URL.
- **301 redirects.** Two situations redirect permanently to the canonical
  pretty URL: a legacy `?hof[*]` request for a state that has a pretty form,
  and a non-canonical pretty URL (facets or values out of order,
  e.g. `/filter/color/red/brand/nike/`). This keeps inbound links and old
  bookmarks converging on one URL per filter state instead of fragmenting
  link equity across variants.
- **Hard 404 on unresolvable paths.** A `/filter/…/` path that doesn't decode
  cleanly — an unknown facet name, an unknown slug, a facet that isn't
  path-eligible — 404s outright. This is deliberate: silently falling back to
  the unfiltered archive would serve a soft-404 (a real HTTP 200 with content
  that doesn't match the requested URL), which is worse for crawlers than an
  honest 404.
- **Noindex threshold is unchanged.** The existing "noindex once N facets are
  stacked" behavior (default 2, configurable on the SEO screen) applies
  identically whether the active filters are encoded as a pretty path or a
  query string — pretty URLs don't change when a page gets noindexed, only
  what its URL and canonical look like.

## The base segment

The reserved path segment (`filter` by default) is configurable on the SEO
screen. Change it if:

- You want a different word (`/shop/filters/…/`, `/shop/refine/…/`, whatever
  fits your site).
- **A store term collides with it** — if a product category or tag is slugged
  exactly `filter`, its own archive URLs (and their paginated/nested forms)
  get swallowed by the pretty-URL rewrite rules and 404, since the rule can't
  tell "…/category/filter/…" (the term) apart from
  "…/category/{term}/filter/…" (the filter segment). HOF detects this and
  shows a warning on the SEO screen naming the colliding term; renaming the
  base segment to anything else resolves it.

Changing the base segment (or the enabled toggle) schedules a rewrite-rule
flush on the next admin page load — it never flushes on a live front-end
request.

## Filters reference

| Filter | Default | Purpose |
|---|---|---|
| `hof_pretty_urls_bases` | WooCommerce shop + category/tag/attribute archives | Add or adjust the list of storefront bases pretty-URL rewrite rules register on. Each entry is `{prefix, query, captures}` — see `RewriteManager::build_rules()`. Extra bases added here get rewrite rules and routing, but not crawlable links, canonical, or 301 handling (see [Where it applies](#where-it-applies)). |
| `hof_pretty_urls_max_values` | `500` | The distinct-value cap per facet for the value ⇄ slug map. A facet over the cap is treated as not path-eligible on both the server and the client — it always stays on the `?hof[*]` tail, so the two encoders never disagree. |
| `hof_slugmap_cache_ttl` | `3600` (seconds) | How long a facet's value ⇄ slug map is cached in the object cache. The cache key is versioned by the index version, so a reindex invalidates it immediately regardless of TTL — this filter only controls how long an *unchanged* map is trusted. |

## Troubleshooting

**Every `/filter/…/` URL 404s.** Rewrite rules haven't been flushed yet, or
were cleared by something else (a theme switch, another plugin, a manual
`flush_rewrite_rules()` call elsewhere). Visit **Settings → Permalinks** and
click Save — that forces a flush — or toggle pretty URLs off and back on from
the SEO screen.

**Links on the page aren't pretty (still `?hof[...]`).** Two common causes:
permalinks are set to "Plain" (pretty URLs need a real permalink structure —
see [What it does](#what-it-does)), or the toggle on the SEO screen is simply
off. Both are visible at a glance on the SEO screen.

**A category/tag archive 404s after enabling pretty URLs.** Check whether its
slug matches the base segment exactly (see [The base segment](#the-base-segment)
above) — the SEO screen surfaces this as a warning. Change the base segment
to something that doesn't collide with any of your term slugs.
