# Facet Type: Radio

> ✅ **Shipped (experimental).**

Single-select from a small list. The "pick exactly one" facet.

## what it is

A list of mutually exclusive options. Users pick one (or none, with `allow_clear`). Useful when the data shape demands exclusivity.

## when to use it

- Sort order ("Newest", "Price low-high", "Popular")
- Shipping class ("Standard", "Express", "Pickup only")
- Stock state when you need a single mode (use [Toggle](Facet-Type-Toggle) for boolean on/off)
- Membership tier picker on directory sites

## when not to use it

- More than ~6 options → use [Dropdown](Facet-Type-Dropdown)
- Multiple values are valid → use [Checkbox](Facet-Type-Checkbox)
- Binary on/off → use [Toggle](Facet-Type-Toggle)

## configuration

```json
{
  "name": "shipping_class",
  "type": "radio",
  "label": "Shipping",
  "source": "meta:_shipping_class",
  "behavior": {
    "allow_clear": true,
    "default": null,
    "show_count": true
  },
  "ui": {
    "layout": "vertical",
    "show_clear_button": true
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `behavior.allow_clear` | bool | `true` | User can deselect to "no filter" |
| `behavior.default` | string \| `null` | `null` | Pre-selected value |
| `behavior.show_count` | bool | `true` | Show match count per option |
| `ui.layout` | `"vertical"` \| `"horizontal"` | `"vertical"` | Stacking direction |
| `ui.show_clear_button` | bool | `true` | Render an explicit "Clear" affordance |

## URL state

```text
?_hof_shipping_class=express
```

Single value. Empty/absent param = no filter (or `default` if configured).

## planned PHP filters

```php
apply_filters( 'hof_facet_radio_choices', $choices, $facet );
apply_filters( 'hof_facet_radio_default', $default, $facet );
apply_filters( 'hof_facet_radio_label', $label, $value, $facet );
do_action( 'hof_facet_radio_rendered', $facet );
```

## examples

**Result sort order:**

```json
{ "name": "sort", "type": "radio", "source": "virtual:sort",
  "behavior": { "default": "relevance" },
  "ui": { "layout": "horizontal" } }
```

**Single-select pricing tier:**

```json
{ "name": "tier", "type": "radio", "source": "taxonomy:pricing_tier",
  "behavior": { "allow_clear": true, "show_count": true } }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — for multi-select
- [Dropdown](Facet-Type-Dropdown) — same semantic, different chrome
- [Toggle](Facet-Type-Toggle) — for boolean cases
