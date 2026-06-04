# Migration

How to bring your site over from another filter plugin.

> [!WARNING]
> **Automated migration tools are on the post-1.0 roadmap.** They didn't make the 1.0.0 cut. This page captures what we plan to support; the `wp hof migrate …` commands below don't exist yet. The manual paths (especially "from custom code") work today.

## from FacetWP

Planned scope:

- ✅ Facet definitions (checkbox, radio, dropdown, slider, date, search, hierarchy)
- ✅ Index data (where data shapes overlap)
- ✅ Templates (display templates → HOF display templates)
- ⚠️ Listings (a structured port; manual review may be needed)
- ❌ Custom PHP in facet templates (you'll need to port these yourself; we'll provide a guide)

Planned UX:

1. Install both plugins side by side
2. Run `wp hof migrate facetwp --dry-run`
3. Review the report
4. Run `wp hof migrate facetwp --commit`
5. Verify, then disable FacetWP

## from WP Grid Builder

Planned scope:

- ✅ Facet definitions (with mappings for WPGB-specific types)
- ✅ Grid → HOF listing template (best-effort)
- ⚠️ Card builder layouts → HOF display templates (best-effort, manual review)
- ❌ Pro/extension features (case by case)

## from Search & Filter Pro

Less direct mapping, but we'll provide:

- ✅ A guided mapping wizard in the admin
- ✅ Field-by-field translation
- ❌ No automated cutover (manual recreation suggested)

## from custom code

If you've built filters with `pre_get_posts`, `WP_Query`, or AJAX of your own — congratulations, you're a real one. HOF can replace most of that with config instead of code. The migration is:

1. Catalog every place you call `WP_Query` for filtering
2. Map each to an HOF facet or listing
3. Replace the PHP with the HOF block/shortcode/template tag
4. Delete the old code with prejudice

## need help?

Open an issue with the `migration` label and we'll help scope it.
