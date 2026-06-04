# Facet Type: Search

> 🚧 **Planned for v0.5 (beta).**

Free-text search scoped to the current result set.

## what it is

A debounced text input that filters the result set by matching against configured post fields (title, content, excerpt, SKU, etc.). Plays nicely with other facets — search refines what the facets have already narrowed.

## when to use it

- Product search within a category page
- "Find within these results" UX
- Long catalogs where browsing isn't enough
- Sites that already have full-text indexed content

## when not to use it

- As your *only* search (use a real search plugin like Relevanssi or SearchWP for site-wide search)
- Tiny catalogs (< 20 items) where browsing is faster
- Exact-match SKU lookups → use [Dropdown](Facet-Type-Dropdown) with a SKU source

## configuration

```json
{
  "name": "product_search",
  "type": "search",
  "label": "Search",
  "source": "post:title,excerpt,content",
  "behavior": {
    "debounce_ms": 300,
    "min_chars": 2,
    "match": "fuzzy",
    "logic": "all_words"
  },
  "ui": {
    "placeholder": "Search products...",
    "icon": "search",
    "instant": true,
    "clear_button": true
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `source` | comma-separated post fields | `"post:title"` | Fields to search across |
| `behavior.debounce_ms` | int | `300` | Ms to wait after typing stops before querying |
| `behavior.min_chars` | int | `2` | Don't query until this many chars typed |
| `behavior.match` | `"exact"` \| `"prefix"` \| `"fuzzy"` | `"prefix"` | Matching strategy |
| `behavior.logic` | `"any_word"` \| `"all_words"` \| `"phrase"` | `"all_words"` | Multi-word handling |
| `ui.placeholder` | string | `""` | Input placeholder |
| `ui.icon` | string \| `null` | `"search"` | Icon name (lucide) or `null` |
| `ui.instant` | bool | `true` | Update as user types (vs. on Enter) |
| `ui.clear_button` | bool | `true` | Show "x" to clear |

### post field sources

| `source` value | What it searches |
|---|---|
| `post:title` | Post title |
| `post:content` | Post body |
| `post:excerpt` | Post excerpt |
| `post:all` | Title + content + excerpt |
| `meta:<key>` | A specific meta field (e.g. SKU) |
| `taxonomy:<slug>` | Term names in a taxonomy |

Combine with commas: `"post:title,meta:_sku,taxonomy:product_tag"`

## URL state

```text
?_hof_product_search=organic+cotton
```

URL-encoded space (`+` or `%20`). Phrases (with `logic: "phrase"`) preserve word order.

## planned PHP filters

```php
apply_filters( 'hof_facet_search_query', $query, $facet );
apply_filters( 'hof_facet_search_fields', $fields, $facet );
apply_filters( 'hof_facet_search_min_chars', $min, $facet );
apply_filters( 'hof_facet_search_match_strategy', $strategy, $facet );

// Hook into the actual search SQL
apply_filters( 'hof_facet_search_sql_where', $where, $query, $facet );
```

## examples

**Standard Woo product search:**

```json
{ "name": "search", "type": "search",
  "source": "post:title,post:excerpt,meta:_sku",
  "behavior": { "logic": "all_words", "match": "prefix" } }
```

**Exact phrase search across content:**

```json
{ "name": "exact", "type": "search", "source": "post:all",
  "behavior": { "match": "exact", "logic": "phrase", "min_chars": 3 } }
```

## see also

- [[Core Concepts|Core-Concepts]] — how search interacts with the index
- [Dropdown](Facet-Type-Dropdown) — with searchable mode for picking from known options
