# Brand Assets

Single source of truth for the Hooked on Facets visual identity. Everything here is referenced from `../BRAND.md` at the project root — start there for the full spec.

## Files

| File | What it is |
|------|------------|
| `logo.svg` | Full lockup: faceted hexagon mark + "hooked on facets" wordmark. Use in marketing contexts. |
| `logo-mark.svg` | Mark only — the faceted hexagon. Use for favicons, sidebars, app tiles, any context smaller than ~120px wide. |
| `colors.css` | CSS variables for the entire palette. Import once at the root of any new page or component. |
| `typography.css` | Geist Sans/Mono font loader + type scale utility classes. Import after `colors.css`. |

## Usage

```html
<!-- In your root layout / template -->
<link rel="stylesheet" href="/brand/colors.css">
<link rel="stylesheet" href="/brand/typography.css">

<!-- Logo inline -->
<img src="/brand/logo.svg" alt="Hooked on Facets" width="200" height="45">
```

```css
/* In your component CSS */
.my-button {
  background: var(--hof-color-hook-purple);
  color: var(--hof-color-purple-50);
  border-radius: var(--hof-radius-md);
  font-family: var(--hof-font-sans);
}
```

## Don'ts

- ❌ Don't duplicate hex values inline — always reference the CSS variables.
- ❌ Don't load Geist from anywhere except the Google Fonts URL in `typography.css`.
- ❌ Don't modify the logo SVGs to recolor or restyle. If you need a variant, ask first.
- ❌ Don't make new color tokens here without a real use case and a name that fits the convention.
