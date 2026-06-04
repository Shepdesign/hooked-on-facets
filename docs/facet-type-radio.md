# Facet Type: Radio

Single-select from a small list. The "pick exactly one" facet.

## what it is

A list of mutually exclusive options. Users pick one, or clear back to "no filter". Useful when the data shape demands exclusivity.

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
  "kind": "meta",
  "source": "_shipping_class",
  "display": "radio",
  "label": "Shipping"
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `kind` | `"taxonomy"` \| `"meta"` \| `"field"` | — | Where the indexed values come from |
| `source` | string | — | The taxonomy slug or meta/field key |

The radio facet renders one option per indexed value plus a "clear" affordance, with match counts shown next to each option. It has no display-specific `settings` in 1.0.0.

## URL state

```text
?hof[shipping_class]=express
```

Single value. Empty/absent param = no filter.

## examples

**Single-select pricing tier:**

```json
{ "name": "tier", "kind": "taxonomy", "source": "pricing_tier", "display": "radio" }
```

**Meta-sourced shipping class:**

```json
{ "name": "shipping_class", "kind": "meta", "source": "_shipping_class", "display": "radio" }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — for multi-select
- [Dropdown](Facet-Type-Dropdown) — same semantic, different chrome
- [Toggle](Facet-Type-Toggle) — for boolean cases
