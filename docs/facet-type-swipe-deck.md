# Facet Type: Swipe Deck

> 🎴 **Shipped (experimental).**

A stacked deck of swipeable cards — one term per card — that feels native on touch and works with the keyboard on desktop. Built for stores where the *vibe* of a category matters more than ticking ten checkboxes.

## what it is

A finite stack of cards, one card per term, with a primary action (swipe right / arrow right / "yes") and a secondary action (swipe left / arrow left / "no"). The deck commits all "yes" selections to the URL as a single multi-select facet value.

It's not a gimmick — it's a real facet. URL state, AJAX refetch, count badges, the lot.

## when to use it

- Lifestyle filters: "show me the vibe" — colors, moods, styles
- Onboarding a shopper who doesn't know what they want yet
- Mobile-first stores where every vertical pixel matters
- Categories small enough to fan out as cards (under ~30 terms)

## when not to use it

- Long term lists → use [Checkbox](Facet-Type-Checkbox) or [Dropdown](Facet-Type-Dropdown)
- Single-select needed → use [Radio](Facet-Type-Radio)
- Numeric ranges → use [Range Slider](Facet-Type-Range-Slider)
- Visual *only* matters → use [Color Swatch](Facet-Type-Color-Swatch) — swatches are denser

## configuration

The shipping display slug is `swiper`. (The brand name is *swipe deck* — the slug is historical.)

```json
{
  "name": "vibe",
  "kind": "taxonomy",
  "source": "product_cat",
  "display": "swiper",
  "label": "Pick your vibe",
  "settings": {
    "variant": "Swipe",
    "cardSize": "Medium",
    "deckDepth": 4,
    "animation": "Spring"
  }
}
```

### options

| Field | Values | Default | What |
|---|---|---|---|
| `settings.variant` | `"Card"` \| `"Grid"` \| `"Swipe"` | `"Swipe"` | `Swipe` is the deck; `Card`/`Grid` are display fallbacks for non-touch |
| `settings.cardSize` | `"Small"` \| `"Medium"` \| `"Large"` | `"Medium"` | Card footprint |
| `settings.deckDepth` | int 1–10 | `4` | Number of cards visible in the stack behind the top card |
| `settings.animation` | `"Spring"` \| `"Linear"` | `"Spring"` | Swipe physics — Spring overshoots, Linear glides |

## interaction model

| Input | Action |
|---|---|
| Swipe right / `→` / tap ✓ | Include term in filter |
| Swipe left / `←` / tap ✗ | Skip term |
| `↑` / tap ↩ | Undo last decision |
| End of deck | Auto-applies all "yes" selections |

Selections coalesce into a single URL param like a multi-select checkbox facet — the deck is the *UI*, not the storage shape.

## URL state

```text
?_hof_vibe=cozy,bold,minimal
```

Comma-separated term slugs. Identical wire format to checkbox/swatch facets, so deep links survive a display-type change.

## visual customization

The deck respects the site's existing typography and CSS variables:

```css
:root {
  --hof-swipe-card-radius: 14px;
  --hof-swipe-card-bg: var(--hof-surface);
  --hof-swipe-accent: var(--hof-accent);
}
```

Per-term card art uses the same term-meta convention as Color Swatch:

```php
update_term_meta( $term_id, 'swatch_image', $attachment_id );
update_term_meta( $term_id, 'swatch_color', '#D85A30' );
```

When neither is set, the deck falls back to a typographic card with a generated initial glyph.

## planned PHP filters

```php
apply_filters( 'hof_facet_swiper_cards', $cards, $facet );
apply_filters( 'hof_facet_swiper_card_image', $image_url, $term, $facet );
apply_filters( 'hof_facet_swiper_card_label', $label, $term, $facet );
```

## see also

- [Color Swatch](Facet-Type-Color-Swatch) — denser visual alternative
- [Checkbox](Facet-Type-Checkbox) — same data shape, different UI
- [[Brand Voice|Brand-Voice]] — why the brand calls it *swipe deck*, not anything else
