# Facet Type: Date Range

A pair of native date inputs for filtering on a date range.

## what it is

Two `<input type="date">` controls (start, end). The index stores each date as a Unix epoch (the Indexer converts date sources to epoch when the facet's display is `date_range`), so the resolver matches with a numeric `BETWEEN`. Powers events, post archives, order history filtering.

## when to use it

- Event listings (start date, date range)
- Blog post archives (published date)
- Booking systems (availability windows)
- Order history filtering
- Any temporal range filter

## when not to use it

- Single date (no range) → use [Range Slider](Facet-Type-Range-Slider) configured for dates, or a dropdown of presets
- Filtering "last X days" only → use [Radio](Facet-Type-Radio) with preset options
- Non-temporal numeric ranges → use [Range Slider](Facet-Type-Range-Slider)

## configuration

```json
{
  "name": "event_date",
  "kind": "meta",
  "source": "event_start_date",
  "display": "date_range",
  "label": "When",
  "settings": {
    "format": "date"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` \| `"meta"` \| `"field"` | — | Where the indexed value comes from (meta/field for dates) |
| `source` | string | — | The meta/field key holding the date |
| `settings.format` | `"date"` \| `"datetime"` | `"date"` | `date` = ISO `yyyy-mm-dd`; assumes the source resolves to a Unix timestamp in the index |

The min/max bounds shown on the inputs are computed from the indexed values, not configured.

## URL state

```text
?hof[event_date][min]=2026-06-01&hof[event_date][max]=2026-06-30
```

Each end is an ISO date. Either side may be omitted for an open-ended range (e.g. only `[min]` for "from June 1 onward").

## examples

**Event date filter:**

```json
{ "name": "event_date", "kind": "meta", "source": "event_start_date",
  "display": "date_range", "settings": { "format": "date" } }
```

**Field-sourced publish date:**

```json
{ "name": "published", "kind": "field", "source": "post_date",
  "display": "date_range" }
```

## see also

- [Range Slider](Facet-Type-Range-Slider) — numeric ranges
- [[Core Concepts|Core-Concepts]] — how the index handles dates
