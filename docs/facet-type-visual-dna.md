# Facet Type: Visual DNA

Drop an image, paste an image URL, or eyedrop any color visible on screen — the catalog filters to products in the closest matching color term.

## what it is

A "view" facet that drives an existing color taxonomy or meta facet. Three input modalities collapse to the same pipeline:

```text
image → 96×96 canvas → quantize → dominant hex → LAB ΔE → nearest term → store.set(target, [slug])
```

No new resolver path, no per-product image processing at index time. The match runs entirely on the values that *already* live in the target color facet's index, so it's instant and deterministic.

## when to use it

- Storefronts where shoppers come in with a color in mind ("find me something this color")
- Inspiration-driven shopping — drop a Pinterest image, find catalog matches
- Visual-first product categories: apparel, home goods, art, paint, furniture
- Mobile, where typing the right color name is the wrong UX

## when not to use it

- Stores without color attributes — there's nothing to match against
- Catalogs where "color" isn't the primary visual axis (pattern, texture, shape matter more) — Visual DNA only matches on dominant color
- Catalogs that need true visual similarity (not just nearest color) — that's [Visual DNA v2 → indexed ΔE](Roadmap), still on the roadmap

## configuration

```json
{
  "name": "visual_dna",
  "kind": "view",
  "display": "visual_dna",
  "label": "Visual DNA",
  "settings": {
    "target_facet": "color"
  }
}
```

### options

| Field | Values | What |
|---|---|---|
| `kind` | `"view"` | Required |
| `display` | `"visual_dna"` | Required |
| `settings.target_facet` | facet name | The color-bearing facet to drive. Must have `display` in `{checkbox, radio, dropdown, swatch, swiper}`. |
| `source` | — | Not used |

## the three modalities

| Modality | How |
|---|---|
| **Drop a file** | Drag an image onto the drop zone, or click it to open a file picker. Works for any browser. |
| **Paste a URL** | Type / paste an image URL into the field and press Enter. The image host must send permissive CORS — otherwise the browser blocks the canvas read and the user sees a friendly fallback message. |
| **EyeDropper** | Click the 🎨 Pick button to enter the browser's native EyeDropper, then click any pixel anywhere on screen. **Chromium-only** (Chrome, Edge, Brave, Opera) as of late 2025. The button is feature-detected and hidden where unsupported. |

## color matching: how it works

The frontend extracts one dominant color from the input via canvas sampling:

1. **Downsample** the source image to 96×96 (cheap, GPU-friendly, kills JPEG noise).
2. **Quantize** each pixel to 4 bits per channel → 4096 buckets total.
3. **Pick** the heaviest bucket that isn't near-white or near-black — unless those are a clear majority, in which case keep them.
4. Return the **bucket-averaged RGB** as a hex.

That hex is then compared against the target facet's color terms in **CIE LAB ΔE76** space. The smallest ΔE wins; that term's slug is applied as a filter via the normal store pathway.

### where the color terms come from

The renderer builds the term-color map server-side and inlines it on the wrapper element so the JS has no extra network round-trip:

| Target facet kind | Source of hex per term |
|---|---|
| **Taxonomy** | `term_meta.swatch_color` (same key the Color Swatch facet uses), falling back to a built-in CSS-name table. |
| **Meta / field** | Distinct `facet_value` rows in the index, looked up against the built-in CSS-name table. |

The built-in table covers ~35 common color words (`red`, `navy`, `olive`, `burgundy`, `salmon`, `khaki`, `mint`, etc.) — enough that a vanilla catalog with no per-term color meta still works. Terms that can't be resolved are silently dropped.

## URL state

Visual DNA writes through to the underlying color facet — it has no URL key of its own.

```text
?hof[color]=red
```

Identical wire format to a normal checkbox/radio/dropdown selection. Deep links survive a display swap; the chip in the active-filters bar reads the same.

## v3 — palette matching

