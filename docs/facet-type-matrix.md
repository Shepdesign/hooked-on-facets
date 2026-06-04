# Facet Type: Matrix

An intersection picker. Stack several values and results narrow to items that
carry *every* one — AND-within-facet, made legible.

## what it is

A list of rows, one per value, each with a toggle, a selected-state dot, and a
per-row count bar sized to that value's share of the largest bucket. Selecting
rows stacks them: the resolver ANDs the selected values together, so an item
must carry **all** of them to survive. Pair it with the active-filters chip
strip so shoppers can see exactly which values are stacked.

Unlike a checkbox facet (which defaults to OR within the facet), the matrix
display defaults to **AND** — that's the whole point of an intersection picker.

## when to use it

- "Show me items that are *both* organic *and* cotton *and* fair-trade"
- Attribute stacking where each added value should *narrow*, not widen, results
- Surfacing how many items each value contributes via the count bars

## when not to use it

- You want OR semantics (match any selected value) → use [Checkbox](Facet-Type-Checkbox)
- Single-select → use [Radio](Facet-Type-Radio)
- Long lists where the count bars add noise → use [Checkbox](Facet-Type-Checkbox)

## configuration

The display slug is `matrix`.

```json
{
  "name": "features",
  "kind": "taxonomy",
  "source": "pa_features",
  "display": "matrix",
  "label": "Stack features"
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `settings.match` | `"any"` \| `"all"` | `"all"` | `all` = AND within the facet (item must carry every selected value); `any` = OR. Matrix defaults to `all` |

The matrix display is the only facet that defaults `settings.match` to `all`.
The resolver applies this default in `Resolver::build_facet_legs()` — when
`match` resolves to `all` it emits one single-value `INTERSECT` leg per selected
value, so the object must match all of them. The `match` setting is read from
the shared settings allowlist in `src/Api/RestController.php`; there are no
other matrix-specific settings.

## interaction model

| Input | Action |
|---|---|
| Toggle a row | Adds/removes that value from the stacked set, then refetches |
| Selected row | Shows a filled dot and a highlighted cell |
| Count bar | Tracks the row's share of the largest bucket count |

Each row is a real checkbox, so the runtime reuses the multi-select path; the
matrix is the UI, the AND semantics live in the resolver.

## URL state

```text
?hof[features][]=organic&hof[features][]=cotton
```

Array form, same wire shape as a checkbox facet. The any/all match mode lives in
the facet config, not the URL — so the same link works regardless of match mode.

## theming

The per-row count bar reads a runtime-set custom property; the rest uses the
shared `--hof-*` tokens:

```css
.hof-matrix-bar {
  /* width tracks the row's share of the largest bucket count */
  width: calc(var(--hof-matrix-weight, 0) * 60px);
}
```

`--hof-matrix-weight` is written per row by the renderer (the value's count
divided by the largest count). Selected cells and the dot pick up
`--hof-primary`.

## see also

- [Checkbox](Facet-Type-Checkbox) — same data shape, OR by default
- [Radio](Facet-Type-Radio) — single-select alternative
