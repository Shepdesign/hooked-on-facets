# Facet Type: Toggle

> ✅ **Shipped (experimental).**

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
  "type": "toggle",
  "label": "In stock only",
  "source": "meta:_stock_status",
  "behavior": {
    "match_value": "instock",
    "default": false,
    "show_count": true
  },
  "ui": {
    "style": "switch",
    "label_position": "right",
    "size": "md"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.match_value` | scalar | `true` | The value to match when toggle is ON |
| `behavior.default` | bool | `false` | Default state (ON or OFF) |
| `behavior.show_count` | bool | `true` | Show count of matching items next to label |
| `ui.style` | `"switch"` \| `"checkbox"` \| `"pill"` | `"switch"` | Render style |
| `ui.label_position` | `"left"` \| `"right"` | `"right"` | Label placement |
| `ui.size` | `"sm"` \| `"md"` \| `"lg"` | `"md"` | Visual size |

## URL state

```text
?_hof_in_stock=1
```

Absent param or `=0` means OFF (no filter). `=1` means ON.

## planned PHP filters

```php
apply_filters( 'hof_facet_toggle_match_value', $value, $facet );
apply_filters( 'hof_facet_toggle_count', $count, $facet );
apply_filters( 'hof_facet_toggle_default', $default, $facet );
do_action( 'hof_facet_toggle_changed', $is_on, $facet );
```

## examples

**WooCommerce in-stock filter:**

```json
{ "name": "in_stock", "type": "toggle", "source": "meta:_stock_status",
  "behavior": { "match_value": "instock" },
  "ui": { "style": "switch", "label_position": "right" } }
```

**On-sale filter (custom virtual source):**

```json
{ "name": "on_sale", "type": "toggle", "source": "virtual:woo_on_sale",
  "behavior": { "match_value": true },
  "ui": { "style": "pill" } }
```

**Featured posts:**

```json
{ "name": "featured", "type": "toggle", "source": "meta:_featured",
  "behavior": { "match_value": "yes", "default": false } }
```

## see also

- [Radio](Facet-Type-Radio) — for three-state filters (on / off / either)
- [Checkbox](Facet-Type-Checkbox) — for multi-flag scenarios