Each product indexes a **top-N color palette** (default N=5) at extraction time, not just one dominant color. Queries are matched against the whole palette, so a product with a tiny pop of cyan ranks as a cyan match — even if its dominant color is something else.

| Step | What happens |
|---|---|
| **1. Schema** | `wp_hof_index` carries `lab_l`/`lab_a`/`lab_b` (LAB triplet) and reuses `facet_numeric` to store the bucket weight (fraction of opaque pixels). DB version 1.2.0; no migration since v2 — the columns already exist. |
| **2. Extract** | At index time, `VisualDna\ColorExtractor::extract_palette_from_post()` runs the same 96 px downsample + 4-bit-per-channel quantize + bucket-rank logic as the storefront JS, then keeps up to N buckets whose weight ≥ 5% of opaque pixels. Near-white/black buckets are dropped unless they're all that's left. One `_visual_dna_lab` row is written per palette entry. |
| **3. Query** | The storefront posts to `/wp-json/hof/v1/visual-dna {hex?, hexes?, limit}`. The endpoint converts each query color to LAB and returns top-K products ranked by **`MIN(LEAST(ΔE_q1, ΔE_q2, …))` over the product's palette rows** — i.e. for each product, the smallest distance across the cross product (query palette × product palette). |
| **4. Apply** | The frontend pushes the ranked ID list through the store as `_visual_ids`. The resolver intersects with whatever other facet filters are active, preserving the ΔE order. |
| **5. Fallback** | If no LAB rows are indexed yet, the endpoint reports `indexed_count: 0` and the frontend falls back to v1 (snap to nearest term). |

### Why MIN over the cross product

Two products with the same dominant color but different accents are no longer indistinguishable: when you query "gold," the navy-and-gold product ranks ahead of solid navy. Conversely a shopper who drops a photo with both blue and yellow now matches products containing either color — not just the average of the two.

It's not [Earth Mover's Distance](https://en.wikipedia.org/wiki/Earth_mover%27s_distance), and we're not weighting by palette weight at query time. Both are options if MIN-ΔE turns out to under-rank "the product whose palette mostly matches" against "the product with a single near-match." For an alpha, MIN-ΔE is dead simple and the demo cases rank as expected.

### Verified end-to-end

The `bin/verify-visual-dna.{php,sh}` harness regenerates 20 solid + 5 mixed-color test images, sideloads them, reindexes, and asserts:

- Dominant LAB matches expected (v2 contract preserved): **25/25 ΔE=0.00**
- Every expected palette color is present within ΔE < 2: **25/25**
- Single-hex query returns the matching product first
- **Multi-hex / accent-color query**: a `{gold}` query returns `blue-with-gold-accent` first (ΔE=0.00) ahead of solid yellow (ΔE=17.44) — proving the accent-color path works

## remaining limitations

- **Color only.** Pattern, shape, texture still aren't factors. A striped red-and-blue shirt and a solid violet shirt may land near each other in LAB space.
- **No weighting at query time.** A product with 40% red ranks the same on a red query as a product with 5% red. Weight-aware ranking is a tunable future option.
- **Index time cost.** Each reindex'd product with an image pays ~30–80 ms for the extraction + (palette-size) inserts. On a 100k-product catalog the initial extraction is meaningful — plan for it on import, or batch via cron. Subsequent saves only re-extract the affected product.

## browser support

| Modality | Support |
|---|---|
| Drop file | Universal |
| Paste URL | Universal — but cross-origin images require permissive CORS on the host |
| EyeDropper | Chromium-only (~80% global usage). Gracefully hidden in Firefox/Safari. |

## see also

- [Color Swatch](Facet-Type-Color-Swatch) — the visual-by-attribute alternative (shopper picks from a set of squares, not from arbitrary input)
- [Ask](Facet-Type-Ask) — the conversational alternative when "find me things this color" lives in a longer sentence
- [Architecture](Architecture) — how view facets fit
