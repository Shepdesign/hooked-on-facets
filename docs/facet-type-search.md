# Facet Type: Search

Free-text substring filter scoped to a facet's own indexed values.

## what it is

A single text input that filters the result set with a `LIKE %term%` substring match against the values indexed for this facet's source. Plays nicely with other facets — search refines what the facets have already narrowed.

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
  "name": "title_search",
  "kind": "field",
  "source": "post_title",
  "display": "search",
  "label": "Search"
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` \| `"meta"` \| `"field"` | — | Where the searchable values come from |
| `source` | string | — | The taxonomy slug or meta/field key whose indexed values are matched |

The input matches `LIKE %term%` against this facet's indexed values for the configured source — it does not span arbitrary post fields. The placeholder is fixed ("Search…") and there are no `debounce` / `min_chars` / matching-strategy settings in 1.0.0.

## URL state

```text
?hof[title_search]=organic%20cotton
```

A single URL-encoded search string.

## examples

**Search a product field:**

```json
{ "name": "title_search", "kind": "field", "source": "post_title", "display": "search" }
```

**Search a meta value (e.g. SKU):**

```json
{ "name": "sku", "kind": "meta", "source": "_sku", "display": "search" }
```

## see also

- [[Core Concepts|Core-Concepts]] — how search interacts with the index
- [Dropdown](Facet-Type-Dropdown) — with searchable mode for picking from known options
