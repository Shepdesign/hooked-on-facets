# Facet Types

Hooked on Facets ships **16 facet types**, all server-rendered from
`src/Facets/Renderer.php` with matching runtime in `public/src/`. Each is chosen
per-facet in the builder. The `active_filters` chip strip rides the same renderer
as a display helper (not counted among the 16).

| Type | Slug | Best for |
|---|---|---|
| Checkbox | `checkbox` | Multi-select sets (brands, tags). The default. |
| Radio | `radio` | Single-select from a small list. |
| Dropdown | `dropdown` | Single-select where space is tight. |
| Toggle | `toggle` | A single on/off boolean (e.g. "in stock"). |
| Hierarchy | `hierarchy` | Nested taxonomies (categories with children). |
| Range slider | `range` | Numeric ranges served from `facet_numeric`. |
| Date range | `date_range` | Calendar date ranges. |
| Search box | `search` | Free-text narrowing within a facet's values. |
| Fluid swatches | `swatch` | Color/image tiles that morph size by bucket count. |
| Swipe deck | `swiper` | Touch-first card swiping through values. |
| Spin the wheel | `spin_the_wheel` | Gamified single-select over an accessible radiogroup. |
| Intersection matrix | `matrix` | Venn/UpSet-style AND-within-facet intersections. |
| Saved bin | `saved_bin` | A drag-and-drop comparison bin (filters by saved IDs). |
| AI ask | `ask` | Natural-language → filter chips (requires an Anthropic API key). |
| Visual DNA | `visual_dna` | Color-extraction-driven visual filtering. |
| Pagination | `pagination` | Numbered result pagination. |

## Matching semantics

Most multi-value facets default to **OR within the facet** (any selected value
matches) and **AND across facets** (every facet must match). Multi-value facets
expose an **any / all** control; `all` switches to **AND within the facet**
(`settings.match = 'all'`), which the `matrix` type uses by default.

## Notes on specific types

- **AI ask** calls the Anthropic API with your own key — see
  [Getting Started](getting-started.md) and the AI Settings screen. Every other
  type works with no external service.
- **Saved bin** and **Visual DNA** filter by object ID (the reserved `_bin_ids`
  and `_visual_ids` resolver keys) rather than an index value.
- **Range** and **date_range** read the pre-cast `facet_numeric` column, so no
  string-to-number casting happens at query time.

## Theming

Every visible value on every facet is driven by a `--hof-*` CSS custom property.
Override via raw CSS scoped to `.hof-facet`, or via the `hof_public_css_tokens`
PHP filter. The **Tokens** admin screen provides a full visual editor.
