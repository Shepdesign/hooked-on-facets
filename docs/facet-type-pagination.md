# Facet Type: Pagination

Numbered page navigation for the results grid. A view facet that doesn't filter
on its own — it paginates whatever the other facets resolved.

## what it is

A `« 1 2 … N »` nav. It computes total pages from the active HOF filter result
(or the main query's `found_posts` when no filters are applied), reads the
current page, and renders numbered links with optional first/last and prev/next
controls. Each link preserves every other URL param.

When there's only one page (or no results), the nav renders nothing.

## when to use it

- Any results grid large enough to span multiple pages
- Stores that prefer numbered pages over infinite scroll

## when not to use it

- Tiny catalogs that fit on one page (the nav auto-hides anyway)

## configuration

The display slug is `pagination`. It's a view facet — no `source`.

```json
{
  "name": "pager",
  "kind": "view",
  "source": "",
  "display": "pagination",
  "label": "Pages",
  "settings": {
    "per_page": 24,
    "neighbors": 2,
    "show_first_last": true,
    "show_prev_next": true
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `settings.per_page` | int > 0 | WP `posts_per_page` | Items per page; falls back to the site's `posts_per_page` when unset or zero |
| `settings.neighbors` | int 0–5 | `2` | How many page numbers to show on each side of the current page |
| `settings.show_first_last` | bool | `true` | Show the « first / » last controls |
| `settings.show_prev_next` | bool | `true` | Show the ‹ prev / › next controls |

(Settings allowlist: the `pagination` branch of `sanitize_settings` in
`src/Api/RestController.php`; defaults applied in `render_pagination()` in
`src/Facets/Renderer.php`.)

## interaction model

| Input | Action |
|---|---|
| Click a page number | Navigates to that page |
| ‹ / › | Previous / next page (when `show_prev_next`) |
| « / » | First / last page (when `show_first_last`) |

With JavaScript on, clicks are intercepted, the URL is `pushState`'d, and the
results re-fetch in place. The links are real `href`s, so they work without JS.

## URL state

Pagination uses WordPress's own paging, not a `?hof[...]` param. The links are
built with `get_pagenum_link()`, which respects the permalink structure —
`/shop/page/2/` with pretty permalinks, or `?paged=2` otherwise — and preserves
every other current query arg (including your active `?hof[...]` filters). The
renderer reads the current page from the `paged` query var.

```text
/shop/page/2/        (pretty permalinks)
?paged=2             (plain permalinks)
```

(Source: `pagination_url()` and `render_pagination()` in
`src/Facets/Renderer.php`.)

## theming

Pagination drops the standard facet card chrome — it's a nav control, not a
filter card — and styles its buttons from the shared `--hof-*` tokens
(`--hof-primary`, `--hof-border`, `--hof-radius-*`). Target `.hof-pagination-btn`,
`.hof-pagination-num.is-current`, and the first/last/prev/next classes to
restyle.

## see also

- [Facet Types](Facet-Types) — the full catalog
- [Getting Started](Getting-Started) — wiring facets into a page
