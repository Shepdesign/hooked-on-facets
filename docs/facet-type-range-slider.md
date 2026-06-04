# Facet Type: Range Slider

> 🚧 **Planned for v0.1 (alpha).**

A dual-handle slider for numeric ranges. Price, weight, distance, anything continuous.

## what it is

Two draggable handles defining a min/max. Optional histogram shows data distribution. Optional number inputs for precise entry.

## when to use it

- Price filtering
- Weight, dimensions
- Numeric custom meta (rating thresholds, duration, distance)
- Anything continuous and bounded

## when not to use it

- Discrete numeric values with few options → use [Checkbox](Facet-Type-Checkbox) or [Radio](Facet-Type-Radio)
- Dates → use [Date Range](Facet-Type-Date-Range)
- Open-ended values (no sensible max) → use [Search](Facet-Type-Search) with a numeric parser

## configuration

```json
{
  "name": "price",
  "type": "range_slider",
  "label": "Price",
  "source": "meta:_price",
  "behavior": {
    "min": "auto",
    "max": "auto",
    "step": 1,
    "compare": "between"
  },
  "ui": {
    "format": "currency",
    "currency_symbol": "$",
    "show_inputs": true,
    "histogram": true,
    "histogram_buckets": 20
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.min` | number \| `"auto"` | `"auto"` | Lower bound; auto = computed from data |
| `behavior.max` | number \| `"auto"` | `"auto"` | Upper bound |
| `behavior.step` | number | `1` | Slider step increment |
| `behavior.compare` | `"between"` \| `"gte"` \| `"lte"` | `"between"` | Match strategy |
| `ui.format` | `"number"` \| `"currency"` \| `"custom"` | `"number"` | Value formatter |
| `ui.currency_symbol` | string | `"$"` | Used when `format: currency` |
| `ui.show_inputs` | bool | `true` | Render min/max number inputs alongside slider |
| `ui.histogram` | bool | `false` | Show distribution chart above slider |
| `ui.histogram_buckets` | int | `20` | Number of histogram bars |

## URL state

```text
?_hof_price=10-200
```

Hyphen-separated min and max. Open-ended ranges use empty side: `?_hof_price=-50` (up to 50) or `?_hof_price=100-` (100+).

## planned PHP filters

```php
apply_filters( 'hof_facet_range_slider_bounds', $bounds, $facet );
apply_filters( 'hof_facet_range_slider_step', $step, $facet );
apply_filters( 'hof_facet_range_slider_format_value', $formatted, $raw, $facet );
apply_filters( 'hof_facet_range_slider_histogram_data', $data, $facet );
```

## examples

**WooCommerce price slider with histogram:**

```json
{ "name": "price", "type": "range_slider", "source": "meta:_price",
  "behavior": { "min": "auto", "max": "auto", "step": 1 },
  "ui": { "format": "currency", "histogram": true } }
```

**Rating threshold (4+ stars):**

```json
{ "name": "min_rating", "type": "range_slider", "source": "meta:rating",
  "behavior": { "min": 0, "max": 5, "step": 0.5, "compare": "gte" } }
```

## see also

- [Date Range](Facet-Type-Date-Range) — for temporal ranges
- [Toggle](Facet-Type-Toggle) — for boolean numeric thresholds (e.g. "on sale")
