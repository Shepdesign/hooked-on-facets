# Facet Type: Toggle

A single boolean filter. "On sale." "In stock." "Free shipping." On or off.

## what it is

A switch or single checkbox that filters results down to items matching a single boolean condition. Off = no filter applied. On = only matching items shown.

## when to use it

- "On sale only"
- "In stock only"
- "Free shipping"
- "Featured products"
- Any binary state filter

## when not to use it

- Multiple states (on/off/either) → use [Radio](Facet-Type-Radio) with three options
- The "either" state matters → use [Radio](Facet-Type-Radio)
- Multi-value flags → use [Checkbox](Facet-Type-Checkbox)

## configuration

```json
{
  "name": "in_stock",
  "kind": "meta",
  "source": "_stock_status",
  "display": "toggle",
  "label": "In stock only",
  "settings": {
    "true_value": "instock",
    "on_label": "In stock",
    "off_label": "Off"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` \| `"meta"` \| `"field"` | — | Where the indexed value comes from |
| `source` | string | — | The taxonomy slug or meta/field key |
| `settings.true_value` | string | `"1"` | The exact `facet_value` the index stores for matching items. Typically `1` for boolean meta, or e.g. `instock` |
| `settings.on_label` | string | facet label | Label shown for the ON state |
| `settings.off_label` | string | `"Off"` | Label shown for the OFF state |

When on, the facet filters to items whose indexed value equals `true_value`; when off, the facet is cleared (no filter).

## URL state

```text
?hof[in_stock]=instock
```

The param carries `true_value` when on. Absent param means OFF (no filter).

## examples

**WooCommerce in-stock filter:**

```json
{ "name": "in_stock", "kind": "meta", "source": "_stock_status",
  "display": "toggle", "settings": { "true_value": "instock" } }
```

**Boolean featured-flag filter:**

```json
{ "name": "featured", "kind": "meta", "source": "_featured",
  "display": "toggle", "settings": { "true_value": "yes", "on_label": "Featured only" } }
```

## see also

- [Radio](Facet-Type-Radio) — for three-state filters (on / off / either)
- [Checkbox](Facet-Type-Checkbox) — for multi-flag scenarios
