# Facet Type: Saved Bin

A client-side comparison bin. Shoppers pin products into a bin that persists in
the browser, then flip a switch to see only what they saved.

## what it is

Unlike every other facet, the saved bin doesn't filter on an indexed value — it
filters on a set of *object IDs* the shopper collected. Contents live in
`localStorage`, so they survive navigation without a server round-trip. When the
**Show only saved** toggle is on, the bin feeds its IDs to the resolver through
the reserved `_bin_ids` key, which intersects the result set with exactly those
IDs.

The server renders only an empty shell; `bin.js` hydrates the contents from
`localStorage` on load and after every refresh.

## when to use it

- A "compare these" or "shortlist" workflow across a browsing session
- Letting shoppers gather candidates, then narrow the grid to just those
- Wishlist-style collecting that shouldn't require an account

## when not to use it

- You need server-side persistence across devices → the bin is per-browser only
- You're filtering on a product attribute → use [Checkbox](Facet-Type-Checkbox)
  or any value-based facet

## configuration

The display slug is `saved_bin`.

```json
{
  "name": "compare",
  "kind": "view",
  "source": "",
  "display": "saved_bin",
  "label": "Comparison bin"
}
```

### options

The saved bin exposes **no display-specific settings**. (Verified against the
`sanitize_settings` allowlist in `src/Api/RestController.php` — `saved_bin` has
no dedicated branch — and the bin renderer in `src/Facets/Renderer.php` reads no
settings.)

### adding items to the bin

Shoppers add items with any element carrying `data-hof-bin-add` and
`data-hof-bin-id` (plus an optional `data-hof-bin-label`), or by dragging such an
element onto the bin. The `[hof_bin_button]` shortcode emits one — drop it inside
a product-card template:

```text
[hof_bin_button id="123" label="Acme Lamp"]
```

`id` defaults to the current loop post, so `[hof_bin_button]` works bare inside a
loop; `label` defaults to the post title. The shortcode also accepts `add` and
`added` for button-text overrides. (Source: `shortcode_bin_button` in
`src/Frontend/Shortcodes.php`.)

## interaction model

| Input | Action |
|---|---|
| Click a `data-hof-bin-add` element | Toggles that item in/out of the bin |
| Drag an add element onto the bin | Adds the item |
| Click an item's remove (×) | Removes that item |
| **Show only saved** toggle | Restricts results to the saved IDs (or clears the restriction when off) |
| **Clear bin** | Empties the bin |

Bins on a page share one `localStorage` key, so adding an item anywhere updates
every bin. The storage key is per-site (`hof_bin_` plus the table prefix), so two
sites on one browser origin don't share a bin.

## URL state

The bin's contents are not URL state — they live in `localStorage`. The
restriction it applies is the reserved `_bin_ids` resolver key, a plain ID
intersection (not an index value). When driven from URL state it accepts a CSV
form:

```text
?hof[_bin_ids]=12,34,56
```

(Source: `Resolver::sanitize_filter_state()` in `src/Filter/Resolver.php`.) In
normal use, `bin.js` sets `_bin_ids` on the store directly rather than via the
URL.

## theming

The bin uses the shared `--hof-*` tokens. A couple of state hooks themes can
target:

```css
.hof-facet-bin[data-hof-bin-over="1"] { /* drag-over state on the dropzone */ }
.hof-bin-add[data-hof-bin-in="1"]     { /* an add button whose item is in the bin */ }
```

## see also

- [Checkbox](Facet-Type-Checkbox) — value-based filtering
- [Visual DNA](Facet-Type-Visual-DNA) — the other reserved-key facet (`_visual_ids`)
