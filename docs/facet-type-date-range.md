# Facet Type: Date Range

> ✅ **Shipped (experimental).** UI + resolver path are live; Indexer date → epoch conversion is still a follow-up.

A calendar-driven date range picker with optional presets.

## what it is

Two date inputs (start, end) with a dual-month calendar UI. Optional preset shortcuts ("Today", "This week", "Next 30 days"). Powers events, post archives, order history filtering.

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
  "type": "date_range",
  "label": "When",
  "source": "meta:event_start_date",
  "behavior": {
    "min": "auto",
    "max": "auto",
    "compare": "overlaps",
    "end_source": "meta:event_end_date"
  },
  "ui": {
    "preset_ranges": ["today", "this_week", "this_month", "next_30_days"],
    "calendar": "dual",
    "first_day_of_week": 1,
    "date_format": "site_default"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.min` | ISO date \| `"auto"` | `"auto"` | Earliest selectable date |
| `behavior.max` | ISO date \| `"auto"` | `"auto"` | Latest selectable date |
| `behavior.compare` | `"between"` \| `"overlaps"` \| `"starts_in"` | `"between"` | Match strategy |
| `behavior.end_source` | string \| `null` | `null` | For event-style data with start + end fields |
| `ui.preset_ranges` | array of preset slugs | `[]` | Shortcut buttons |
| `ui.calendar` | `"single"` \| `"dual"` | `"dual"` | One or two months shown |
| `ui.first_day_of_week` | 0-6 | site setting | 0 = Sunday, 1 = Monday |
| `ui.date_format` | string \| `"site_default"` | `"site_default"` | PHP date format |

### preset slugs

`today`, `tomorrow`, `yesterday`, `this_week`, `this_weekend`, `this_month`, `next_7_days`, `next_30_days`, `next_90_days`, `last_7_days`, `last_30_days`, `last_90_days`, `this_year`, `last_year`

## URL state

```text
?_hof_event_date=2026-06-01_2026-06-30
```

ISO dates separated by underscore. Open-ended: `?_hof_event_date=2026-06-01_` (from June 1 onward).

## planned PHP filters

```php
apply_filters( 'hof_facet_date_range_bounds', $bounds, $facet );
apply_filters( 'hof_facet_date_range_presets', $presets, $facet );
apply_filters( 'hof_facet_date_range_compare', $compare, $facet );
apply_filters( 'hof_facet_date_range_format', $format, $facet );
```

## examples

**Event calendar with overlap matching:**

```json
{ "name": "when", "type": "date_range", "source": "meta:event_start",
  "behavior": { "compare": "overlaps", "end_source": "meta:event_end" },
  "ui": { "preset_ranges": ["this_week", "this_month", "next_30_days"] } }
```

**Blog archive by publish date:**

```json
{ "name": "published", "type": "date_range", "source": "post:date",
  "behavior": { "compare": "between" },
  "ui": { "calendar": "dual" } }
```

## see also

- [Range Slider](Facet-Type-Range-Slider) — numeric ranges
- [[Core Concepts|Core-Concepts]] — how the index handles dates
